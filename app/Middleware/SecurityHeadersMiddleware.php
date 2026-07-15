<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $nonce = base64_encode(random_bytes(18));
        $response = $next($request->withAttribute('csp_nonce', $nonce));
        $scriptSources = ["'self'", "'nonce-{$nonce}'"];
        $connectSources = ["'self'"];
        $frameSources = ["'self'"];
        if ($this->config->get('payments.paypal.enabled') === true) {
            $scriptSources[] = 'https://www.paypal.com';
            $scriptSources[] = 'https://www.paypalobjects.com';
            $connectSources[] = 'https://www.paypal.com';
            $frameSources[] = 'https://www.paypal.com';
        }
        if ($this->config->get('payments.square.enabled') === true) {
            $scriptSources[] = 'https://web.squarecdn.com';
            $scriptSources[] = 'https://sandbox.web.squarecdn.com';
            $connectSources[] = 'https://pci-connect.squareup.com';
            $frameSources[] = 'https://pci-connect.squareup.com';
        }
        $policy = implode('; ', [
            "default-src 'self'", 'script-src ' . implode(' ', array_unique($scriptSources)),
            "style-src 'self'", "img-src 'self' data:", "font-src 'self'", "media-src 'self'",
            'connect-src ' . implode(' ', array_unique($connectSources)), 'frame-src ' . implode(' ', array_unique($frameSources)),
            "frame-ancestors 'none'", "base-uri 'self'", "form-action 'self' https://www.paypal.com https://*.squareup.com", "object-src 'none'", "upgrade-insecure-requests",
        ]);
        $headers = [
            'Content-Security-Policy' => $policy,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(self)',
            'Cross-Origin-Opener-Policy' => 'same-origin-allow-popups',
            'X-Request-ID' => \App\Core\RequestContext::id(),
        ];
        $https = strtolower((string) ($request->server['HTTPS'] ?? '')) === 'on' || (string) ($request->server['SERVER_PORT'] ?? '') === '443';
        if ($https && $this->config->get('app.env') === 'production') {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        if ($request->attribute('user_id') !== null) {
            $headers['Cache-Control'] = 'private, no-store, max-age=0';
            $headers['Pragma'] = 'no-cache';
        }
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }
}
