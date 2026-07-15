<?php

declare(strict_types=1);

namespace App\Security;

use InvalidArgumentException;

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes(max(20, $bytes)));
    }

    public function verify(string $secret, string $code, ?int $timestamp = null, int $window = 1, ?int $minimumCounter = null): ?int
    {
        if (!preg_match('/^[0-9]{6}$/', $code)) {
            return null;
        }
        $counter = intdiv($timestamp ?? time(), 30);
        for ($offset = -abs($window); $offset <= abs($window); $offset++) {
            $candidateCounter = $counter + $offset;
            if ($candidateCounter < 0 || ($minimumCounter !== null && $candidateCounter <= $minimumCounter)) {
                continue;
            }
            if (hash_equals($this->at($secret, $candidateCounter), $code)) {
                return $candidateCounter;
            }
        }
        return null;
    }

    public function at(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $high = intdiv($counter, 0x100000000);
        $low = $counter % 0x100000000;
        $binaryCounter = pack('N2', $high, $low);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    public function provisioningUri(string $secret, string $account, string $issuer = 'The Purple Parlor'): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    private function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $encoded;
    }

    private function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(str_replace([' ', '-', '='], '', $encoded));
        if ($encoded === '' || preg_match('/[^A-Z2-7]/', $encoded)) {
            throw new InvalidArgumentException('Invalid base32 secret.');
        }
        $bits = '';
        foreach (str_split($encoded) as $character) {
            $position = strpos(self::ALPHABET, $character);
            $bits .= str_pad(decbin((int) $position), 5, '0', STR_PAD_LEFT);
        }
        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }
        return $decoded;
    }
}
