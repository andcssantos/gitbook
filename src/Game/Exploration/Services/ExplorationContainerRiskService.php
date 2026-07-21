<?php

namespace App\Game\Exploration\Services;

class ExplorationContainerRiskService
{
    /** @return array<string, mixed> */
    public function resolve(
        string $actionCode,
        array $actionConfig,
        int $lockpickingLevel = 1,
        float $trapChanceReduction = 0.0,
        ?float $trapRoll = null,
        ?float $failRoll = null,
        ?int $trapTypeIndex = null
    ): array {
        $risk = is_array($actionConfig['container_risk'] ?? null) ? $actionConfig['container_risk'] : null;
        if ($risk === null) {
            return [
                'applies' => false,
                'trap_triggered' => false,
            ];
        }

        $trapChance = max(0.0, min(1.0, (float) ($risk['trap_chance'] ?? 0)));
        if ($actionCode === 'pick_lock') {
            $trapChance = max(0.0, $trapChance - ($lockpickingLevel * 0.012));
        }
        $trapChance = max(0.0, $trapChance - max(0.0, min(0.5, $trapChanceReduction)));

        $roll = $trapRoll ?? (mt_rand(0, 10000) / 10000);
        if ($roll >= $trapChance) {
            return [
                'applies' => true,
                'trap_triggered' => false,
                'trap_chance' => round($trapChance, 4),
                'roll' => round($roll, 4),
            ];
        }

        $trapTypes = array_values(array_filter(
            (array) ($risk['trap_types'] ?? []),
            static fn (mixed $type): bool => is_string($type) && trim($type) !== ''
        ));
        $trapType = $trapTypes === []
            ? 'generic_trap'
            : $trapTypes[$trapTypeIndex ?? array_rand($trapTypes)];

        $failChance = max(0.0, min(1.0, (float) ($risk['fail_chance_on_trap'] ?? 0)));
        $failCheckRoll = $failRoll ?? (mt_rand(0, 10000) / 10000);
        $failed = $failChance > 0 && $failCheckRoll < $failChance;

        return [
            'applies' => true,
            'trap_triggered' => true,
            'trap_type' => $trapType,
            'trap_chance' => round($trapChance, 4),
            'roll' => round($roll, 4),
            'failed' => $failed,
            'loot_multiplier' => max(0.0, min(1.0, (float) ($risk['loot_multiplier_on_trap'] ?? 0.5))),
            'xp_penalty' => max(0, (int) ($risk['xp_penalty_on_trap'] ?? 0)),
            'message' => $this->trapMessage($trapType, $failed),
        ];
    }

    /** @param array<int, array<string, mixed>> $loot */
    public function applyLootPenalty(array $loot, array $riskOutcome): array
    {
        if (!($riskOutcome['trap_triggered'] ?? false)) {
            return $loot;
        }

        if ($riskOutcome['failed'] ?? false) {
            return [];
        }

        $multiplier = (float) ($riskOutcome['loot_multiplier'] ?? 0.5);
        $adjusted = [];
        foreach ($loot as $entry) {
            $quantity = max(1, (int) ($entry['quantity'] ?? 1));
            $quantity = max(1, (int) floor($quantity * $multiplier));
            $entry['quantity'] = $quantity;
            $entry['trap_adjusted'] = true;
            $adjusted[] = $entry;
        }

        return $adjusted;
    }

    /** @param list<array<string, mixed>> $mitigationSources */
    /** @return array<string, mixed>|null */
    public function summarizeActionRisk(
        string $actionCode,
        array $actionConfig,
        float $trapChanceReduction = 0.0,
        array $mitigationSources = []
    ): ?array {
        $risk = is_array($actionConfig['container_risk'] ?? null) ? $actionConfig['container_risk'] : null;
        if ($risk === null) {
            return null;
        }

        $baseTrapChance = max(0.0, (float) ($risk['trap_chance'] ?? 0));
        $appliedReduction = max(0.0, min(0.5, $trapChanceReduction));
        $trapChance = max(0.0, $baseTrapChance - $appliedReduction);
        $failChance = max(0.0, min(1.0, (float) ($risk['fail_chance_on_trap'] ?? 0)));
        $label = match (true) {
            $trapChance >= 0.3 => 'Risco alto',
            $trapChance >= 0.15 => 'Risco medio',
            default => 'Risco baixo',
        };

        return [
            'base_trap_chance' => round($baseTrapChance, 2),
            'trap_reduction_applied' => round($appliedReduction, 2),
            'trap_chance' => round($trapChance, 2),
            'fail_chance' => round($failChance, 2),
            'label' => $label,
            'is_force_open' => $actionCode === 'force_open',
            'can_fail' => $failChance > 0,
            'mitigation_sources' => $mitigationSources,
            'tooltip' => $this->buildRiskTooltip($baseTrapChance, $appliedReduction, $trapChance, $failChance, $mitigationSources),
        ];
    }

    /** @param list<array<string, mixed>> $mitigationSources */
    private function buildRiskTooltip(
        float $baseTrapChance,
        float $appliedReduction,
        float $trapChance,
        float $failChance,
        array $mitigationSources
    ): string {
        $parts = [
            'Chance base: ' . (int) round($baseTrapChance * 100) . '%',
        ];

        if ($appliedReduction > 0) {
            $sourceLabels = array_values(array_filter(array_map(
                static fn (array $source): string => (string) ($source['label'] ?? ''),
                $mitigationSources
            )));
            $mitigationLabel = $sourceLabels === [] ? 'Mitigacao ativa' : implode(', ', $sourceLabels);
            $parts[] = 'Mitigacao: -' . (int) round($appliedReduction * 100) . '% (' . $mitigationLabel . ')';
        }

        $parts[] = 'Chance efetiva: ' . (int) round($trapChance * 100) . '%';
        if ($failChance > 0) {
            $parts[] = 'Falha total na armadilha: ' . (int) round($failChance * 100) . '%';
        }

        return implode(' · ', $parts);
    }

    private function trapMessage(string $trapType, bool $failed): string
    {
        if ($failed) {
            return match ($trapType) {
                'needle_trap' => 'Uma agulha envenenada disparou e destruiu o conteudo.',
                'snare_trap' => 'Um laço de arame fechou o compartimento antes que voce pudesse pegar algo.',
                default => 'A armadilha impediu a coleta.',
            };
        }

        return match ($trapType) {
            'needle_trap' => 'Uma agulha disparou, mas voce ainda recuperou parte do conteudo.',
            'snare_trap' => 'Um laço de arame rasgou parte do conteudo durante a abertura.',
            default => 'Uma armadilha reduziu a quantidade recuperada.',
        };
    }
}
