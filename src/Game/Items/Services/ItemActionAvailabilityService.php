<?php

namespace App\Game\Items\Services;

use App\Game\Market\Services\ItemMarketEligibilityService;
use App\Game\Market\Services\MarketItemContextService;
use App\Game\Items\Repositories\ItemActionDefinitionRepository;
use App\Game\Items\Repositories\ItemActionRuleRepository;
use PDO;

class ItemActionAvailabilityService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ItemActionDefinitionRepository $definitions = null,
        private ?ItemActionRuleRepository $rules = null
    ) {
        $this->definitions ??= new ItemActionDefinitionRepository($this->pdo);
        $this->rules ??= new ItemActionRuleRepository($this->pdo);
    }

    public function listForItem(array $item): array
    {
        $actions = [];

        foreach ($this->definitions->listActive() as $definition) {
            if (!$this->isEnabledForItem($definition, $item)) {
                continue;
            }

            $actions[] = [
                'code' => (string) $definition['code'],
                'name' => (string) $definition['name'],
                'description' => $definition['description'] !== null ? (string) $definition['description'] : null,
                'requires_confirmation' => (bool) $definition['requires_confirmation'],
                'is_destructive' => (bool) $definition['is_destructive'],
            ];
        }

        return $actions;
    }

    public function isExecutable(string $actionCode, array $item): bool
    {
        $definition = $this->definitions->findActiveByCode($actionCode);
        if ($definition === null) {
            return false;
        }

        return $this->isEnabledForItem($definition, $item);
    }

    private function isEnabledForItem(array $definition, array $item): bool
    {
        $actionCode = (string) $definition['code'];
        if ($this->isEquipped($item) && !in_array($actionCode, ['INSPECT', 'UNEQUIP'], true)) {
            return false;
        }

        $flags = $this->safetyFlags($item);
        if ($this->isLockProtectedAction($actionCode) && (bool) $flags['locked']) {
            return false;
        }

        if ($actionCode === 'LOCK_ITEM' && (bool) $flags['locked']) {
            return false;
        }

        if ($actionCode === 'UNLOCK_ITEM' && !(bool) $flags['locked']) {
            return false;
        }

        if ($actionCode === 'FAVORITE_ITEM' && (bool) $flags['favorite']) {
            return false;
        }

        if ($actionCode === 'UNFAVORITE_ITEM' && !(bool) $flags['favorite']) {
            return false;
        }

        if ($actionCode === 'WISHLIST_ITEM' && (bool) $flags['wishlist']) {
            return false;
        }

        if ($actionCode === 'UNWISHLIST_ITEM' && !(bool) $flags['wishlist']) {
            return false;
        }

        $rules = $this->rules->listForActionDefinition((int) $definition['id']);
        if ($rules === []) {
            return false;
        }

        foreach ($rules as $rule) {
            if ((int) $rule['enabled'] !== 1) {
                continue;
            }

            if (!$this->matches($rule, $item)) {
                continue;
            }

            if (in_array($actionCode, ['SELL', 'LIST_MARKET'], true) && !$this->marketEligible($actionCode, $item)) {
                continue;
            }

            if ($actionCode === 'DISMANTLE' && !$this->dismantleEligible($item)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function matches(array $rule, array $item): bool
    {
        $reference = (string) ($rule['reference_code'] ?? '');

        return match ((string) $rule['rule_type']) {
            'ALL_ITEMS' => true,
            'ITEM_CATEGORY' => (string) ($item['category_code'] ?? '') === $reference,
            'ITEM_DEFINITION' => (string) ($item['definition_code'] ?? '') === $reference,
            'IS_CONTAINER' => (int) ($item['is_container'] ?? 0) === 1,
            'HAS_EQUIP_SLOT' => trim((string) ($item['equip_slot_code'] ?? '')) !== '',
            'IS_EQUIPPED' => $this->isEquipped($item),
            default => false,
        };
    }

    private function isEquipped(array $item): bool
    {
        if (!isset($item['id'], $item['owner_player_id'])) {
            return false;
        }

        $stmt = $this->pdo()->prepare('SELECT item_instance_id FROM player_equipment WHERE player_id = :player_id AND item_instance_id = :item_instance_id LIMIT 1');
        $stmt->execute([
            'player_id' => (int) $item['owner_player_id'],
            'item_instance_id' => (int) $item['id'],
        ]);

        return $stmt->fetchColumn() !== false;
    }

    private function isLockProtectedAction(string $actionCode): bool
    {
        return in_array($actionCode, ['DISCARD', 'SELL', 'LIST_MARKET', 'DISMANTLE'], true);
    }

    private function safetyFlags(array $item): array
    {
        $playerId = (int) ($item['owner_player_id'] ?? 0);
        $itemInstanceId = (int) ($item['id'] ?? $item['item_instance_id'] ?? 0);
        if ($playerId <= 0 || $itemInstanceId <= 0) {
            return ['locked' => false, 'favorite' => false, 'wishlist' => false];
        }

        return (new ItemSafetyService($this->pdo()))->flagsForItem($playerId, $itemInstanceId);
    }

    private function marketEligible(string $actionCode, array $item): bool
    {
        $contextItem = $this->marketContextItem($item);
        if ($contextItem === null) {
            return false;
        }

        $eligibility = new ItemMarketEligibilityService($this->pdo());

        return $actionCode === 'SELL'
            ? $eligibility->canSellNpc($contextItem)
            : $eligibility->canListOnMarket($contextItem);
    }

    private function dismantleEligible(array $item): bool
    {
        $contextItem = $this->marketContextItem($item);

        return $contextItem !== null
            && (new \App\Game\Materials\Services\DismantleService($this->pdo()))->canDismantle($contextItem);
    }

    private function marketContextItem(array $item): ?array
    {
        $publicId = (string) ($item['public_id'] ?? '');
        $playerId = (int) ($item['owner_player_id'] ?? 0);
        if ($publicId === '' || $playerId <= 0) {
            return null;
        }

        return (new MarketItemContextService($this->pdo()))->forOwnedItem($playerId, $publicId);
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? \App\Support\DB::pdo();
    }
}
