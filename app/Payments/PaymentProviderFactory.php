<?php

declare(strict_types=1);

namespace App\Payments;

use App\Core\Config;
use App\Database\Database;
use InvalidArgumentException;

final class PaymentProviderFactory
{
    public function __construct(
        private readonly Config $config,
        private readonly PaymentGate $gate,
        private readonly HttpClient $http,
        private readonly Database $database,
        /** @var array<string,PaymentProviderInterface> */
        private readonly array $overrides = [],
    ) {
    }

    public function configured(): PaymentProviderInterface
    {
        return $this->make((string) $this->config->get('payments.provider', 'demo'));
    }

    public function make(string $provider): PaymentProviderInterface
    {
        $provider = strtolower($provider);
        if (($this->overrides[$provider] ?? null) instanceof PaymentProviderInterface) {
            return $this->overrides[$provider];
        }
        return match ($provider) {
            'demo' => new DemoPaymentProvider((string) $this->config->require('app.key'), $this->database),
            'paypal' => new PayPalPaymentProvider($this->config, $this->gate, $this->http),
            'square' => new SquarePaymentProvider($this->config, $this->gate, $this->http, $this->database),
            default => throw new InvalidArgumentException('Unsupported payment provider.'),
        };
    }
}
