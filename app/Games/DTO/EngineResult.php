<?php

declare(strict_types=1);

namespace PurpleParlor\Games\DTO;

final class EngineResult implements \JsonSerializable
{
    /** @param array<string, mixed> $payload @param array<string, mixed> $serverState @param array<string, mixed> $transition */
    public function __construct(
        private readonly array $payload,
        private readonly array $serverState,
        public readonly array $transition,
        private readonly ?string $signature,
    ) {}

    /** @return array<string, mixed> */ public function serverState(): array { return $this->serverState; }
    /** @return array<string, mixed> */ public function payload(): array { return $this->payload; }
    public function signature(): ?string { return $this->signature; }

    public function jsonSerialize(): array
    {
        return $this->signature === null ? $this->payload : $this->payload + ['signature' => $this->signature, 'signatureAlgorithm' => 'HMAC-SHA256'];
    }
}
