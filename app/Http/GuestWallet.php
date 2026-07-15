<?php

declare(strict_types=1);

namespace App\Http;

use DomainException;

/**
 * A session-scoped, server-authoritative demonstration wallet. PHP's session
 * lock serializes concurrent tabs. Guest balances are never merged into an
 * account; account creation receives the normal single initial grant instead.
 */
final class GuestWallet
{
    private const INITIAL_COINS = 5_000;
    private const MAX_ENTRIES = 200;

    public function start(): void
    {
        $this->requireSession();
        if (isset($_SESSION['guest_wallet']) && is_array($_SESSION['guest_wallet'])) {
            return;
        }
        $now = gmdate('c');
        $_SESSION['guest_wallet'] = [
            'balance' => self::INITIAL_COINS,
            'created_at' => $now,
            'conversion_token' => bin2hex(random_bytes(32)),
            'ledger' => [[
                'amount' => self::INITIAL_COINS,
                'before' => 0,
                'after' => self::INITIAL_COINS,
                'reason' => 'guest.initial_grant',
                'idempotency_key' => 'guest-initial-' . session_id(),
                'created_at' => $now,
            ]],
            'rounds' => [],
        ];
    }

    public function balance(): int
    {
        $this->start();
        return max(0, (int) ($_SESSION['guest_wallet']['balance'] ?? 0));
    }

    /** @return array<string, mixed> */
    public function apply(int $amount, string $reason, string $idempotencyKey, ?string $roundId = null): array
    {
        $this->start();
        if ($amount === 0 || abs($amount) > 1_000_000_000) {
            throw new DomainException('Invalid guest balance adjustment.');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{7,190}$/', $idempotencyKey)) {
            throw new DomainException('Invalid idempotency key.');
        }
        if (!preg_match('/^[a-z][a-z0-9_.-]{2,99}$/', $reason)) {
            throw new DomainException('Invalid guest ledger reason.');
        }
        foreach ((array) $_SESSION['guest_wallet']['ledger'] as $entry) {
            if (($entry['idempotency_key'] ?? null) === $idempotencyKey) {
                if ((int) ($entry['amount'] ?? 0) !== $amount || ($entry['reason'] ?? null) !== $reason) {
                    throw new DomainException('An idempotency key was reused with different guest ledger data.');
                }
                return $entry;
            }
        }
        $before = $this->balance();
        $after = $before + $amount;
        if ($after < 0) {
            throw new DomainException('Insufficient fictional guest balance.');
        }
        $entry = [
            'amount' => $amount,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'round_id' => $roundId,
            'idempotency_key' => $idempotencyKey,
            'created_at' => gmdate('c'),
        ];
        $_SESSION['guest_wallet']['balance'] = $after;
        $_SESSION['guest_wallet']['ledger'][] = $entry;
        $_SESSION['guest_wallet']['ledger'] = array_slice($_SESSION['guest_wallet']['ledger'], -self::MAX_ENTRIES);
        return $entry;
    }

    /** @param array<string, mixed> $record */
    public function storeRound(string $idempotencyKey, array $record): void
    {
        $this->start();
        $_SESSION['guest_wallet']['rounds'][$idempotencyKey] = $record;
        if (count($_SESSION['guest_wallet']['rounds']) > 50) {
            $_SESSION['guest_wallet']['rounds'] = array_slice($_SESSION['guest_wallet']['rounds'], -50, null, true);
        }
    }

    /** @return array<string, mixed>|null */
    public function round(string $idempotencyKey): ?array
    {
        $this->start();
        $record = $_SESSION['guest_wallet']['rounds'][$idempotencyKey] ?? null;
        return is_array($record) ? $record : null;
    }

    /** @return list<array<string, mixed>> */
    public function recentRounds(): array
    {
        $this->start();
        return array_values(array_reverse($_SESSION['guest_wallet']['rounds']));
    }

    public function hasSession(): bool
    {
        return isset($_SESSION['guest_wallet']) && is_array($_SESSION['guest_wallet']);
    }

    public function conversionToken(): string
    {
        $this->start();
        $token = $_SESSION['guest_wallet']['conversion_token'] ?? null;
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['guest_wallet']['conversion_token'] = $token;
        }
        return $token;
    }

    /** @return array<string,int> Settled server-side round counts by game slug. */
    public function progressSnapshot(): array
    {
        if (!$this->hasSession()) {
            return [];
        }
        $seen = [];
        $progress = [];
        foreach ((array) $_SESSION['guest_wallet']['rounds'] as $round) {
            if (!is_array($round) || ($round['status'] ?? '') !== 'settled') {
                continue;
            }
            $publicId = (string) ($round['public_id'] ?? '');
            $slug = (string) ($round['slug'] ?? '');
            if ($publicId === '' || $slug === '' || isset($seen[$publicId])) {
                continue;
            }
            $seen[$publicId] = true;
            $progress[$slug] = ($progress[$slug] ?? 0) + 1;
        }
        return $progress;
    }

    public function forgetAfterConversion(): void
    {
        unset($_SESSION['guest_wallet'], $_SESSION['_game_seed_queue']);
    }

    public function resetAfterCooldown(int $cooldownSeconds = 86400): bool
    {
        $this->start();
        $last = strtotime((string) ($_SESSION['guest_wallet']['reset_at'] ?? '1970-01-01')) ?: 0;
        if ($last > time() - $cooldownSeconds || $this->balance() > 100) {
            return false;
        }
        $amount = self::INITIAL_COINS - $this->balance();
        if ($amount > 0) {
            $this->apply($amount, 'guest.balance_reset', 'guest-reset-' . gmdate('Ymd') . '-' . session_id());
        }
        $_SESSION['guest_wallet']['reset_at'] = gmdate('c');
        return true;
    }

    private function requireSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('A secure session must be active for guest play.');
        }
    }
}
