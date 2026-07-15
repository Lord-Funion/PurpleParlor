<?php

declare(strict_types=1);

namespace App\Payments;

use RuntimeException;

class HttpClient
{
    /** @return array{status:int,headers:array<string,string>,body:string,json:array<string,mixed>} */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new RuntimeException('Payment-provider requests require HTTPS.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The cURL PHP extension is required for payment providers.');
        }
        $handle = curl_init($url);
        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout), CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $separator)))] = trim(substr($line, $separator + 1));
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($handle);
        if (!is_string($response)) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('Payment provider connection failed: ' . $error);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        $decoded = json_decode($response, true);
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $response, 'json' => is_array($decoded) ? $decoded : []];
    }
}
