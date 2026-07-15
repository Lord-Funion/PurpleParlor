<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\RequestContext;
use App\Core\Response;

/** A dependency-free branded fallback that is safe even during partial setup. */
final class ErrorPage
{
    /** @param array<string,string> $headers */
    public static function response(int $status, ?string $message = null, array $headers = []): Response
    {
        $copy = [
            400 => ['A card landed sideways.', 'That request could not be understood. Check the details and try again.'],
            401 => ['This room needs a sign-in.', 'Please log in to continue.'],
            403 => ['That door is reserved.', 'Your account does not have permission to enter this area.'],
            404 => ['This chair is empty.', 'The page may have moved, or the address may be mistyped.'],
            405 => ['That move is not available.', 'This address does not accept that type of request.'],
            419 => ['Your session went cool.', 'For your protection, the form token expired. Refresh the page and try once more.'],
            422 => ['That move could not be completed.', 'Check the details and try again.'],
            429 => ['Let\'s take a breath.', 'Too many requests arrived in a short time. Wait a little before trying again.'],
            500 => ['The table needs a moment.', 'Something unexpected happened. No technical details or secrets are shown publicly.'],
            503 => ['The Parlor is preparing.', 'This service is temporarily unavailable. Please try again shortly.'],
        ];
        [$heading, $default] = $copy[$status] ?? $copy[500];
        $detail = trim((string) $message) !== '' ? (string) $message : $default;
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $requestId = RequestContext::id();
        $body = '<!doctype html><html lang="en" data-theme="purple-parlor"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
            . '<title>' . $escape((string) $status . ' · The Purple Parlor') . '</title><link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml">'
            . '<link rel="stylesheet" href="/assets/css/parlor.css"></head><body class="error-page"><main id="main-content" class="site-main">'
            . '<section class="error-page shell"><div class="error-card"><p class="error-code">' . $status . '</p><p class="eyebrow">Purple Parlor notice</p>'
            . '<h1 class="heading-error">' . $escape($heading) . '</h1><p class="lede">' . $escape($detail) . '</p><div class="button-row">'
            . '<a class="button button-gold" href="/">Return home</a><a class="button button-ghost" href="/help">Visit help</a></div>'
            . '<p class="fine-print">Request reference: <code>' . $escape($requestId) . '</code></p></div></section></main></body></html>';
        return new Response($body, $status, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'] + $headers);
    }
}
