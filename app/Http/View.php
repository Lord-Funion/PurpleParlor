<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

final class View
{
    public function __construct(private readonly string $root)
    {
    }

    /** @param array<string, mixed> $variables */
    public function render(string $view, array $variables = [], ?string $layout = 'layouts/app'): string
    {
        // Nested layouts (notably the administration shell) render their
        // content before the primary app layout is included. Provide the
        // standard helpers at the renderer boundary so every view receives
        // them regardless of layout nesting order.
        $esc = static fn (mixed $value): string => function_exists('e')
            ? e((string) $value)
            : htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $variables['esc'] = $esc;
        $variables['url'] = static function (string $name, array $parameters = [], string $fallback = '/') use ($esc): string {
            if (function_exists('route')) {
                try {
                    return $esc(route($name, $parameters));
                } catch (\Throwable) {
                    // Partially installed and error-page rendering uses the
                    // explicitly supplied local fallback.
                }
            }
            return $esc($fallback);
        };
        $variables['assetUrl'] = static function (string $path) use ($esc): string {
            return function_exists('asset')
                ? $esc(asset($path))
                : $esc('/assets/' . ltrim($path, '/'));
        };
        $viewPath = $this->resolve($view);
        if ($layout === null) {
            return $this->capture($viewPath, $variables);
        }

        $layoutPath = $this->resolve($layout);
        $variables['contentView'] = $viewPath;
        return $this->capture($layoutPath, $variables);
    }

    /** @param array<string, mixed> $variables */
    private function capture(string $path, array $variables): string
    {
        extract($variables, EXTR_SKIP);
        ob_start();
        try {
            require $path;
            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
    }

    private function resolve(string $view): string
    {
        $relative = str_replace(['.', '/', '\\'], DIRECTORY_SEPARATOR, preg_replace('/\.php$/', '', $view) ?? $view) . '.php';
        $candidate = $this->root . DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR);
        $resolved = realpath($candidate);
        $root = realpath($this->root);
        if ($resolved === false || $root === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The requested view is unavailable.');
        }
        return $resolved;
    }
}
