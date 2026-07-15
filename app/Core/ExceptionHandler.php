<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\AuthenticationException;
use App\Auth\AuthorizationException;
use App\Payments\PaymentsDisabled;
use App\Http\ErrorPage;
use PurpleParlor\Games\Exceptions\GameException;
use Throwable;

final class ExceptionHandler
{
    public function __construct(private readonly Logger $logger, private readonly Config $config)
    {
    }

    public function render(Throwable $exception, ?Request $request = null): Response
    {
        $status = match (true) {
            $exception instanceof GameException => $exception->httpStatus,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => 403,
            $exception instanceof PaymentsDisabled => 503,
            $exception instanceof \DomainException, $exception instanceof \InvalidArgumentException => 422,
            default => 500,
        };
        $this->logger->error('Request failed.', [
            'exception' => $exception,
            'status' => $status,
            'method' => $request?->method,
            'uri' => $request?->uri,
        ]);
        $debug = $this->config->get('app.debug') === true && $this->config->get('app.env') !== 'production';
        $message = $status >= 500 ? 'An unexpected error occurred.' : $exception->getMessage();
        if ($debug) {
            $message .= ' [' . $exception::class . ' at ' . basename($exception->getFile()) . ':' . $exception->getLine() . ']';
        }
        if ($request?->expectsJson() ?? true) {
            $payload = ['error' => $message, 'request_id' => RequestContext::id()];
            if ($exception instanceof GameException) {
                $payload += ['code' => $status >= 500 ? 'game_unavailable' : $exception->errorCode];
                if ($status < 500) {
                    $payload += $exception->context;
                }
            }
            return Response::json($payload, $status);
        }
        return ErrorPage::response($status, $message);
    }
}
