<?php

namespace App\Game\Exploration\Services;

use App\Game\Biomes\Repositories\BiomeCatalogRepository;
use PDO;

class ExplorationBiomeCatalogService
{
    /** @var array<string, array<string, mixed>> */
    private const BIOMES = [
        'bosque_inicial' => [
            'code' => 'bosque_inicial',
            'name' => 'Bosque Inicial',
            'summary' => 'Trilhas antigas, flora densa e recursos basicos para novos aventureiros.',
            'status' => 'available',
            'requires_expedition' => true,
            'default_duration_minutes' => 30,
            'default_respawn_minutes' => 15,
            'discovery_radius' => 1.6,
            'map' => [
                'width' => 6,
                'height' => 4,
                'spawn' => ['x' => 1.0, 'y' => 2.0],
            ],
            'map_node' => ['x' => 2, 'y' => 3],
        ],
        'costa_salobra' => [
            'code' => 'costa_salobra',
            'name' => 'Costa Salobra',
            'summary' => 'Costa repleta de sal, destrocos e recursos marinhos para exploradores experientes.',
            'status' => 'locked',
            'requires_expedition' => true,
            'default_duration_minutes' => 45,
            'default_respawn_minutes' => 20,
            'discovery_radius' => 1.5,
            'unlock' => [
                'exploration_level_min' => 2,
                'completed_expeditions_min' => 1,
            ],
            'entry_requirements' => [
                'mode' => 'soft',
                'soft_label' => 'Sem protecao contra veneno/sal',
                'energy_cost_multiplier' => 1.35,
                'hazard_multiplier' => 1.2,
                'any' => [
                    [
                        'type' => 'gear_stat_min',
                        'stat_code' => 'poison_resist',
                        'min' => 1,
                        'label' => 'Resistencia a veneno',
                    ],
                    [
                        'type' => 'item_owned',
                        'item_definition_code' => 'swamp_mask',
                        'quantity' => 1,
                        'label' => 'Mascara do Pantano',
                    ],
                ],
            ],
            'map' => [
                'width' => 6,
                'height' => 4,
                'spawn' => ['x' => 1.0, 'y' => 1.0],
            ],
            'map_node' => ['x' => 5, 'y' => 1],
        ],
        'gruta_ecoante' => [
            'code' => 'gruta_ecoante',
            'name' => 'Gruta Ecoante',
            'summary' => 'Caverna sazonal onde ecos antigos revelam caminhos e artefatos.',
            'status' => 'locked',
            'requires_expedition' => true,
            'default_duration_minutes' => 40,
            'default_respawn_minutes' => 18,
            'discovery_radius' => 1.4,
            'season_featured' => true,
            'map' => [
                'width' => 7,
                'height' => 4,
                'spawn' => ['x' => 1.0, 'y' => 2.0],
            ],
            'map_node' => ['x' => 3, 'y' => 4],
        ],
        'ruinas_afundadas' => [
            'code' => 'ruinas_afundadas',
            'name' => 'Ruinas Afundadas',
            'summary' => 'Ruinas semi-submersas da temporada, repletas de reliquias e perigos ocultos.',
            'status' => 'locked',
            'requires_expedition' => true,
            'default_duration_minutes' => 50,
            'default_respawn_minutes' => 22,
            'discovery_radius' => 1.35,
            'season_featured' => true,
            'map' => [
                'width' => 7,
                'height' => 5,
                'spawn' => ['x' => 1.2, 'y' => 2.5],
            ],
            'map_node' => ['x' => 6, 'y' => 3],
        ],
        'pantano_venenoso' => [
            'code' => 'pantano_venenoso',
            'name' => 'Pantano Venenoso',
            'summary' => 'Miasmas densos. Exige protecao contra veneno para entrar com seguranca.',
            'status' => 'locked',
            'requires_expedition' => true,
            'default_duration_minutes' => 45,
            'default_respawn_minutes' => 25,
            'discovery_radius' => 1.3,
            'unlock' => [
                'exploration_level_min' => 4,
                'completed_expeditions_min' => 3,
            ],
            'entry_requirements' => [
                'mode' => 'hard',
                'any' => [
                    [
                        'type' => 'item_equipped',
                        'item_definition_code' => 'swamp_mask',
                        'label' => 'Mascara do Pantano equipada',
                    ],
                    [
                        'type' => 'gear_stat_min',
                        'stat_code' => 'poison_resist',
                        'min' => 2,
                        'label' => 'Resistencia a veneno 2+',
                    ],
                ],
            ],
            'map' => [
                'width' => 7,
                'height' => 5,
                'spawn' => ['x' => 1.0, 'y' => 2.0],
            ],
            'map_node' => ['x' => 4, 'y' => 5],
        ],
        'vale_dos_reis' => [
            'code' => 'vale_dos_reis',
            'name' => 'Vale dos Reis',
            'summary' => 'Territorio cerimonial. Sem a coroa, os guardas nao deixam passar.',
            'status' => 'locked',
            'requires_expedition' => true,
            'default_duration_minutes' => 55,
            'default_respawn_minutes' => 30,
            'discovery_radius' => 1.4,
            'unlock' => [
                'exploration_level_min' => 5,
                'completed_expeditions_min' => 5,
            ],
            'entry_requirements' => [
                'mode' => 'hard',
                'all' => [
                    [
                        'type' => 'item_equipped',
                        'item_definition_code' => 'crown_of_kings',
                        'label' => 'Coroa dos Reis equipada',
                    ],
                ],
            ],
            'map' => [
                'width' => 8,
                'height' => 5,
                'spawn' => ['x' => 1.5, 'y' => 2.5],
            ],
            'map_node' => ['x' => 7, 'y' => 2],
        ],
    ];

    private BiomeCatalogRepository $repository;
    private bool $preferDatabase;

    public function __construct(?PDO $pdo = null, ?BiomeCatalogRepository $repository = null)
    {
        $this->repository = $repository ?? new BiomeCatalogRepository($pdo);
        $this->preferDatabase = $this->repository->hasTables() && $this->repository->countBiomes() > 0;
    }

    /** @return list<array<string, mixed>> */
    public function listBiomes(): array
    {
        if ($this->preferDatabase) {
            return $this->repository->listExplorationSummaries();
        }

        $biomes = [];
        foreach (self::BIOMES as $biome) {
            $biomes[] = $this->mapBiomeSummary($biome);
        }

        return $biomes;
    }

    /** @return array<string, mixed> */
    public function biome(string $biomeCode): ?array
    {
        $normalized = $this->normalizeBiomeCode($biomeCode);
        if ($this->preferDatabase) {
            $fromDb = $this->repository->getExplorationBiome($normalized);
            if ($fromDb !== null) {
                return $fromDb;
            }
        }

        return isset(self::BIOMES[$normalized]) ? self::BIOMES[$normalized] : null;
    }

    public function isAvailable(string $biomeCode): bool
    {
        $biome = $this->biome($biomeCode);

        return $biome !== null && (string) ($biome['status'] ?? '') === 'available';
    }

    public function discoveryRadius(string $biomeCode): float
    {
        $biome = $this->biome($biomeCode);

        return (float) ($biome['discovery_radius'] ?? 1.5);
    }

    /** @return array{x: float, y: float} */
    public function spawnPosition(string $biomeCode): array
    {
        $biome = $this->biome($biomeCode);
        $spawn = is_array($biome['map']['spawn'] ?? null) ? $biome['map']['spawn'] : ['x' => 0.0, 'y' => 0.0];

        return [
            'x' => (float) ($spawn['x'] ?? 0),
            'y' => (float) ($spawn['y'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    public function mapConfig(string $biomeCode): array
    {
        $biome = $this->biome($biomeCode);
        $map = is_array($biome['map'] ?? null) ? $biome['map'] : [];

        return [
            'width' => max(1, (int) ($map['width'] ?? 6)),
            'height' => max(1, (int) ($map['height'] ?? 4)),
            'spawn' => $this->spawnPosition($biomeCode),
            'discovery_radius' => $this->discoveryRadius($biomeCode),
        ];
    }

    public function normalizeBiomeCode(string $biomeCode): string
    {
        $normalized = strtolower(trim($biomeCode));
        $normalized = preg_replace('/[^a-z0-9_]/', '', $normalized) ?: '';

        return $normalized;
    }

    /** @param array<string, mixed> $biome */
    private function mapBiomeSummary(array $biome): array
    {
        $map = is_array($biome['map'] ?? null) ? $biome['map'] : [];
        $node = is_array($biome['map_node'] ?? null) ? $biome['map_node'] : ['x' => 0, 'y' => 0];

        return [
            'code' => (string) ($biome['code'] ?? ''),
            'name' => (string) ($biome['name'] ?? ''),
            'summary' => (string) ($biome['summary'] ?? ''),
            'status' => (string) ($biome['status'] ?? 'locked'),
            'requires_expedition' => (bool) ($biome['requires_expedition'] ?? false),
            'default_duration_minutes' => (int) ($biome['default_duration_minutes'] ?? 30),
            'map_node' => [
                'x' => (int) ($node['x'] ?? 0),
                'y' => (int) ($node['y'] ?? 0),
            ],
            'map' => [
                'width' => max(1, (int) ($map['width'] ?? 6)),
                'height' => max(1, (int) ($map['height'] ?? 4)),
            ],
            'entry_requirements' => is_array($biome['entry_requirements'] ?? null) ? $biome['entry_requirements'] : null,
        ];
    }
}
