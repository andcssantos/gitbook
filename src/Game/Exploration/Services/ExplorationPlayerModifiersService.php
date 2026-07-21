<?php

namespace App\Game\Exploration\Services;

use App\Game\Player\Services\PlayerAttributeService;
use PDO;

class ExplorationPlayerModifiersService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ExplorationPassiveConstellationService $constellations = null,
        private ?ExplorationTrapMitigationService $trapMitigation = null
    ) {
        $this->constellations ??= new ExplorationPassiveConstellationService(
            $this->pdo !== null ? new PlayerAttributeService($this->pdo) : null
        );
        $this->trapMitigation ??= new ExplorationTrapMitigationService($this->pdo);
    }

    /** @return array<string, mixed> */
    public function forPlayer(int $playerId, ?string $biomeCode = null): array
    {
        $activeConstellations = $this->constellations->activeForPlayer($playerId, $biomeCode);
        $constellationEffects = $this->constellations->aggregatedEffects($playerId, $biomeCode);
        $mitigation = $this->trapMitigation->forPlayer($playerId);
        $trapReduction = (float) ($constellationEffects['trap_chance_reduction'] ?? 0)
            + (float) ($mitigation['trap_reduction'] ?? 0);
        $mitigationSources = $this->trapMitigationSources($activeConstellations, $mitigation);

        return [
            'constellations' => $activeConstellations,
            'constellation_catalog' => $this->constellations->catalog(),
            'constellation_loadout' => $this->constellations->loadoutForPlayer($playerId, $biomeCode),
            'trap_mitigation' => $mitigation,
            'trap_mitigation_sources' => $mitigationSources,
            'discovery_radius_bonus' => round((float) ($constellationEffects['discovery_radius_bonus'] ?? 0), 3),
            'trap_chance_reduction' => round(min(0.5, $trapReduction), 3),
            'expedition_loot_bonus' => round((float) ($constellationEffects['expedition_loot_bonus'] ?? 0), 3),
            'combat_bonuses' => [
                'damage_bonus' => round((float) ($constellationEffects['combat_damage_bonus'] ?? 0), 3),
                'crit_bonus' => round((float) ($constellationEffects['combat_crit_bonus'] ?? 0), 3),
                'dodge_bonus' => round((float) ($constellationEffects['combat_dodge_bonus'] ?? 0), 3),
                'reflect_bonus' => round((float) ($constellationEffects['combat_reflect_bonus'] ?? 0), 3),
                'attack_rate_bonus' => round((float) ($constellationEffects['combat_attack_rate_bonus'] ?? 0), 3),
                'damage_reduction' => round(min(0.35, (float) ($constellationEffects['combat_damage_reduction'] ?? 0)), 3),
            ],
        ];
    }

    /** @param list<array<string, mixed>> $activeConstellations */
    /** @return list<array<string, mixed>> */
    private function trapMitigationSources(array $activeConstellations, array $mitigation): array
    {
        $sources = [];
        foreach ($activeConstellations as $constellation) {
            $reduction = (float) (($constellation['effects']['trap_chance_reduction'] ?? 0));
            if ($reduction <= 0) {
                continue;
            }

            $sources[] = [
                'code' => (string) ($constellation['code'] ?? ''),
                'label' => (string) ($constellation['name'] ?? ''),
                'reduction' => round($reduction, 3),
                'source' => 'constellation',
            ];
        }

        if (($mitigation['active'] ?? false)) {
            $sources[] = [
                'code' => (string) ($mitigation['item']['definition_code'] ?? 'equipment'),
                'label' => (string) ($mitigation['item']['name'] ?? 'Equipamento'),
                'reduction' => round((float) ($mitigation['trap_reduction'] ?? 0), 3),
                'source' => 'equipment',
            ];
        }

        return $sources;
    }
}
