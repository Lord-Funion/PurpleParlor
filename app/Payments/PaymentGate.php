<?php

declare(strict_types=1);

namespace App\Payments;

use App\Core\Config;
use App\Database\Database;

final class PaymentGate
{
    public function __construct(private readonly Config $config, private readonly ?Database $database = null)
    {
    }

    public function liveReady(): bool
    {
        return $this->config->get('payments.enabled') === true
            && $this->config->get('payments.mode') === 'live'
            && $this->config->get('payments.adult_owner_confirmed') === true
            && $this->config->get('payments.live_activation_lock') === false
            && !$this->databaseActivationLocked();
    }

    public function checkoutAllowed(string $provider): bool
    {
        $mode = (string) $this->config->get('payments.mode', 'sandbox');
        $environment = (string) $this->config->get('app.env', 'production');
        if ($provider === 'demo') {
            $explicitDemo = strtolower((string) $this->config->get('payments.provider', '')) === 'demo';
            $configurationLocked = $this->config->get('payments.live_activation_lock', true) === true;
            $knownEnvironment = in_array($environment, ['local', 'development', 'testing', 'production'], true);
            $persistentProductionLock = $environment !== 'production' || $this->database === null || $this->databaseActivationLocked();
            return $explicitDemo && $mode !== 'live' && $configurationLocked && $knownEnvironment && $persistentProductionLock;
        }
        if ($mode === 'live') {
            return $this->liveReady() && $this->providerConfigured($provider, 'live');
        }
        return $this->config->get('payments.enabled') === true && $mode === 'sandbox' && $this->providerConfigured($provider, 'sandbox');
    }

    public function assertCheckoutAllowed(string $provider): void
    {
        if (!$this->checkoutAllowed($provider)) {
            throw new PaymentsDisabled('Payments are disabled or the selected provider is not safely configured.');
        }
    }

    public function checkoutEnvironment(string $provider): string
    {
        $provider = strtolower($provider);
        if ($provider === 'demo') {
            return 'demo';
        }
        $environment = strtolower((string) $this->config->get("payments.{$provider}.environment", ''));
        return in_array($environment, ['sandbox', 'live'], true) ? $environment : 'unconfigured';
    }

    public function providerConfigured(string $provider, string $environment): bool
    {
        if ($provider === 'paypal') {
            return $this->config->get('payments.paypal.enabled') === true
                && $this->config->get('payments.paypal.environment') === $environment
                && trim((string) $this->config->get('payments.paypal.client_id', '')) !== ''
                && trim((string) $this->config->get('payments.paypal.client_secret', '')) !== ''
                && trim((string) $this->config->get('payments.paypal.webhook_id', '')) !== '';
        }
        if ($provider === 'square') {
            return $this->config->get('payments.square.enabled') === true
                && $this->config->get('payments.square.environment') === $environment
                && trim((string) $this->config->get('payments.square.application_id', '')) !== ''
                && trim((string) $this->config->get('payments.square.access_token', '')) !== ''
                && trim((string) $this->config->get('payments.square.location_id', '')) !== ''
                && trim((string) $this->config->get('payments.square.signature_key', '')) !== ''
                && str_starts_with((string) $this->config->get('payments.square.webhook_url', ''), 'https://');
        }
        return $provider === 'demo';
    }

    public function administrativeSandboxTestAllowed(string $provider, bool $isAdultOwner, bool $reauthenticated): bool
    {
        return $isAdultOwner && $reauthenticated
            && $this->config->get('payments.mode') === 'sandbox'
            && $this->providerConfigured($provider, 'sandbox');
    }

    private function databaseActivationLocked(): bool
    {
        if ($this->database === null) {
            return false;
        }
        try {
            $row = $this->database->fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'payments.production_locked'");
            if ($row === null) {
                return true;
            }
            return json_decode((string) $row['setting_value'], true) !== false;
        } catch (\Throwable) {
            return true;
        }
    }
}
