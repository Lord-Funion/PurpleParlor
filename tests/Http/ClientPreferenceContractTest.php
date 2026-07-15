<?php

declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\TestCase;

final class ClientPreferenceContractTest extends TestCase
{
    public function testNewRoundConfirmationRunsBeforeAnyGameRequestAndIsNotDuplicated(): void
    {
        $root = dirname(__DIR__, 2);
        $client = (string) file_get_contents($root . '/public/assets/js/games/game-client.js');
        $parlor = (string) file_get_contents($root . '/public/assets/js/parlor.js');

        $newRoundGuard = strpos($client, '!this.roundId && wager > 0 && this.#wagerConfirmationEnabled()');
        $confirmation = strpos($client, 'window.confirm(`Use ${wager} fictional Cozy Coins for this round?`)');
        $pending = strpos($client, 'this.pending = true;');
        $request = strpos($client, 'await this.api');

        $this->assertTrue($newRoundGuard !== false, 'The client must distinguish a new wagered round from continuation actions.');
        $this->assertTrue($confirmation !== false && $confirmation > $newRoundGuard, 'The wager confirmation must be part of the guarded new-round path.');
        $this->assertTrue($pending !== false && $confirmation < $pending, 'Confirmation must occur before the request becomes pending.');
        $this->assertTrue($request !== false && $confirmation < $request, 'Confirmation must occur before any game API request.');
        $this->assertFalse(str_contains($parlor, "form.matches('[data-wager-form]')"), 'A document-level handler must not create a second wager prompt.');
    }

    public function testAccountPreferencesAreSafelyMappedWhileGuestsKeepLocalStorage(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = (string) file_get_contents($root . '/resources/views/layouts/app.php');
        $parlor = (string) file_get_contents($root . '/public/assets/js/parlor.js');

        $this->assertTrue(str_contains($layout, 'data-user-preferences='), 'Authenticated preference data must be handed to the client.');
        $this->assertTrue(str_contains($layout, 'JSON_HEX_TAG') && str_contains($layout, 'JSON_HEX_QUOT'), 'The JSON handoff must be safe inside an HTML attribute.');
        foreach (['reduced_motion', 'confirm_wagers', 'effects_volume', 'session_reminder_minutes'] as $serverKey) {
            $this->assertTrue(str_contains($parlor, $serverKey), "Server preference {$serverKey} must have an explicit client mapping.");
        }
        $this->assertTrue(str_contains($parlor, 'serverPreferences === null') && str_contains($parlor, '? localPreferences'), 'Guests must continue to use local device preferences when no account payload exists.');
        $this->assertTrue(str_contains($parlor, '{ ...localPreferences, ...serverPreferences }'), 'Authenticated server values must override matching stale local values.');
        $this->assertTrue(str_contains($parlor, 'applyPreferences(preferences, serverPreferences !== null)'), 'Hydrated account values must replace stale local storage before later page loads.');
    }
}
