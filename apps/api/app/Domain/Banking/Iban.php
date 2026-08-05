<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Crypto\FieldCipher;
use InvalidArgumentException;
use Stringable;

/**
 * An IBAN (ISO 13616), validated by the ISO 7064 MOD-97-10 check (SPRINT_03 §S3-01).
 *
 * This class exists because the database cannot do this job. `bank_accounts.iban` is encrypted, and a
 * `CHECK` constraint cannot pattern-match ciphertext — so the format guarantee that would otherwise live
 * in the schema lives here instead, at the only layer that ever sees the plaintext. That is a relocation
 * of the check, not a weakening of it: nothing constructs an `Iban` without passing mod-97.
 *
 * **The plaintext is deliberately awkward to reach.** `__toString()` and `__debugInfo()` both return the
 * masked form, so an IBAN that finds its way into a log line, an exception message, a `dd()`, or a
 * stack-trace argument dump shows `KW81••••••••3456` rather than an account number. Reading the real
 * value takes an explicit {@see toPlaintext()} call, which is greppable in review — the safe thing is
 * what happens by default, and the unsafe thing has to be asked for by name.
 */
final class Iban implements Stringable
{
    /** ISO 13616 permits 15–34 overall; these are the exact lengths for the markets QAYD serves first. */
    private const COUNTRY_LENGTHS = [
        'KW' => 30, 'SA' => 24, 'AE' => 23, 'BH' => 22, 'QA' => 29, 'OM' => 23,
        'JO' => 30, 'EG' => 29, 'LB' => 28, 'GB' => 22, 'DE' => 22, 'FR' => 27,
    ];

    private function __construct(private readonly string $normalized) {}

    /**
     * @throws InvalidArgumentException when the value is not a structurally valid IBAN
     */
    public static function fromString(string $value): self
    {
        $normalized = FieldCipher::normalize($value);

        if (! self::isStructurallyValid($normalized)) {
            // The message never quotes the input: an invalid IBAN is still somebody's account number,
            // and this string ends up in logs and API error bodies.
            throw new InvalidArgumentException('The IBAN is not valid.');
        }

        return new self($normalized);
    }

    /** The same check without the exception, for callers scoring rather than validating. */
    public static function tryFrom(string $value): ?self
    {
        $normalized = FieldCipher::normalize($value);

        return self::isStructurallyValid($normalized) ? new self($normalized) : null;
    }

    public static function isValid(string $value): bool
    {
        return self::isStructurallyValid(FieldCipher::normalize($value));
    }

    /** The real value. Named so that every place plaintext escapes is one grep away. */
    public function toPlaintext(): string
    {
        return $this->normalized;
    }

    /** The keyed digest this IBAN is found by, since the ciphertext cannot be searched. */
    public function blindIndex(): string
    {
        return FieldCipher::blindIndex($this->normalized);
    }

    /** Country + check digits, then the tail — enough to recognise an account, not enough to use one. */
    public function masked(): string
    {
        $length = strlen($this->normalized);

        return substr($this->normalized, 0, 4)
            .str_repeat('•', max(0, $length - 8))
            .substr($this->normalized, -4);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->normalized, $other->normalized);
    }

    public function __toString(): string
    {
        return $this->masked();
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['iban' => $this->masked()];
    }

    private static function isStructurallyValid(string $normalized): bool
    {
        $length = strlen($normalized);

        if ($length < 15 || $length > 34) {
            return false;
        }

        // Two letters of country, two digits of checksum, then a country-defined body.
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $normalized) !== 1) {
            return false;
        }

        $expected = self::COUNTRY_LENGTHS[substr($normalized, 0, 2)] ?? null;

        if ($expected !== null && $length !== $expected) {
            return false;
        }

        return self::mod97($normalized) === 1;
    }

    /**
     * ISO 7064 MOD-97-10.
     *
     * The first four characters move to the end, each letter becomes its position + 9 (A→10 … Z→35), and
     * the resulting number must leave remainder 1 modulo 97. That number is far wider than a 64-bit int
     * for a 34-character IBAN, so the remainder is carried piecewise across the digits — the standard
     * approach, and the reason this needs no bignum extension.
     */
    private static function mod97(string $normalized): int
    {
        $rearranged = substr($normalized, 4).substr($normalized, 0, 4);
        $remainder = 0;

        foreach (str_split($rearranged) as $character) {
            $chunk = ctype_digit($character)
                ? $character
                : (string) (ord($character) - ord('A') + 10);

            foreach (str_split($chunk) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder;
    }
}
