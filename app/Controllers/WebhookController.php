<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Payments\WebhookProcessor;

final class WebhookController
{
    public function __construct(private readonly WebhookProcessor $webhooks)
    {
    }

    public function paypal(Request $request): Response
    {
        return $this->process('paypal', $request);
    }

    public function square(Request $request): Response
    {
        return $this->process('square', $request);
    }

    private function process(string $provider, Request $request): Response
    {
        $result = $this->webhooks->process($provider, $request->rawBody, $request->headers);
        $status = $result->accepted ? 200 : ($result->status === 'invalid_signature' ? 401 : 422);
        return Response::json(['accepted' => $result->accepted, 'duplicate' => $result->duplicate, 'status' => $result->status, 'event_id' => $result->eventId], $status);
    }
}
