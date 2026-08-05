<?php

declare(strict_types=1);

namespace App\Crypto;

use RuntimeException;
use SensitiveParameter;

/**
 * Field-level encryption and blind indexing (docs/security/ENCRYPTION.md).
 *
 * Two operations on one plaintext, answering two different questions:
 *
 *  - {@see encrypt()} makes a value unreadable to anyone holding the row. **XChaCha20-Poly1305**, an
 *    AEAD construction with a 24-byte random nonce — wide enough that random nonces never realistically
 *    repeat, which is why the spec chose it over AES-GCM's 12 bytes. The authentication tag matters as
 *    much as the confidentiality: a ciphertext that has been altered, truncated, or moved does not
 *    decrypt to something wrong, it refuses to decrypt at all.
 *  - {@see blindIndex()} makes a value *findable* without making it readable — a keyed HMAC-SHA-256 of
 *    the normalized plaintext, under a key that is deliberately NOT the encryption key.
 *
 * **The AAD is a tenant-isolation control, not a formality.** Every ciphertext is bound to
 * `company_id : table : column : row_id`, so a value lifted out of one row — or one company — and pasted
 * into another fails authentication. Field encryption therefore defends the tenant boundary a third
 * time, after RLS and after the request-scoped guards.
 *
 * Everything here fails closed. A missing key, a wrong key, a tampered ciphertext, a value carried to
 * another row: all raise. ENCRYPTION.md is explicit that QAYD never silently returns ciphertext, a null,
 * or a placeholder when decryption fails, because each of those turns a security event into a data bug
 * discovered much later by someone reading a report.
 */
final class FieldCipher
{
    /** Envelope prefix + version. Bumped only if the on-disk format changes, never for key rotation. */
    private const ENVELOPE = 'qf1';

    /** Normalization for blind indexes: strip every non-alphanumeric, uppercase the rest. */
    public static function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }

    /**
     * A keyed, deterministic digest for equality lookup on an encrypted column.
     *
     * Deterministic is both the requirement and the danger: the same plaintext always produces the same
     * digest, which is exactly what makes `WHERE iban_bidx = ?` work and exactly what would let someone
     * holding the index confirm a guess. The key is what stops that, so it is a *separate* secret from
     * the field key — leaking one half of the pair reveals nothing about the other.
     *
     * Normalization runs first, so `KW81 CBKU 0000` and `kw81cbku0000` are the same account.
     */
    public static function blindIndex(#[SensitiveParameter] string $value): string
    {
        return hash_hmac('sha256', self::normalize($value), self::indexKey());
    }

    /**
     * Encrypt $plaintext, bound to $aad.
     *
     * The stored form is `qf1.<base64 nonce>.<base64 ciphertext>`. The nonce is public by design — it
     * has to be, to decrypt — and is random per value, which is why two rows holding the same IBAN look
     * nothing alike on disk. That property is precisely why a UNIQUE constraint over this column would
     * never fire, and why equality lives on the blind index instead.
     */
    public static function encrypt(#[SensitiveParameter] string $plaintext, string $aad): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $aad,
            $nonce,
            self::fieldKey(),
        );

        return implode('.', [self::ENVELOPE, base64_encode($nonce), base64_encode($ciphertext)]);
    }

    /**
     * Decrypt $envelope, requiring it to have been sealed under exactly $aad.
     *
     * Retired keys are tried after the active one, so a rotation window works without pausing writes.
     * Order matters only for speed: a value under the active key never touches the retired list.
     *
     * @throws RuntimeException on a malformed envelope, a wrong key, a tampered ciphertext, or an AAD
     *                          that does not match the one it was sealed with — all indistinguishable to
     *                          the caller on purpose, because which one it was is not the caller's
     *                          business and the answer would help an attacker.
     */
    public static function decrypt(string $envelope, string $aad): string
    {
        $parts = explode('.', $envelope, 3);

        if (count($parts) !== 3 || $parts[0] !== self::ENVELOPE) {
            throw new RuntimeException('Encrypted field is malformed.');
        }

        $nonce = base64_decode($parts[1], true);
        $ciphertext = base64_decode($parts[2], true);

        if ($nonce === false || $ciphertext === false) {
            throw new RuntimeException('Encrypted field is malformed.');
        }

        foreach ([self::fieldKey(), ...self::retiredFieldKeys()] as $key) {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $ciphertext,
                $aad,
                $nonce,
                $key,
            );

            if ($plaintext !== false) {
                return $plaintext;
            }
        }

        throw new RuntimeException('Encrypted field failed authentication and was not decrypted.');
    }

    /** The AAD for one column of one row: what this ciphertext is allowed to belong to. */
    public static function aad(int|string|null $companyId, string $table, string $column, int|string $rowId): string
    {
        return implode(':', [(string) $companyId, $table, $column, (string) $rowId]);
    }

    private static function fieldKey(): string
    {
        return self::key('encryption.field_key', 'FIELD_ENCRYPTION_KEY');
    }

    private static function indexKey(): string
    {
        return self::key('encryption.index_key', 'FIELD_INDEX_KEY');
    }

    /**
     * @return list<string>
     */
    private static function retiredFieldKeys(): array
    {
        $configured = config('encryption.retired_field_keys', []);
        $keys = [];

        if (is_array($configured)) {
            foreach ($configured as $value) {
                if (is_string($value) && $value !== '') {
                    $keys[] = self::decodeKey($value, 'FIELD_ENCRYPTION_RETIRED_KEYS');
                }
            }
        }

        return $keys;
    }

    private static function key(string $configKey, string $envName): string
    {
        $value = config($configKey);

        if (! is_string($value) || $value === '') {
            // Refusing to run beats inventing a key: one generated on the fly would encrypt today's rows
            // into something no later process could ever read.
            throw new RuntimeException(sprintf('%s is not configured; refusing to handle Restricted data.', $envName));
        }

        return self::decodeKey($value, $envName);
    }

    private static function decodeKey(string $value, string $envName): string
    {
        $raw = base64_decode($value, true);

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException(sprintf('%s must be 32 raw bytes, base64-encoded.', $envName));
        }

        return $raw;
    }
}
