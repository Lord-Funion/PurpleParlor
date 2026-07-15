<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Core\Request;
use App\Core\Response;
use App\Services\GamePlayService;
use DomainException;
use PurpleParlor\Games\Exceptions\GameException;

final class ApiGameController
{
    public function __construct(private readonly GamePlayService $games, private readonly AuthService $auth)
    {
    }

    public function index(Request $request): Response
    {
        return Response::json([
            'games' => $this->games->catalog(),
            'nextServerSeedHash' => $this->games->precommit($this->auth->currentUser()?->id),
            'serverAuthoritative' => true,
            'currencyNotice' => 'Virtual currency has no cash value and cannot be purchased, transferred, redeemed, withdrawn, or exchanged for anything of value.',
        ]);
    }

    public function round(Request $request): Response
    {
        if (isset($request->body['round_id'])) {
            return Response::json(['error' => 'A new round cannot include an existing round ID.', 'code' => 'invalid_round'], 422);
        }
        return $this->execute($request);
    }

    public function action(Request $request): Response
    {
        if (!is_string($request->body['round_id'] ?? null) || $request->body['round_id'] === '') {
            return Response::json(['error' => 'A round ID is required for this action.', 'code' => 'round_required'], 422);
        }
        return $this->execute($request);
    }

    private function execute(Request $request): Response
    {
        try {
            $payload = $this->games->play($this->auth->currentUser()?->id, (string) $request->attribute('slug'), $request->body);
            return Response::json($payload);
        } catch (GameException $exception) {
            if ($exception->httpStatus >= 500) {
                throw $exception;
            }
            return Response::json(['error' => $exception->getMessage(), 'message' => $exception->getMessage(), 'code' => $exception->errorCode] + $exception->context, $exception->httpStatus);
        } catch (DomainException|\InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage(), 'message' => $exception->getMessage(), 'code' => 'invalid_game_request'], 422);
        }
    }
}
