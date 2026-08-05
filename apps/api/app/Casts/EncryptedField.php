<?php

declare(strict_types=1);

namespace App\Casts;

use App\Crypto\FieldCipher;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Encrypts a Restricted column on write and decrypts it on read (docs/security/ENCRYPTION.md).
 *
 * Declaring a column encrypted is one line in a model's `casts()`; everything below is what that line
 * buys. The value never exists in plaintext in the database, and the ciphertext is bound by AAD to the
 * exact company, table, column and row it belongs to — so moving it anywhere else makes it undecryptable
 * rather than merely misplaced.
 *
 * **Why this refuses to encrypt a row that has no key yet.** The AAD includes the row id, and a model
 * being inserted does not have one — so a value encrypted before INSERT would seal under `…:new` and
 * then fail to open under `…:417` on the very next read. That failure surfaces far from its cause, as an
 * unreadable IBAN discovered weeks later, which is the worst shape a data bug can take. The documented
 * example in ENCRYPTION.md falls into exactly this trap by defaulting the id to `'new'`; this
 * implementation throws instead. Set encrypted attributes AFTER the row exists — the same two-step
 * insert the journal-number allocator already uses — and the mistake becomes a loud exception at the
 * moment it is made.
 *
 * The `set` type parameter is `mixed`, not `string|null`, and deliberately so: Eloquent hands this cast
 * whatever the caller assigned, and the whole point of {@see set()} is to refuse the things that are not
 * plaintext. Declaring the narrower type would make the analyser certify a guarantee the runtime does
 * not have.
 *
 * @implements CastsAttributes<string|null, mixed>
 */
final class EncryptedField implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            // The column holds an envelope string or nothing. Anything else means the row was written
            // around this cast, which is a schema violation rather than a value to coerce.
            throw new LogicException(sprintf('%s.%s does not hold an encrypted value.', $model->getTable(), $key));
        }

        // Fails closed: a wrong key, a tampered value, or one carried in from another row all raise
        // rather than returning something plausible.
        return FieldCipher::decrypt($value, $this->aad($model, $key));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        // A value object (Iban, SwiftBic) is accepted, but only through its own string form — which is
        // MASKED by design. Callers must hand over the plaintext explicitly, via `toPlaintext()`, so
        // that "what got encrypted" is never decided by an implicit conversion.
        if (! is_string($value)) {
            throw new LogicException(sprintf(
                'Assign %s.%s a plaintext string. A value object must be unwrapped with toPlaintext() '
                .'first, since its string form is masked and would encrypt the mask.',
                $model->getTable(),
                $key,
            ));
        }

        return [$key => FieldCipher::encrypt($value, $this->aad($model, $key))];
    }

    /**
     * What this ciphertext is allowed to belong to.
     *
     * `company_id` is read straight off the model because every table carrying Restricted data is a
     * tenant table. A null there would silently widen the binding, so it is passed through as-is and
     * left to fail at decrypt time rather than being defaulted to something agreeable.
     */
    private function aad(Model $model, string $key): string
    {
        $rowId = $model->getKey();

        if ($rowId === null) {
            throw new LogicException(sprintf(
                'Cannot encrypt %s.%s before the row exists: the AAD binds the ciphertext to its row id, '
                .'so a value sealed now could never be decrypted after INSERT. Save the row first, then '
                .'set the encrypted attribute.',
                $model->getTable(),
                $key,
            ));
        }

        $companyId = $model->getAttribute('company_id');

        return FieldCipher::aad(
            is_int($companyId) || is_string($companyId) ? $companyId : null,
            $model->getTable(),
            $key,
            is_int($rowId) || is_string($rowId) ? $rowId : '',
        );
    }
}
