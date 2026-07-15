<?php

declare(strict_types=1);

namespace PurpleParlor\Games;

use PurpleParlor\Games\Contracts\GameInterface;
use PurpleParlor\Games\Contracts\RandomSource;
use PurpleParlor\Games\Random\CryptoRandomSource;

final class GameRegistry
{
    /** @var array<string, GameInterface> */
    private array $games = [];

    /** @param array<int|string, array<string, mixed>> $configuration */
    public function __construct(array $configuration, ?RandomSource $random = null)
    {
        $random ??= new CryptoRandomSource();
        foreach ($configuration as $key => $gameConfiguration) {
            if (!isset($gameConfiguration['slug']) && is_string($key)) $gameConfiguration['slug'] = $key;
            $game = new CatalogGame($gameConfiguration, $random);
            if (isset($this->games[$game->getSlug()])) throw new \InvalidArgumentException('Duplicate game slug: ' . $game->getSlug());
            $this->games[$game->getSlug()] = $game;
        }
        ksort($this->games, SORT_STRING);
    }

    public static function fromConfigFile(string $path, ?RandomSource $random = null): self
    {
        $configuration = require $path;
        if (!is_array($configuration)) throw new \RuntimeException('Game configuration file must return an array.');
        return new self($configuration, $random);
    }

    public function get(string $slug): GameInterface
    {
        if (!isset($this->games[$slug])) throw new \OutOfBoundsException('Unknown game: ' . $slug);
        return $this->games[$slug];
    }

    public function has(string $slug): bool { return isset($this->games[$slug]); }

    /** @return array<string, GameInterface> */ public function all(): array { return $this->games; }

    /** @return list<array<string, mixed>> */
    public function publicCatalog(): array
    {
        return array_values(array_map(static function (GameInterface $game): array {
            $config = $game->getConfiguration();
            return [
                'slug' => $game->getSlug(), 'name' => $config['name'], 'category' => $config['category'],
                'description' => $config['description'], 'wager' => $config['wager'], 'theoreticalRtp' => $config['theoretical_rtp'],
                'renderer' => $config['client_renderer'], 'settings' => $config['settings'], 'rulesUrl' => '/games/' . $game->getSlug() . '/rules',
            ];
        }, $this->games));
    }
}
