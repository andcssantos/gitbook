<?php

namespace App\Game\Campaign\Services;

use App\Support\DB;
use PDO;

/**
 * MVP do vilarejo da campanha: hotspots + flags (ex.: Mapa Rasgado).
 */
class CampaignVillageService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?CampaignProgressService $progress = null,
        private ?CampaignStageRunService $runs = null,
    ) {
        $this->pdo ??= DB::pdo();
        $this->progress ??= new CampaignProgressService($this->pdo);
        $this->runs ??= new CampaignStageRunService($this->pdo);
    }

    /**
     * @return array<string, mixed>
     */
    public function interact(int $playerId, string $nodeCode, string $hotspotCode): array
    {
        $node = $this->runs->nodeByCode($nodeCode);
        if ($node === null || (string) ($node['node_type'] ?? '') !== 'village') {
            throw new \RuntimeException('Vilarejo invalido.');
        }

        $config = [];
        if (is_string($node['config_json'] ?? null) && $node['config_json'] !== '') {
            try {
                $decoded = json_decode((string) $node['config_json'], true, 512, JSON_THROW_ON_ERROR);
                $config = is_array($decoded) ? $decoded : [];
            } catch (\JsonException) {
                $config = [];
            }
        }

        $hotspot = null;
        foreach ((array) ($config['hotspots'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string) ($row['code'] ?? '') === $hotspotCode) {
                $hotspot = $row;
                break;
            }
        }
        if ($hotspot === null) {
            throw new \RuntimeException('Ponto de interesse nao encontrado.');
        }

        $requiresTool = $hotspot['requires_tool'] ?? null;
        $toolNote = is_string($requiresTool) && $requiresTool !== ''
            ? " (precisa de {$requiresTool} — MVP liberado)"
            : '';

        $grantedFlag = (string) ($hotspot['grants_flag'] ?? '');
        $message = (string) ($hotspot['label'] ?? $hotspotCode) . ': voce investigou o local.' . $toolNote;
        $flagsGranted = [];

        if ($grantedFlag !== '') {
            $existing = $this->progress->row($playerId, (int) $node['id']);
            $flags = [];
            if (is_string($existing['flags_json'] ?? null) && $existing['flags_json'] !== '') {
                try {
                    $decoded = json_decode((string) $existing['flags_json'], true, 512, JSON_THROW_ON_ERROR);
                    $flags = is_array($decoded) ? $decoded : [];
                } catch (\JsonException) {
                    $flags = [];
                }
            }
            if (empty($flags[$grantedFlag])) {
                $this->progress->mergeFlags($playerId, (int) $node['id'], [$grantedFlag => true]);
                $flagsGranted[] = $grantedFlag;
                $message = $grantedFlag === 'torn_map'
                    ? 'Voce encontrou o Mapa Rasgado!' . $toolNote
                    : "Flag desbloqueada: {$grantedFlag}" . $toolNote;
            } else {
                $message = 'Voce ja encontrou o que havia aqui.';
            }
        }

        return [
            'node_code' => $nodeCode,
            'hotspot' => $hotspot,
            'message' => $message,
            'flags_granted' => $flagsGranted,
        ];
    }
}
