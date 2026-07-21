<?php

namespace App\Game\Items\Services;

use App\Game\Market\Services\MarketItemContextService;
use App\Game\Market\Services\MarketPriceService;
use App\Game\Materials\Services\DismantleYieldCalculator;
use App\Game\Materials\Services\MaterialCompositionResolver;
use App\Support\DB;
use PDO;

class ItemInvestigationService
{
    public function __construct(
        private ?PDO $connection = null,
        private ?MarketItemContextService $context = null,
        private ?MaterialCompositionResolver $composition = null,
        private ?DismantleYieldCalculator $dismantle = null
    ) {
        $this->context ??= new MarketItemContextService($this->connection);
        $this->composition ??= new MaterialCompositionResolver($this->connection);
        $this->dismantle ??= new DismantleYieldCalculator($this->connection);
    }

    public function investigate(int $playerId, string $itemPublicId): array
    {
        $item = $this->context->forOwnedItem($playerId, $itemPublicId);
        if ($item === null) {
            throw new \App\Game\Inventory\InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Inventory item was not found.', 404);
        }

        $itemInstanceId = (int) ($item['item_instance_id'] ?? 0);
        $itemPublicId = (string) ($item['public_id'] ?? $item['item_public_id'] ?? $itemPublicId);
        $quote = (new MarketPriceService($this->connection))->quote($item);
        $profileKey = (string) ($quote['profile_key'] ?? '');
        $power = (new ItemPowerService())->forItem($item);
        $history = $this->historyForItem($itemInstanceId, $itemPublicId);

        return [
            'item' => $item,
            'power' => $power,
            'description' => $this->descriptionForItem($item),
            'market' => [
                'market_value' => (int) $quote['market_value'],
                'npc_value' => (int) $quote['npc_value'],
                'suggested_premium' => (int) ($quote['suggested_premium'] ?? 0),
                'breakdown' => is_array($quote['breakdown'] ?? null) ? $quote['breakdown'] : [],
                'supply' => $this->supplySnapshot($profileKey),
                'price_history' => $this->priceHistory($itemInstanceId),
            ],
            'dismantle' => [
                'can_dismantle' => (new \App\Game\Materials\Services\DismantleService($this->connection))->canDismantle($item),
                'materials' => $this->dismantle->preview($item),
            ],
            'crafting' => $this->craftingHints($item),
            'history' => $history,
            'history_summary' => $this->historySummary($history),
            'composition' => $this->composition->resolveForItem($item, true),
        ];
    }

    private function descriptionForItem(array $item): ?string
    {
        $stmt = $this->pdo()->prepare('SELECT description FROM item_definitions WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => (string) ($item['definition']['code'] ?? $item['definition_code'] ?? '')]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (string) $value : null;
    }

    private function supplySnapshot(string $profileKey): array
    {
        if ($profileKey === '' || !$this->tableExists('market_supply_demand')) {
            return ['similar_listings' => 0, 'demand_factor' => 1.0, 'demand_label' => 'Estavel'];
        }

        $stmt = $this->pdo()->prepare('SELECT similar_listings_count, demand_factor, recent_sale_count FROM market_supply_demand WHERE profile_key = :profile_key LIMIT 1');
        $stmt->execute(['profile_key' => $profileKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['similar_listings' => 0, 'demand_factor' => 1.0, 'demand_label' => 'Estavel'];
        }

        $demand = (float) ($row['demand_factor'] ?? 1.0);
        $label = $demand >= 1.25 ? 'Alta' : ($demand <= 0.9 ? 'Baixa' : 'Estavel');

        return [
            'similar_listings' => (int) ($row['similar_listings_count'] ?? 0),
            'recent_sales' => (int) ($row['recent_sale_count'] ?? 0),
            'demand_factor' => round($demand, 2),
            'demand_label' => $label,
        ];
    }

    private function priceHistory(int $itemInstanceId): array
    {
        if ($itemInstanceId <= 0 || !$this->tableExists('market_price_history')) {
            return [];
        }

        $stmt = $this->pdo()->prepare('SELECT market_value, npc_value, recorded_at
            FROM market_price_history
            WHERE item_instance_id = :item_instance_id
            ORDER BY recorded_at DESC
            LIMIT 7');
        $stmt->execute(['item_instance_id' => $itemInstanceId]);

        return array_reverse(array_map(fn (array $row): array => [
            'market_value' => (int) $row['market_value'],
            'npc_value' => (int) $row['npc_value'],
            'recorded_at' => (string) $row['recorded_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    private function craftingHints(array $item): array
    {
        $composition = $this->composition->resolveForItem($item, false);
        if ($composition === []) {
            return [];
        }

        $names = array_map(fn (array $row): string => (string) $row['family_name'], $composition);

        return [[
            'type' => 'forge',
            'label' => 'Receita Forja',
            'description' => 'Peca base + ' . implode(' + ', $names),
        ]];
    }

    private function historyForItem(int $itemInstanceId, string $itemPublicId): array
    {
        if ($itemInstanceId <= 0) {
            return [];
        }

        $history = (new ItemSafetyService($this->connection))->historyForItem($itemInstanceId, $itemPublicId, 24);

        if ($this->tableExists('item_upgrade_events')) {
            $stmt = $this->pdo()->prepare('SELECT from_level, to_level, success, cost_currency_code, created_at
                FROM item_upgrade_events
                WHERE item_instance_id = :item_instance_id
                ORDER BY created_at DESC
                LIMIT 12');
            $stmt->execute(['item_instance_id' => $itemInstanceId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ((int) $row['success'] === 1 && (int) $row['to_level'] > (int) $row['from_level']) {
                    $history[] = [
                        'type' => 'bless',
                        'label' => '+'.(int) $row['to_level'].' bless',
                        'metadata' => [
                            'from_level' => (int) $row['from_level'],
                            'to_level' => (int) $row['to_level'],
                            'success' => true,
                            'currency' => (string) $row['cost_currency_code'],
                        ],
                        'created_at' => (string) $row['created_at'],
                    ];
                } else {
                    $history[] = [
                        'type' => 'bless_fail',
                        'label' => 'Falha de bless (+'.(int) $row['from_level'].')',
                        'metadata' => [
                            'from_level' => (int) $row['from_level'],
                            'to_level' => (int) $row['to_level'],
                            'success' => false,
                            'currency' => (string) $row['cost_currency_code'],
                        ],
                        'created_at' => (string) $row['created_at'],
                    ];
                }
            }
        }

        if ($this->tableExists('item_instance_affixes')) {
            $stmt = $this->pdo()->prepare("SELECT iad.name, iia.source, iia.created_at
                FROM item_instance_affixes iia
                INNER JOIN item_affix_definitions iad ON iad.id = iia.affix_definition_id
                WHERE iia.item_instance_id = :item_instance_id AND iia.source = 'chaos_jewel'
                ORDER BY iia.created_at DESC
                LIMIT 5");
            $stmt->execute(['item_instance_id' => $itemInstanceId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $history[] = [
                    'type' => 'chaos',
                    'label' => 'Chaos: '.(string) $row['name'],
                    'metadata' => [
                        'affix' => (string) $row['name'],
                        'source' => (string) $row['source'],
                    ],
                    'created_at' => (string) $row['created_at'],
                ];
            }
        }

        if ($this->tableExists('item_socketed_gems')) {
            $stmt = $this->pdo()->prepare('SELECT gem_def.name, isg.inserted_at
                FROM item_instance_sockets iis
                INNER JOIN item_socketed_gems isg ON isg.socket_id = iis.id
                INNER JOIN item_instances gem ON gem.id = isg.gem_item_instance_id
                INNER JOIN item_definitions gem_def ON gem_def.id = gem.item_definition_id
                WHERE iis.item_instance_id = :item_instance_id
                ORDER BY isg.inserted_at DESC
                LIMIT 5');
            $stmt->execute(['item_instance_id' => $itemInstanceId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $history[] = [
                    'type' => 'socket',
                    'label' => 'Gema encaixada: '.(string) $row['name'],
                    'metadata' => [
                        'gem' => (string) $row['name'],
                    ],
                    'created_at' => (string) $row['inserted_at'],
                ];
            }
        }

        usort($history, fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return array_map(fn (array $event): array => $this->normalizeHistoryEvent($event), array_slice($history, 0, 16));
    }

    private function normalizeHistoryEvent(array $event): array
    {
        $type = (string) ($event['type'] ?? 'unknown');
        $category = $this->historyCategory($type);
        $tone = $this->historyTone($type);

        return [
            'type' => $type,
            'category' => $category,
            'tone' => $tone,
            'label' => (string) ($event['label'] ?? ucfirst(str_replace('_', ' ', $type))),
            'metadata' => is_array($event['metadata'] ?? null) ? $event['metadata'] : null,
            'created_at' => (string) ($event['created_at'] ?? ''),
            'source' => (string) ($event['source'] ?? $category),
        ];
    }

    private function historyCategory(string $type): string
    {
        return match ($type) {
            'locked', 'unlocked', 'favorited', 'unfavorited', 'wishlisted', 'unwishlisted', 'bulk_action_applied', 'bulk_action_rejected' => 'safety',
            'sold_npc', 'listed_market', 'market_cancelled', 'market_bought' => 'economy',
            'dismantled', 'crafted_consumed', 'crafted_created' => 'crafting',
            'bless', 'bless_fail', 'chaos' => 'enhancement',
            'socket', 'unsocket' => 'socketing',
            'discarded' => 'lifecycle',
            default => 'other',
        };
    }

    private function historyTone(string $type): string
    {
        return match ($type) {
            'locked', 'favorited', 'wishlisted', 'bulk_action_applied', 'bless', 'chaos', 'socket' => 'success',
            'unlocked', 'unfavorited', 'unwishlisted', 'bulk_action_rejected', 'bless_fail' => 'warning',
            'discarded', 'sold_npc', 'listed_market', 'dismantled', 'crafted_consumed' => 'danger',
            default => 'info',
        };
    }

    private function historySummary(array $history): array
    {
        $summary = [
            'total' => count($history),
            'categories' => [],
            'latest_at' => null,
        ];

        foreach ($history as $event) {
            $category = (string) ($event['category'] ?? 'other');
            $summary['categories'][$category] = (int) ($summary['categories'][$category] ?? 0) + 1;
            $createdAt = (string) ($event['created_at'] ?? '');
            if ($createdAt !== '' && ($summary['latest_at'] === null || strcmp($createdAt, (string) $summary['latest_at']) > 0)) {
                $summary['latest_at'] = $createdAt;
            }
        }

        return $summary;
    }

    private function tableExists(string $table): bool
    {
        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
            $stmt->execute(['table' => $table]);

            return (bool) $stmt->fetchColumn();
        }

        $stmt = $this->pdo()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
        $stmt->execute(['table' => $table]);

        return (bool) $stmt->fetchColumn();
    }

    private function pdo(): PDO
    {
        return $this->connection ?? DB::pdo();
    }
}
