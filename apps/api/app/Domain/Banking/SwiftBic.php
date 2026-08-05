<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Crypto\FieldCipher;
use InvalidArgumentException;
use Stringable;

/**
 * A SWIFT/BIC code (ISO 9362), validated structurally (SPRINT_03 §S3-01).
 *
 * Here for the same reason as {@see Iban}: `bank_accounts.swift_bic` is encrypted, so the regex `CHECK`
 * the schema would otherwise carry cannot run against the stored value. The rule moves to the only layer
 * that sees plaintext.
 *
 * ISO 9362 has no checksum — unlike an IBAN, a BIC that is one character wrong is still structurally
 * valid, and only the network can say whether it addresses a real institution. So this validates shape
 * and nothing more, and does not pretend to more certainty than the standard offers.
 *
 * Eight characters addresses an institution's primary office; eleven addresses a specific branch. Both
 * are legal, and a stored eight is not an incomplete eleven.
 */
final class SwiftBic implements Stringable
{
    private function __construct(private readonly string $normalized) {}

    /**
     * @throws InvalidArgumentException when the value is not a structurally valid BIC
     */
    public static function fromString(string $value): self
    {
        $normalized = FieldCipher::normalize($value);

        if (! self::isStructurallyValid($normalized)) {
            throw new InvalidArgumentException('The SWIFT/BIC code is not valid.');
        }

        return new self($normalized);
    }

    public static function isValid(string $value): bool
    {
        return self::isStructurallyValid(FieldCipher::normalize($value));
    }

    /** The real value — explicit, so plaintext reads are greppable. */
    public function toPlaintext(): string
    {
        return $this->normalized;
    }

    /** The institution and country are not secret; the location and branch are the identifying part. */
    public function masked(): string
    {
        return substr($this->normalized, 0, 6).str_repeat('•', strlen($this->normalized) - 6);
    }

    /** True when this BIC addresses a specific branch rather than the institution's primary office. */
    public function isBranchCode(): bool
    {
        return strlen($this->normalized) === 11;
    }

    public function countryCode(): string
    {
        return substr($this->normalized, 4, 2);
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
        return ['swift_bic' => $this->masked()];
    }

    private static function isStructurallyValid(string $normalized): bool
    {
        // 4 letters institution · 2 letters country · 2 alphanumeric location · optional 3 alphanumeric branch.
        return preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $normalized) === 1;
    }
}
