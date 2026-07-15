<?php

declare(strict_types=1);

namespace App\Payments;

interface PaymentProviderInterface
{
    public function name(): string;
    public function createOneTimeCheckout(PurchaseRequest $request): CheckoutResult;
    public function createSubscription(SubscriptionRequest $request): CheckoutResult;
    public function cancelSubscription(string $externalId): ProviderResult;
    public function refundPayment(string $externalId, int $amountCents, ?string $idempotencyKey = null): ProviderResult;
    public function retrieveSubscription(string $externalId): ProviderSubscription;
    /** @param array<string, string|list<string>> $headers */
    public function verifyWebhook(string $rawBody, array $headers): bool;
    /** @param array<string, string|list<string>> $headers */
    public function parseWebhook(string $rawBody, array $headers): PaymentEvent;
}
