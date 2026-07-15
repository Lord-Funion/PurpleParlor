<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class Encryptor
{
    private string $key;

    public function __construct(string $appKey)
    {
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            $appKey = $decoded === false ? '' : $decoded;
        }
        if (strlen($appKey) < 32) {
            throw new RuntimeException('APP_KEY must contain at least 32 bytes. Generate it with bin/system-check.php --generate-key.');
        }
        $this->key = hash('sha256', $appKey, true);
    }

    public function encrypt(string $plaintext, string $context = 'default'): string
    {
        $aad = 'purple-parlor:' . $context;
        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $this->key);
            return 'sodium:' . base64_encode($nonce . $ciphertext);
        }
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('Sodium or OpenSSL is required for authenticated encryption.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($ciphertext === false) {
            throw new RuntimeException('Authenticated encryption failed.');
        }
        return 'gcm:' . base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $payload, string $context = 'default'): string
    {
        [$format, $encoded] = array_pad(explode(':', $payload, 2), 2, '');
        $data = base64_decode($encoded, true);
        if ($data === false) {
            throw new RuntimeException('Encrypted payload is malformed.');
        }
        $aad = 'purple-parlor:' . $context;
        if ($format === 'sodium') {
            if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
                throw new RuntimeException('Sodium is required to decrypt this value.');
            }
            $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            if (strlen($data) <= $nonceLength) {
                throw new RuntimeException('Encrypted payload is malformed.');
            }
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($data, $nonceLength), $aad, substr($data, 0, $nonceLength), $this->key);
        } elseif ($format === 'gcm') {
            if (strlen($data) <= 28) {
                throw new RuntimeException('Encrypted payload is malformed.');
            }
            $plaintext = openssl_decrypt(substr($data, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16), $aad);
        } else {
            throw new RuntimeException('Encrypted payload format is unsupported.');
        }
        if (!is_string($plaintext)) {
            throw new RuntimeException('Encrypted payload authentication failed.');
        }
        return $plaintext;
    }
}
