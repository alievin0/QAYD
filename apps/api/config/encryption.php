<?php

declare(strict_types=1);

/**
 * Field-level encryption keys (docs/security/ENCRYPTION.md, SPRINT_03 Phase 0).
 *
 * Two keys, and they are deliberately different. The **field key** encrypts Restricted column values
 * (XChaCha20-Poly1305, AEAD). The **index key** derives blind indexes — keyed HMAC-SHA-256 digests that
 * make equality lookup possible on a column whose ciphertext is randomly nonced. Keeping them separate
 * is the entire point of the pair: leaking the index reveals no plaintext, and leaking the ciphertext
 * reveals no index, so neither compromise hands an attacker the other half.
 *
 * Both are read from the environment, never from the database, so a stolen dump is ciphertext to whoever
 * holds it (ENCRYPTION.md: "keys never live beside ciphertext"). A KMS/envelope hierarchy with per-tenant
 * KEKs is what the spec ultimately calls for; this is the config-sourced first step, and TD-33 records
 * the gap so it is not mistaken for the finished article.
 *
 * Keys are 32 raw bytes, base64-encoded in the environment. Generate with:
 *   php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
 */
return [

    /*
     * Encrypts Restricted field values. Rotating it means re-encrypting every encrypted column, so
     * ciphertexts carry the key version that wrote them and a retired key can still decrypt during a
     * rotation window.
     */
    'field_key' => env('FIELD_ENCRYPTION_KEY'),

    /*
     * Derives blind indexes. Rotating it means recomputing every `*_bidx` column — cheaper than
     * re-encrypting, because the plaintext is recoverable from the ciphertext to do it with.
     */
    'index_key' => env('FIELD_INDEX_KEY'),

    /*
     * Retired field keys, newest first, kept only for the length of a rotation. A value encrypted under
     * a retired key still decrypts; nothing new is ever written under one.
     *
     * @var list<string>
     */
    'retired_field_keys' => array_values(array_filter(
        explode(',', (string) env('FIELD_ENCRYPTION_RETIRED_KEYS', '')),
    )),

];
