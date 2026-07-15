<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Database\Database;
use App\Http\GuestWallet;
use App\Security\Encryptor;
use DomainException;
use PurpleParlor\Games\DTO\GameRequest;
use PurpleParlor\Games\DTO\PlayerContext;
use PurpleParlor\Games\Fairness\SeedCommitment;
use PurpleParlor\Games\Fairness\VerifiableRandomSource;
use PurpleParlor\Games\GameEngine;
use PurpleParlor\Games\GameRegistry;
use PurpleParlor\Games\Exceptions\GameException;
use PurpleParlor\Games\Security\OutcomeSigner;

/**
 * Server-authoritative bridge between the HTTP API, game engine, stored rounds,
 * and virtual-currency ledger. Hidden game state is authenticated-encrypted in
 * the database and never accepted from or returned to JavaScript.
 */
final class GamePlayService
{
    /** @var array<string, array<string,mixed>> */
    private array $games;

    public function __construct(
        private readonly Database $database,
        private readonly Config $config,
        private readonly VirtualLedgerService $ledger,
        private readonly GuestWallet $guests,
        private readonly Encryptor $encryptor,
        private readonly EngagementProgressService $engagement,
    ) {
        $games = $config->get('games', []);
        $this->games = is_array($games) ? $games : [];
    }

    /** @return list<array<string,mixed>> */
    public function catalog(): array
    {
        $available = [];
        foreach ($this->database->fetchAll('SELECT slug, active, configuration_json FROM game_definitions ORDER BY slug') as $row) {
            $slug = (string) $row['slug'];
            if ((int) $row['active'] !== 1 || !isset($this->games[$slug])) {
                continue;
            }
            $available[$slug] = $this->withStoredWager($this->games[$slug], (string) ($row['configuration_json'] ?? ''));
        }
        return (new GameRegistry($available))->publicCatalog();
    }

    /**
     * Publishes a commitment before a wager can be accepted. The corresponding
     * encrypted seed is consumed by the next new round, never generated after
     * that wager request arrives.
     */
    public function precommit(?int $userId): string
    {
        if ($userId === null) {
            $this->guests->start();
            $record = $_SESSION['_game_seed_queue'] ?? null;
            if (!is_array($record) || !is_string($record['server_seed'] ?? null) || !is_string($record['commit_hash'] ?? null)) {
                $record = $this->newGuestCommitment((int) ($record['version'] ?? 0) + 1);
                $_SESSION['_game_seed_queue'] = $record;
            }
            return (string) $record['commit_hash'];
        }
        return $this->database->transaction(fn (Database $db): string => (string) $this->ensureAuthenticatedCommitment($db, $userId)['commit_hash']);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function play(?int $userId, string $slug, array $input): array
    {
        $newRound = $this->optionalRoundId($input) === null;
        $this->gameConfiguration($slug, $newRound);
        if ($userId !== null) {
            $this->assertPlayControls($userId, $newRound);
        }
        return $userId === null
            ? $this->playGuest($slug, $input)
            : $this->playAuthenticated($userId, $slug, $input);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function playAuthenticated(int $userId, string $slug, array $input): array
    {
        return $this->database->transaction(function (Database $db) use ($userId, $slug, $input): array {
            $idempotency = $this->idempotencyKey($input);
            $fingerprint = $this->fingerprint($slug, $input);
            $duplicate = $db->fetchOne(
                'SELECT gra.result_json, gra.action_json, gr.user_id FROM game_round_actions gra INNER JOIN game_rounds gr ON gr.id = gra.round_id WHERE gra.idempotency_key = :key',
                ['key' => $idempotency],
            );
            if ($duplicate !== null) {
                if ((int) $duplicate['user_id'] !== $userId) {
                    throw new DomainException('That idempotency key is unavailable.');
                }
                return $this->duplicateResponse((string) $duplicate['action_json'], (string) $duplicate['result_json'], $fingerprint, $userId, false);
            }

            $publicRoundId = $this->optionalRoundId($input);
            if ($publicRoundId === null) {
                return $this->startAuthenticatedRound($db, $userId, $slug, $input, $fingerprint);
            }
            return $this->continueAuthenticatedRound($db, $userId, $slug, $publicRoundId, $input, $fingerprint);
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function startAuthenticatedRound(Database $db, int $userId, string $slug, array $input, string $fingerprint): array
    {
        $gameDefinition = $db->fetchOne('SELECT id FROM game_definitions WHERE slug = :slug AND active = 1', ['slug' => $slug]);
        if ($gameDefinition === null) {
            throw new DomainException('This game is not currently available.');
        }
        $clientSeed = $this->clientSeed($input);
        ['server_seed' => $serverSeed, 'commit_hash' => $commitment, 'next_hash' => $nextCommitment] = $this->consumeAuthenticatedCommitment($db, $userId);
        $request = GameRequest::fromArray($input + ['client_seed' => $clientSeed]);
        $balance = $this->ledger->balance($userId, VirtualLedgerService::COZY_COINS);
        $result = $this->engine($serverSeed, $clientSeed, 0, $commitment)->execute(
            $slug,
            $request,
            new PlayerContext((string) $userId, $balance),
        );
        $payload = $result->payload();
        $publicId = (string) $payload['roundId'];
        $now = gmdate('Y-m-d H:i:s');
        $complete = (bool) $payload['complete'];
        $roundId = $db->insert(
            'INSERT INTO game_rounds (user_id, game_id, public_id, status, wager_amount, payout_amount, currency, client_seed, server_seed_hash, server_seed_encrypted, request_id, idempotency_key, started_at, settled_at)
             VALUES (:user, :game, :public, :status, :wager, :payout, :currency, :client, :hash, :encrypted, :request, :key, :started, :settled)',
            [
                'user' => $userId, 'game' => $gameDefinition['id'], 'public' => $publicId,
                'status' => $complete ? 'settled' : 'active', 'wager' => $request->wager,
                'payout' => $complete ? (int) $payload['payout'] : null, 'currency' => VirtualLedgerService::COZY_COINS,
                'client' => $clientSeed, 'hash' => $commitment,
                'encrypted' => $this->encryptor->encrypt($serverSeed, 'game-seed:' . $publicId),
                'request' => \App\Core\RequestContext::id(), 'key' => $request->idempotencyKey,
                'started' => $now, 'settled' => $complete ? $now : null,
            ],
        );
        $db->execute(
            'INSERT INTO game_seed_commits (round_id, commit_hash, server_seed, revealed_at, created_at) VALUES (:round, :hash, :seed, :revealed, :created)',
            ['round' => $roundId, 'hash' => $commitment, 'seed' => $complete ? $serverSeed : null, 'revealed' => $complete ? $now : null, 'created' => $now],
        );
        if ($request->wager > 0 && (bool) ($payload['wagerCharged'] ?? true)) {
            $this->ledger->debitWager($userId, $roundId, $request->wager, 'game:wager:' . $publicId);
        }
        $publicPayload = $this->decorateFairnessPayload($result->jsonSerialize(), $serverSeed, $nextCommitment);
        $this->storeAction($db, $roundId, 1, $request->action, $input, $fingerprint, $publicPayload, $result->serverState(), $publicId, $request->idempotencyKey);
        $this->settleIfComplete($db, $userId, $roundId, $publicId, $serverSeed, $payload);
        $this->touchRecent($db, $userId, (int) $gameDefinition['id']);
        return $this->publicResponse($publicPayload, $userId, false);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function continueAuthenticatedRound(Database $db, int $userId, string $slug, string $publicRoundId, array $input, string $fingerprint): array
    {
        $round = $db->fetchOne($db->forUpdate(
            'SELECT gr.*, gd.slug FROM game_rounds gr INNER JOIN game_definitions gd ON gd.id = gr.game_id WHERE gr.public_id = :public AND gr.user_id = :user'
        ), ['public' => $publicRoundId, 'user' => $userId]);
        if ($round === null || !hash_equals($slug, (string) $round['slug'])) {
            throw new DomainException('The requested round was not found for this game.');
        }
        if ((string) $round['status'] !== 'active') {
            throw new DomainException('That game round is already complete.');
        }
        $latest = $db->fetchOne(
            'SELECT sequence_number, result_json FROM game_round_actions WHERE round_id = :round ORDER BY sequence_number DESC LIMIT 1',
            ['round' => $round['id']],
        );
        if ($latest === null) {
            throw new DomainException('The stored round state is unavailable.');
        }
        $record = $this->decodeObject((string) $latest['result_json']);
        $encryptedState = (string) ($record['server_state_encrypted'] ?? '');
        $previousSequence = (int) $latest['sequence_number'];
        $state = $this->decodeObject($this->encryptor->decrypt($encryptedState, 'game-state:' . $publicRoundId . ':' . $previousSequence));
        $serverSeed = $this->encryptor->decrypt((string) $round['server_seed_encrypted'], 'game-seed:' . $publicRoundId);
        $clientSeed = (string) ($round['client_seed'] ?: 'parlor-player');
        $input['wager'] = (int) $round['wager_amount'];
        $input['client_seed'] = $clientSeed;
        $input['round_id'] = $publicRoundId;
        $request = GameRequest::fromArray($input, $state);
        $balance = $this->ledger->balance($userId, VirtualLedgerService::COZY_COINS);
        $result = $this->engine($serverSeed, $clientSeed, $previousSequence, (string) $round['server_seed_hash'])->execute(
            $slug,
            $request,
            new PlayerContext((string) $userId, $balance),
        );
        $sequence = $previousSequence + 1;
        $nextCommitment = (string) $this->ensureAuthenticatedCommitment($db, $userId)['commit_hash'];
        $publicPayload = $this->decorateFairnessPayload($result->jsonSerialize(), $serverSeed, $nextCommitment);
        $this->storeAction($db, (int) $round['id'], $sequence, $request->action, $input, $fingerprint, $publicPayload, $result->serverState(), $publicRoundId, $request->idempotencyKey);
        $this->settleIfComplete($db, $userId, (int) $round['id'], $publicRoundId, $serverSeed, $result->payload());
        return $this->publicResponse($publicPayload, $userId, false);
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $state @param array<string,mixed> $input */
    private function storeAction(Database $db, int $roundId, int $sequence, string $action, array $input, string $fingerprint, array $payload, array $state, string $publicId, string $idempotency): void
    {
        $actionRecord = ['request_fingerprint' => $fingerprint, 'input' => $this->safeInput($input)];
        $resultRecord = [
            'public' => $payload,
            'server_state_encrypted' => $this->encryptor->encrypt(
                json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'game-state:' . $publicId . ':' . $sequence,
            ),
        ];
        $db->execute(
            'INSERT INTO game_round_actions (round_id, sequence_number, action_type, action_json, result_json, idempotency_key, created_at)
             VALUES (:round, :sequence, :action, :input, :result, :key, :created)',
            [
                'round' => $roundId, 'sequence' => $sequence, 'action' => $action,
                'input' => json_encode($actionRecord, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'result' => json_encode($resultRecord, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'key' => $idempotency, 'created' => gmdate('Y-m-d H:i:s'),
            ],
        );
    }

    /** @param array<string,mixed> $payload */
    private function settleIfComplete(Database $db, int $userId, int $roundId, string $publicId, string $serverSeed, array $payload): void
    {
        if (!(bool) ($payload['complete'] ?? false)) {
            return;
        }
        $payout = max(0, (int) ($payload['payout'] ?? 0));
        if ($payout > 0) {
            $this->ledger->creditPayout($userId, $roundId, $payout, 'game:payout:' . $publicId);
        }
        $now = gmdate('Y-m-d H:i:s');
        $db->execute(
            "UPDATE game_rounds SET status = 'settled', payout_amount = :payout, settled_at = :settled WHERE id = :id AND status = 'active'",
            ['payout' => $payout, 'settled' => $now, 'id' => $roundId],
        );
        $db->execute(
            'UPDATE game_seed_commits SET server_seed = :seed, revealed_at = :revealed WHERE round_id = :round AND revealed_at IS NULL',
            ['seed' => $serverSeed, 'revealed' => $now, 'round' => $roundId],
        );
        foreach (['rounds' => 1, 'fictional_wagered' => (int) ($payload['wager'] ?? 0), 'fictional_returned' => $payout] as $key => $increment) {
            $this->incrementStatistic($db, $userId, $roundId, $key, $increment);
        }
        $outcome = (string) ($payload['outcome'] ?? '');
        $this->incrementStatistic($db, $userId, $roundId, $payout > (int) ($payload['wager'] ?? 0) ? 'wins' : ($payout === (int) ($payload['wager'] ?? 0) ? 'pushes' : 'losses'), 1);
        $this->engagement->recordCompletedRound($userId, $roundId, $payout);
    }

    private function incrementStatistic(Database $db, int $userId, int $roundId, string $key, int $increment): void
    {
        if ($increment === 0) {
            return;
        }
        $round = $db->fetchOne('SELECT game_id FROM game_rounds WHERE id = :id', ['id' => $roundId]);
        $gameId = (int) ($round['game_id'] ?? 0);
        $existing = $db->fetchOne('SELECT id FROM player_statistics WHERE user_id = :user AND game_id = :game AND stat_key = :key', ['user' => $userId, 'game' => $gameId, 'key' => $key]);
        if ($existing === null) {
            $db->execute('INSERT INTO player_statistics (user_id, game_id, stat_key, stat_value, updated_at) VALUES (:user, :game, :key, :value, :updated)', ['user' => $userId, 'game' => $gameId, 'key' => $key, 'value' => $increment, 'updated' => gmdate('Y-m-d H:i:s')]);
        } else {
            $db->execute('UPDATE player_statistics SET stat_value = stat_value + :value, updated_at = :updated WHERE id = :id', ['value' => $increment, 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $existing['id']]);
        }
    }

    private function touchRecent(Database $db, int $userId, int $gameId): void
    {
        $db->execute('DELETE FROM recent_games WHERE user_id = :user AND game_id = :game', ['user' => $userId, 'game' => $gameId]);
        $db->execute('INSERT INTO recent_games (user_id, game_id, last_played_at) VALUES (:user, :game, :played)', ['user' => $userId, 'game' => $gameId, 'played' => gmdate('Y-m-d H:i:s')]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function playGuest(string $slug, array $input): array
    {
        $this->guests->start();
        $idempotency = $this->idempotencyKey($input);
        $fingerprint = $this->fingerprint($slug, $input);
        $duplicate = $this->guests->round($idempotency);
        if ($duplicate !== null) {
            if (!hash_equals($fingerprint, (string) ($duplicate['request_fingerprint'] ?? ''))) {
                throw new DomainException('An idempotency key was reused with different game data.');
            }
            return $this->publicResponse((array) $duplicate['public'], null, true);
        }

        $publicRoundId = $this->optionalRoundId($input);
        $previous = $publicRoundId === null ? null : $this->guestRoundByPublicId($publicRoundId);
        if ($publicRoundId !== null && ($previous === null || ($previous['status'] ?? '') !== 'active' || ($previous['slug'] ?? '') !== $slug)) {
            throw new DomainException('That guest round is unavailable or already complete.');
        }
        $new = $previous === null;
        if ($new) {
            ['server_seed' => $serverSeed, 'commit_hash' => $commitment, 'next_hash' => $nextCommitment] = $this->consumeGuestCommitment();
        } else {
            $serverSeed = (string) $previous['server_seed'];
            $commitment = (string) $previous['server_seed_hash'];
            $nextCommitment = $this->precommit(null);
        }
        $clientSeed = $new ? $this->clientSeed($input) : (string) $previous['client_seed'];
        $nonce = $new ? 0 : (int) $previous['sequence'];
        $state = $new ? [] : (array) $previous['server_state'];
        if (!$new) {
            $input['wager'] = (int) $previous['wager'];
            $input['client_seed'] = $clientSeed;
            $input['round_id'] = $publicRoundId;
        }
        $request = GameRequest::fromArray($input, $state);
        $result = $this->engine($serverSeed, $clientSeed, $nonce, $commitment)->execute(
            $slug,
            $request,
            new PlayerContext('guest:' . session_id(), $this->guests->balance(), true),
        );
        $payload = $result->payload();
        $publicId = (string) $payload['roundId'];
        if ($new && $request->wager > 0 && (bool) ($payload['wagerCharged'] ?? true)) {
            $this->guests->apply(-$request->wager, 'game.wager', 'guest:wager:' . $publicId, $publicId);
        }
        if ((bool) $payload['complete'] && (int) $payload['payout'] > 0) {
            $this->guests->apply((int) $payload['payout'], 'game.payout', 'guest:payout:' . $publicId, $publicId);
        }
        $publicPayload = $this->decorateFairnessPayload($result->jsonSerialize(), $serverSeed, $nextCommitment);
        $record = [
            'request_fingerprint' => $fingerprint, 'public' => $publicPayload, 'public_id' => $publicId,
            'slug' => $slug, 'wager' => $request->wager, 'status' => (bool) $payload['complete'] ? 'settled' : 'active',
            'server_seed' => $serverSeed, 'server_seed_hash' => $commitment, 'client_seed' => $clientSeed,
            'server_state' => $result->serverState(), 'sequence' => $nonce + 1, 'created_at' => gmdate('c'),
        ];
        $this->guests->storeRound($idempotency, $record);
        return $this->publicResponse($publicPayload, null, true);
    }

    /** @return array{server_seed:string,commit_hash:string,next_hash:string} */
    private function consumeAuthenticatedCommitment(Database $db, int $userId): array
    {
        $owner = 'user:' . $userId;
        $row = $db->fetchOne($db->forUpdate('SELECT * FROM game_seed_queue WHERE owner_key = :owner'), ['owner' => $owner]);
        if ($row === null || $row['consumed_at'] !== null) {
            $published = $this->ensureAuthenticatedCommitment($db, $userId, true);
            throw new GameException(
                'A server-seed commitment has now been published. Retry the unchanged round request to consume it.',
                'seed_precommit_required',
                428,
                ['nextServerSeedHash' => $published['commit_hash']],
            );
        }
        $version = (int) $row['version'];
        $serverSeed = $this->encryptor->decrypt((string) $row['server_seed_encrypted'], 'game-queue:' . $owner . ':' . $version);
        if (!hash_equals((string) $row['commit_hash'], SeedCommitment::commit($serverSeed))) {
            throw new \RuntimeException('Stored game commitment failed integrity validation.');
        }
        $nextSeed = SeedCommitment::generateServerSeed();
        $nextHash = SeedCommitment::commit($nextSeed);
        $nextVersion = $version + 1;
        $db->execute(
            'UPDATE game_seed_queue SET commit_hash = :hash, server_seed_encrypted = :seed, version = :version, created_at = :created, consumed_at = NULL WHERE owner_key = :owner AND version = :previous',
            ['hash' => $nextHash, 'seed' => $this->encryptor->encrypt($nextSeed, 'game-queue:' . $owner . ':' . $nextVersion), 'version' => $nextVersion, 'created' => gmdate('Y-m-d H:i:s'), 'owner' => $owner, 'previous' => $version],
        );
        return ['server_seed' => $serverSeed, 'commit_hash' => (string) $row['commit_hash'], 'next_hash' => $nextHash];
    }

    /** @return array<string,mixed> */
    private function ensureAuthenticatedCommitment(Database $db, int $userId, bool $locked = false): array
    {
        $owner = 'user:' . $userId;
        $query = 'SELECT * FROM game_seed_queue WHERE owner_key = :owner';
        $row = $db->fetchOne($locked ? $db->forUpdate($query) : $query, ['owner' => $owner]);
        if ($row !== null && $row['consumed_at'] === null) {
            return $row;
        }
        $version = (int) ($row['version'] ?? 0) + 1;
        $serverSeed = SeedCommitment::generateServerSeed();
        $hash = SeedCommitment::commit($serverSeed);
        $encrypted = $this->encryptor->encrypt($serverSeed, 'game-queue:' . $owner . ':' . $version);
        if ($row === null) {
            $db->execute('INSERT INTO game_seed_queue (owner_key, commit_hash, server_seed_encrypted, version, created_at, consumed_at) VALUES (:owner, :hash, :seed, :version, :created, NULL)', ['owner' => $owner, 'hash' => $hash, 'seed' => $encrypted, 'version' => $version, 'created' => gmdate('Y-m-d H:i:s')]);
        } else {
            $db->execute('UPDATE game_seed_queue SET commit_hash = :hash, server_seed_encrypted = :seed, version = :version, created_at = :created, consumed_at = NULL WHERE owner_key = :owner', ['hash' => $hash, 'seed' => $encrypted, 'version' => $version, 'created' => gmdate('Y-m-d H:i:s'), 'owner' => $owner]);
        }
        return ['owner_key' => $owner, 'commit_hash' => $hash, 'server_seed_encrypted' => $encrypted, 'version' => $version, 'created_at' => gmdate('Y-m-d H:i:s'), 'consumed_at' => null];
    }

    /** @return array{server_seed:string,commit_hash:string,next_hash:string} */
    private function consumeGuestCommitment(): array
    {
        $this->guests->start();
        $record = $_SESSION['_game_seed_queue'] ?? null;
        if (!is_array($record) || !is_string($record['server_seed'] ?? null) || !is_string($record['commit_hash'] ?? null)) {
            $published = $this->newGuestCommitment((int) ($record['version'] ?? 0) + 1);
            $_SESSION['_game_seed_queue'] = $published;
            throw new GameException(
                'A server-seed commitment has now been published. Retry the unchanged round request to consume it.',
                'seed_precommit_required',
                428,
                ['nextServerSeedHash' => $published['commit_hash']],
            );
        }
        $seed = (string) $record['server_seed'];
        $hash = (string) $record['commit_hash'];
        if (!hash_equals($hash, SeedCommitment::commit($seed))) {
            throw new \RuntimeException('Guest game commitment failed integrity validation.');
        }
        $next = $this->newGuestCommitment((int) $record['version'] + 1);
        $_SESSION['_game_seed_queue'] = $next;
        return ['server_seed' => $seed, 'commit_hash' => $hash, 'next_hash' => (string) $next['commit_hash']];
    }

    /** @return array{server_seed:string,commit_hash:string,version:int,created_at:string} */
    private function newGuestCommitment(int $version): array
    {
        $seed = SeedCommitment::generateServerSeed();
        return ['server_seed' => $seed, 'commit_hash' => SeedCommitment::commit($seed), 'version' => max(1, $version), 'created_at' => gmdate('c')];
    }

    /** @return array<string,mixed>|null */
    private function guestRoundByPublicId(string $publicId): ?array
    {
        foreach ($this->guests->recentRounds() as $round) {
            if (($round['public_id'] ?? null) === $publicId) {
                return $round;
            }
        }
        return null;
    }

    private function engine(string $serverSeed, string $clientSeed, int $nonce, string $commitment): GameEngine
    {
        $random = new VerifiableRandomSource($serverSeed, $clientSeed, $nonce);
        $registry = new GameRegistry($this->games, $random);
        $key = (string) $this->config->get('app.key', '');
        return new GameEngine(
            $registry,
            new OutcomeSigner($key),
            SeedCommitment::publicEnvelope($commitment, $clientSeed, $nonce),
        );
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function decorateFairnessPayload(array $payload, string $serverSeed, string $nextCommitment): array
    {
        unset($payload['signature'], $payload['signatureAlgorithm']);
        $payload['nextServerSeedHash'] = $nextCommitment;
        if ((bool) ($payload['complete'] ?? false)) {
            $fairness = is_array($payload['fairness'] ?? null) ? $payload['fairness'] : [];
            $fairness['revealedServerSeed'] = $serverSeed;
            $fairness['revealStatus'] = 'settled_and_rotated';
            $payload['fairness'] = $fairness;
        }
        $signer = new OutcomeSigner((string) $this->config->get('app.key', ''));
        return $payload + ['signature' => $signer->sign($payload), 'signatureAlgorithm' => 'HMAC-SHA256'];
    }

    /** @return array<string,mixed> */
    private function duplicateResponse(string $actionJson, string $resultJson, string $fingerprint, int $userId, bool $guest): array
    {
        $action = $this->decodeObject($actionJson);
        if (!hash_equals($fingerprint, (string) ($action['request_fingerprint'] ?? ''))) {
            throw new DomainException('An idempotency key was reused with different game data.');
        }
        $result = $this->decodeObject($resultJson);
        return $this->publicResponse((array) ($result['public'] ?? []), $userId, $guest);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function publicResponse(array $payload, ?int $userId, bool $guest): array
    {
        $balance = $guest
            ? $this->guests->balance()
            : $this->ledger->balance((int) $userId, VirtualLedgerService::COZY_COINS);
        return $payload + [
            'balance' => $balance,
            'currency' => VirtualLedgerService::COZY_COINS,
            'guest' => $guest,
            'cashValue' => false,
            'currencyNotice' => 'Virtual currency has no cash value and cannot be purchased, transferred, redeemed, withdrawn, or exchanged for anything of value.',
        ];
    }

    /** @return array<string,mixed> */
    private function gameConfiguration(string $slug, bool $requireActive = true): array
    {
        $game = $this->games[$slug] ?? null;
        if (!is_array($game)) {
            throw new DomainException('Unknown game.');
        }
        $stored = $this->database->fetchOne('SELECT active, configuration_json FROM game_definitions WHERE slug = :slug', ['slug' => $slug]);
        if ($stored === null || ($requireActive && (int) $stored['active'] !== 1)) {
            throw new DomainException('This game is not currently available.');
        }
        $game = $this->withStoredWager($game, (string) ($stored['configuration_json'] ?? ''));
        // Only the validated wager envelope is mutable at runtime. Engine,
        // probability, strategy, rules, and payout configuration remain the
        // reviewed code-backed definitions from config/games.php.
        $this->games[$slug] = $game;
        return $game;
    }

    /** @param array<string,mixed> $base @return array<string,mixed> */
    private function withStoredWager(array $base, string $encoded): array
    {
        $stored = json_decode($encoded, true);
        $wager = is_array($stored) && is_array($stored['wager'] ?? null) ? $stored['wager'] : null;
        if ($wager === null) {
            return $base;
        }
        foreach (['minimum', 'maximum', 'increment'] as $key) {
            if (!isset($wager[$key]) || !is_int($wager[$key])) {
                throw new DomainException('The stored game wager configuration is invalid.');
            }
        }
        $minimum = $wager['minimum'];
        $maximum = $wager['maximum'];
        $increment = $wager['increment'];
        $free = $minimum === 0 && $maximum === 0 && $increment === 0;
        $paid = $minimum >= 1 && $maximum >= $minimum && $maximum <= 1_000_000
            && $increment >= 1 && $increment <= $maximum;
        if (!$free && !$paid) {
            throw new DomainException('The stored game wager configuration is outside the safe fictional limits.');
        }
        $base['wager'] = [
            'minimum' => $minimum, 'maximum' => $maximum, 'increment' => $increment,
            'currency' => 'cozy_coins', 'cash_value' => false,
        ];
        $base['wager_increments'] = $increment === 0 ? [0] : array_values(array_unique(array_filter([
            $minimum, $increment, min($maximum, $increment * 5), min($maximum, $increment * 10),
        ], static fn (int $value): bool => $value >= $minimum && $value <= $maximum)));
        if ($base['wager_increments'] === []) {
            $base['wager_increments'] = [$minimum];
        }
        return $base;
    }

    private function assertPlayControls(int $userId, bool $newRound): void
    {
        $row = $this->database->fetchOne('SELECT settings_json FROM user_settings WHERE user_id = :user', ['user' => $userId]);
        $settings = json_decode((string) ($row['settings_json'] ?? '{}'), true);
        $controls = is_array($settings['play_controls'] ?? null) ? $settings['play_controls'] : [];
        $storedControls = $this->database->fetchOne('SELECT session_reminder_minutes, daily_limit_minutes, cooldown_until, self_excluded_until FROM responsible_play_controls WHERE user_id = :user', ['user' => $userId]) ?? [];
        foreach (['self_excluded_until', 'cooldown_until'] as $key) {
            $until = strtotime((string) ($storedControls[$key] ?? $controls[$key] ?? ''));
            if ($until !== false && $until > time()) {
                throw new GameException('Healthy-play controls have paused new game actions until ' . gmdate('Y-m-d H:i:s', $until) . ' UTC.', 'play_paused', 423);
            }
        }
        if (!$newRound) {
            return;
        }
        $limit = max(0, (int) ($controls['daily_round_limit'] ?? 0));
        if ($limit > 0) {
            $today = gmdate('Y-m-d 00:00:00');
            $count = (int) ($this->database->fetchOne('SELECT COUNT(*) AS total FROM game_rounds WHERE user_id = :user AND started_at >= :today', ['user' => $userId, 'today' => $today])['total'] ?? 0);
            if ($count >= $limit) {
                throw new GameException('Your account-specific daily round limit has been reached.', 'daily_limit_reached', 423);
            }
        }
        $dailyMinutes = max(0, (int) ($storedControls['daily_limit_minutes'] ?? $controls['daily_limit_minutes'] ?? 0));
        if ($dailyMinutes > 0) {
            $today = gmdate('Y-m-d 00:00:00');
            $minutes = 0;
            foreach ($this->database->fetchAll('SELECT started_at, settled_at FROM game_rounds WHERE user_id = :user AND started_at >= :today', ['user' => $userId, 'today' => $today]) as $round) {
                $start = strtotime((string) $round['started_at']) ?: time();
                $end = strtotime((string) ($round['settled_at'] ?? $round['started_at'])) ?: $start;
                $minutes += max(1, (int) ceil(max(0, $end - $start) / 60));
            }
            if ($minutes >= $dailyMinutes) {
                throw new GameException('Your account-specific daily game-time limit has been reached.', 'daily_time_limit_reached', 423);
            }
        }
    }

    /** @param array<string,mixed> $input */
    private function idempotencyKey(array $input): string
    {
        $key = (string) ($input['idempotency_key'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $key)) {
            throw new DomainException('A valid idempotency key is required.');
        }
        return $key;
    }

    /** @param array<string,mixed> $input */
    private function optionalRoundId(array $input): ?string
    {
        $round = $input['round_id'] ?? null;
        if ($round === null || $round === '') {
            return null;
        }
        if (!is_string($round) || !preg_match('/^rnd_[a-f0-9]{32}$/', $round)) {
            throw new DomainException('Invalid round ID.');
        }
        return $round;
    }

    /** @param array<string,mixed> $input */
    private function clientSeed(array $input): string
    {
        $seed = trim((string) ($input['client_seed'] ?? 'parlor-' . bin2hex(random_bytes(8))));
        return substr($seed === '' ? 'parlor-player' : $seed, 0, 128);
    }

    /** @param array<string,mixed> $input */
    private function fingerprint(string $slug, array $input): string
    {
        unset($input['_csrf']);
        return hash('sha256', json_encode($this->canonicalize(['slug' => $slug, 'input' => $input]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string,mixed> */
    private function safeInput(array $input): array
    {
        unset($input['_csrf'], $input['server_state'], $input['balance'], $input['payout']);
        return $input;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $json): array
    {
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new DomainException('Stored game data is invalid.');
        }
        return $value;
    }
}
