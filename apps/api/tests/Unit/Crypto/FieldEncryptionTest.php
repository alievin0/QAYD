<?php

declare(strict_types=1);

use App\Casts\EncryptedField;
use App\Crypto\FieldCipher;
use App\Domain\Banking\Iban;
use App\Domain\Banking\SwiftBic;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

/**
 * The field-encryption foundation (SPRINT_03 Phase 0, docs/security/ENCRYPTION.md).
 *
 * The properties worth proving are the ones the rest of the sprint leans on. That the same plaintext
 * encrypts differently every time — because that is exactly why a UNIQUE constraint over an encrypted
 * column silently never fires, and why equality had to move to a blind index. That the AAD binding makes
 * field encryption a tenant control and not only a confidentiality one: a ciphertext carried into
 * another company's row does not decrypt to the wrong answer, it refuses. And that plaintext does not
 * leak into the places nobody audits — `__toString`, `__debugInfo`, exception messages.
 */
// These touch no database, but they do read `config()`, and Pest binds the Laravel TestCase only
// `->in('Feature')`. Binding it here gives this file a container without booting a database anywhere.
uses(TestCase::class);

final class EncryptedFieldFixture extends Model
{
    protected $table = 'encrypted_field_fixture';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['iban' => EncryptedField::class];
    }
}

function efKey(): string
{
    return base64_encode(str_repeat("\x2b", 32));
}

function efRow(int $id = 1, int $companyId = 7): EncryptedFieldFixture
{
    $model = new EncryptedFieldFixture;
    $model->forceFill(['id' => $id, 'company_id' => $companyId]);
    $model->exists = true;

    return $model;
}

beforeEach(function (): void {
    config()->set('encryption.field_key', efKey());
    config()->set('encryption.index_key', base64_encode(str_repeat("\x7f", 32)));
    config()->set('encryption.retired_field_keys', []);
});

describe('FieldCipher', function (): void {
    it('round-trips a value under its own AAD', function (): void {
        $aad = FieldCipher::aad(7, 'bank_accounts', 'iban', 42);
        $sealed = FieldCipher::encrypt('KW81CBKU0000000000001234560101', $aad);

        expect(FieldCipher::decrypt($sealed, $aad))->toBe('KW81CBKU0000000000001234560101');
    });

    it('produces a different ciphertext every time for the same plaintext', function (): void {
        $aad = FieldCipher::aad(7, 'bank_accounts', 'iban', 42);

        // The property that makes UNIQUE over ciphertext useless — asserted so nobody re-adds one.
        expect(FieldCipher::encrypt('SAME', $aad))->not->toBe(FieldCipher::encrypt('SAME', $aad));
    });

    it('refuses a ciphertext carried into another company', function (): void {
        $sealed = FieldCipher::encrypt('KW81CBKU0000000000001234560101',
            FieldCipher::aad(7, 'bank_accounts', 'iban', 42));

        expect(fn () => FieldCipher::decrypt($sealed, FieldCipher::aad(8, 'bank_accounts', 'iban', 42)))
            ->toThrow(RuntimeException::class);
    });

    it('refuses a ciphertext carried into another row of the same company', function (): void {
        $sealed = FieldCipher::encrypt('X', FieldCipher::aad(7, 'bank_accounts', 'iban', 42));

        expect(fn () => FieldCipher::decrypt($sealed, FieldCipher::aad(7, 'bank_accounts', 'iban', 43)))
            ->toThrow(RuntimeException::class);
    });

    it('refuses a ciphertext carried into another column', function (): void {
        $sealed = FieldCipher::encrypt('X', FieldCipher::aad(7, 'bank_accounts', 'iban', 42));

        expect(fn () => FieldCipher::decrypt($sealed, FieldCipher::aad(7, 'bank_accounts', 'account_number', 42)))
            ->toThrow(RuntimeException::class);
    });

    it('refuses a tampered ciphertext rather than returning something plausible', function (): void {
        $aad = FieldCipher::aad(7, 'bank_accounts', 'iban', 42);
        $sealed = FieldCipher::encrypt('KW81CBKU0000000000001234560101', $aad);

        expect(fn () => FieldCipher::decrypt(substr($sealed, 0, -4).'AAAA', $aad))
            ->toThrow(RuntimeException::class);
    });

    it('refuses a malformed envelope', function (): void {
        expect(fn () => FieldCipher::decrypt('not-an-envelope', 'aad'))->toThrow(RuntimeException::class);
    });

    it('refuses to operate at all without a key', function (): void {
        config()->set('encryption.field_key', null);

        // Inventing a key would encrypt today's rows into something no later process could read.
        expect(fn () => FieldCipher::encrypt('X', 'aad'))->toThrow(RuntimeException::class);
    });

    it('still decrypts under a retired key during rotation', function (): void {
        $aad = FieldCipher::aad(7, 'bank_accounts', 'iban', 42);
        $sealed = FieldCipher::encrypt('OLD VALUE', $aad);

        config()->set('encryption.field_key', base64_encode(str_repeat("\x11", 32)));
        config()->set('encryption.retired_field_keys', [efKey()]);

        expect(FieldCipher::decrypt($sealed, $aad))->toBe('OLD VALUE');
    });
});

describe('blind index', function (): void {
    it('is deterministic, so equality lookup works', function (): void {
        expect(FieldCipher::blindIndex('KW81CBKU0000000000001234560101'))
            ->toBe(FieldCipher::blindIndex('KW81CBKU0000000000001234560101'));
    });

    it('ignores spacing and case, so one account has one index', function (): void {
        expect(FieldCipher::blindIndex('kw81 cbku 0000 0000 0000 1234 5601 01'))
            ->toBe(FieldCipher::blindIndex('KW81CBKU0000000000001234560101'));
    });

    it('separates different values', function (): void {
        expect(FieldCipher::blindIndex('A'))->not->toBe(FieldCipher::blindIndex('B'));
    });

    it('changes entirely under a different index key, so leaking one index reveals nothing', function (): void {
        $under = FieldCipher::blindIndex('KW81CBKU0000000000001234560101');
        config()->set('encryption.index_key', base64_encode(str_repeat("\x01", 32)));

        expect(FieldCipher::blindIndex('KW81CBKU0000000000001234560101'))->not->toBe($under);
    });
});

describe('the EncryptedField cast', function (): void {
    it('encrypts on write and decrypts on read', function (): void {
        $row = efRow();
        $row->iban = 'GB82WEST12345698765432';

        expect($row->getAttributes()['iban'])->toStartWith('qf1.')
            ->and($row->getAttributes()['iban'])->not->toContain('WEST')
            ->and($row->iban)->toBe('GB82WEST12345698765432');
    });

    it('refuses to encrypt before the row exists, rather than sealing to an id that will change', function (): void {
        $unsaved = new EncryptedFieldFixture;
        $unsaved->forceFill(['company_id' => 7]);

        // ENCRYPTION.md's example defaults the id to 'new' here, which seals a value that can never be
        // read back. Failing loudly at the write beats an unreadable IBAN found weeks later.
        expect(fn () => $unsaved->iban = 'GB82WEST12345698765432')->toThrow(LogicException::class);
    });

    it('cannot read a value that belongs to another row', function (): void {
        $mine = efRow(id: 42);
        $mine->iban = 'GB82WEST12345698765432';
        $stolen = $mine->getAttributes()['iban'];

        // `setRawAttributes` is how Eloquent hydrates a row read from the database, so this plants the
        // stolen ciphertext exactly as a compromised or mis-copied row would arrive. Going through
        // `forceFill` would run the cast and quietly re-encrypt it under the new row's AAD, proving
        // nothing.
        $theirs = new EncryptedFieldFixture;
        $theirs->setRawAttributes(['id' => 43, 'company_id' => 7, 'iban' => $stolen], true);
        $theirs->exists = true;

        expect(fn () => $theirs->iban)->toThrow(RuntimeException::class);
    });

    it('refuses a value object, because its string form is the mask', function (): void {
        $row = efRow();

        // Assigning the Iban directly would encrypt "GB82••••••••••••••5432" — a masked string, stored
        // forever, decrypting to nothing useful. The masking that protects logs becomes a data-loss
        // trap the moment an implicit conversion is allowed, so it is refused outright.
        expect(fn () => $row->iban = Iban::fromString('GB82WEST12345698765432'))
            ->toThrow(LogicException::class);
    });

    it('passes null through untouched', function (): void {
        $row = efRow();
        $row->iban = null;

        expect($row->getAttributes()['iban'])->toBeNull()->and($row->iban)->toBeNull();
    });
});

describe('Iban', function (): void {
    it('accepts a valid IBAN and normalizes it', function (): void {
        expect(Iban::fromString('gb82 west 1234 5698 7654 32')->toPlaintext())
            ->toBe('GB82WEST12345698765432');
    });

    it('rejects a value that fails the mod-97 check', function (): void {
        // One digit changed from the valid example: structurally fine, arithmetically wrong.
        expect(Iban::isValid('GB82WEST12345698765433'))->toBeFalse();
    });

    it('rejects a country-length mismatch', function (): void {
        expect(Iban::isValid('KW81CBKU000000000000123456'))->toBeFalse();
    });

    it('rejects something that is not shaped like an IBAN at all', function (): void {
        expect(Iban::isValid('hello'))->toBeFalse()
            ->and(Iban::isValid(''))->toBeFalse();
    });

    it('never puts the account number in the exception message', function (): void {
        try {
            Iban::fromString('GB82WEST12345698765433');
            $failed = false;
        } catch (InvalidArgumentException $e) {
            $failed = ! str_contains($e->getMessage(), '765433');
        }

        expect($failed)->toBeTrue();
    });

    it('masks itself in string and debug contexts, so logs never carry plaintext', function (): void {
        $iban = Iban::fromString('GB82WEST12345698765432');

        expect((string) $iban)->not->toContain('WEST')
            ->and($iban->__debugInfo()['iban'])->not->toContain('WEST')
            ->and(print_r($iban, true))->not->toContain('WEST');
    });

    it('exposes plaintext only through an explicitly named call', function (): void {
        expect(Iban::fromString('GB82WEST12345698765432')->toPlaintext())->toContain('WEST');
    });

    it('indexes by the same digest FieldCipher would produce', function (): void {
        expect(Iban::fromString('GB82WEST12345698765432')->blindIndex())
            ->toBe(FieldCipher::blindIndex('gb82 west 1234 5698 7654 32'));
    });

    it('compares two spellings of one account as equal', function (): void {
        expect(Iban::fromString('gb82west12345698765432')
            ->equals(Iban::fromString('GB82 WEST 1234 5698 7654 32')))->toBeTrue();
    });
});

describe('SwiftBic', function (): void {
    it('accepts both the 8-character institution form and the 11-character branch form', function (): void {
        expect(SwiftBic::isValid('CBKUKWKW'))->toBeTrue()
            ->and(SwiftBic::isValid('CBKUKWKWXXX'))->toBeTrue();
    });

    it('rejects lengths the standard does not define', function (): void {
        expect(SwiftBic::isValid('CBKUKWK'))->toBeFalse()
            ->and(SwiftBic::isValid('CBKUKWKWXX'))->toBeFalse();
    });

    it('rejects digits where the standard requires letters', function (): void {
        expect(SwiftBic::isValid('CBK1KWKW'))->toBeFalse();
    });

    it('reads its country code and knows a branch code from an institution code', function (): void {
        expect(SwiftBic::fromString('cbkukwkw')->countryCode())->toBe('KW')
            ->and(SwiftBic::fromString('CBKUKWKW')->isBranchCode())->toBeFalse()
            ->and(SwiftBic::fromString('CBKUKWKWXXX')->isBranchCode())->toBeTrue();
    });

    it('masks itself in string and debug contexts', function (): void {
        expect((string) SwiftBic::fromString('CBKUKWKWXXX'))->toBe('CBKUKW•••••')
            ->and(print_r(SwiftBic::fromString('CBKUKWKWXXX'), true))->not->toContain('KWXXX');
    });
});
