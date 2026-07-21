<?php

namespace App\Game\Market\Services;

use App\Support\DB;
use App\Utils\Config;
use PDO;

class MarketPriceService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function quote(array $item): array
    {
        $upgradeLevel = $this->upgradeLevel($item);
        $upgradeTier = (int) floor($upgradeLevel / 5);
        $categoryCode = (string) ($item['category_code'] ?? $item['definition']['category_code'] ?? 'material');
        $qualityBucket = strtolower((string) ($item['quality_bucket'] ?? 'common'));
        $profileKey = $this->profileKey($item);

        $basePrice = $this->basePrice($categoryCode, $qualityBucket, $upgradeTier);
        $affixScore = $this->affixScore($item);
        $gemScore = $this->gemScore($item);
        $upgradeBonus = 1 + ($upgradeLevel * (float) Config::get('market.pricing.upgrade_bonus_per_level', 0.02));
        $supplyFactor = $this->supplyFactor($profileKey);
        $demandFactor = $this->demandFactor($item);

        $marketValue = (int) round(max(
            (int) Config::get('market.pricing.min_final_price', 1),
            $basePrice * $affixScore * $gemScore * $upgradeBonus * $supplyFactor * $demandFactor
        ));

        $npcRate = $this->npcSellRate($categoryCode, $qualityBucket);
        $npcValue = max(1, (int) floor($marketValue * $npcRate));

        $breakdown = [
            'base_price' => $basePrice,
            'affix_score' => round($affixScore, 4),
            'gem_score' => round($gemScore, 4),
            'upgrade_bonus' => round($upgradeBonus, 4),
            'supply_factor' => round($supplyFactor, 4),
            'demand_factor' => round($demandFactor, 4),
            'npc_rate' => round($npcRate, 4),
            'profile_key' => $profileKey,
            'upgrade_level' => $upgradeLevel,
        ];

        $suggestedPremium = $this->suggestedPremium($marketValue);
        $listingBounds = $this->listingPriceBounds($suggestedPremium);

        return [
            'profile_key' => $profileKey,
            'market_value' => $marketValue,
            'npc_value' => $npcValue,
            'suggested_premium' => $listingBounds['suggested_premium'],
            'listing_price_min' => $listingBounds['min_premium'],
            'listing_price_max' => $listingBounds['max_premium'],
            'npc_rate' => $npcRate,
            'breakdown' => $breakdown,
        ];
    }

    public function suggestedPremium(int $marketValue): int
    {
        $ratio = max(1, (int) Config::get('market.pricing.gold_to_premium_ratio', 8));

        return max(1, (int) round($marketValue / $ratio));
    }

    public function listingPriceBounds(int $suggestedPremium): array
    {
        $globalMin = (int) Config::get('market.listing.min_price_premium', 1);
        $globalMax = (int) Config::get('market.listing.max_price_premium', 999999);
        $minFactor = (float) Config::get('market.listing.min_price_factor', 0.5);
        $maxFactor = (float) Config::get('market.listing.max_price_factor', 2.0);

        $suggestedPremium = max($globalMin, $suggestedPremium);
        $minPremium = max($globalMin, (int) floor($suggestedPremium * $minFactor));
        $maxPremium = min($globalMax, max($minPremium, (int) ceil($suggestedPremium * $maxFactor)));
        $clampedSuggested = max($minPremium, min($maxPremium, $suggestedPremium));

        return [
            'min_premium' => $minPremium,
            'max_premium' => $maxPremium,
            'suggested_premium' => $clampedSuggested,
        ];
    }

    public function profileKey(array $item): string
    {
        $affixes = [];
        foreach ($item['affixes'] ?? [] as $affix) {
            $affixes[] = (string) ($affix['code'] ?? '') . ':' . round((float) ($affix['value'] ?? 0), 1);
        }
        sort($affixes);

        $gems = [];
        foreach ($item['sockets'] ?? [] as $socket) {
            if (!is_array($socket)) {
                continue;
            }
            if (!empty($socket['gem_item_instance_id'])) {
                $gems[] = (string) $socket['gem_item_instance_id'];
                continue;
            }
            $gemPublicId = (string) ($socket['gem']['public_id'] ?? '');
            if ($gemPublicId !== '') {
                $gems[] = $gemPublicId;
            }
        }
        sort($gems);

        $parts = [
            (string) ($item['definition_code'] ?? $item['definition']['code'] ?? ''),
            strtolower((string) ($item['quality_bucket'] ?? 'common')),
            (string) $this->upgradeLevel($item),
            implode(',', $affixes),
            implode(',', $gems),
            (string) round((float) ($item['quality_value'] ?? 0), 1),
        ];

        return substr(hash('sha256', implode('|', $parts)), 0, 32);
    }

    public function recordHistory(int $itemInstanceId, array $quote): void
    {
        if (!$this->tableExists('market_price_history')) {
            return;
        }

        $stmt = $this->pdo()->prepare('INSERT INTO market_price_history (item_instance_id, profile_key, market_value, npc_value, breakdown_json) VALUES (:item_instance_id, :profile_key, :market_value, :npc_value, :breakdown_json)');
        $stmt->execute([
            'item_instance_id' => $itemInstanceId,
            'profile_key' => (string) $quote['profile_key'],
            'market_value' => (int) $quote['market_value'],
            'npc_value' => (int) $quote['npc_value'],
            'breakdown_json' => json_encode($quote['breakdown'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    private function basePrice(string $categoryCode, string $qualityBucket, int $upgradeTier): int
    {
        if ($this->tableExists('market_base_prices')) {
            $stmt = $this->pdo()->prepare("SELECT base_price FROM market_base_prices WHERE category_code = :category_code AND quality_bucket = :quality_bucket AND upgrade_tier = :upgrade_tier AND status = 'active' LIMIT 1");
            $stmt->execute([
                'category_code' => $categoryCode,
                'quality_bucket' => $qualityBucket,
                'upgrade_tier' => $upgradeTier,
            ]);
            $value = $stmt->fetchColumn();
            if ($value !== false) {
                return max(1, (int) $value);
            }
        }

        $rarityAnchor = [
            'common' => 12,
            'uncommon' => 18,
            'magic' => 28,
            'rare' => 45,
            'epic' => 72,
            'legendary' => 110,
            'unique' => 140,
            'divine' => 180,
        ];

        $categoryMultiplier = match ($categoryCode) {
            'weapon', 'armor' => 1.35,
            'container', 'tool' => 1.1,
            'consumable', 'material' => 0.85,
            default => 1.0,
        };

        $anchor = $rarityAnchor[$qualityBucket] ?? 12;

        return max(1, (int) round($anchor * $categoryMultiplier * (1 + ($upgradeTier * 0.15))));
    }

    private function affixScore(array $item): float
    {
        $affixes = $item['affixes'] ?? [];
        if ($affixes === []) {
            return 1.0;
        }

        $score = 1.0;
        foreach ($affixes as $affix) {
            $code = (string) ($affix['code'] ?? '');
            $tierWeight = $this->affixDemandWeight($code);
            $valueWeight = max(0.05, (float) ($affix['rarity_weight'] ?? 1) / 100);
            $score += $tierWeight * $valueWeight;
        }

        return max(1.0, $score);
    }

    private function gemScore(array $item): float
    {
        $socketed = 0;
        foreach ($item['sockets'] ?? [] as $socket) {
            if (!is_array($socket)) {
                continue;
            }
            if (!empty($socket['gem_item_instance_id'])) {
                $socketed++;
                continue;
            }
            // InventoryStateService expõe gema como objeto `gem`, nao como id interno.
            if (!empty($socket['gem']) && is_array($socket['gem'])) {
                $socketed++;
            }
        }

        if ($socketed === 0) {
            return 1.0;
        }

        return 1.0 + ($socketed * 0.12);
    }

    private function supplyFactor(string $profileKey): float
    {
        if (!$this->tableExists('market_listings')) {
            return 1.0;
        }

        $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM market_listings WHERE profile_key = :profile_key AND status = 'active'");
        $stmt->execute(['profile_key' => $profileKey]);
        $similar = (int) $stmt->fetchColumn();

        if ($this->tableExists('market_supply_demand')) {
            if ($this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $update = $this->pdo()->prepare('INSERT INTO market_supply_demand (profile_key, similar_listings_count, demand_factor) VALUES (:profile_key, :similar_listings_count, 1.0)
                    ON CONFLICT(profile_key) DO UPDATE SET similar_listings_count = excluded.similar_listings_count');
            } else {
                $update = $this->pdo()->prepare('INSERT INTO market_supply_demand (profile_key, similar_listings_count, demand_factor) VALUES (:profile_key, :similar_listings_count, 1.0)
                    ON DUPLICATE KEY UPDATE similar_listings_count = VALUES(similar_listings_count)');
            }
            $update->execute([
                'profile_key' => $profileKey,
                'similar_listings_count' => $similar,
            ]);
        }

        return max(0.35, 1 / log($similar + 2));
    }

    private function demandFactor(array $item): float
    {
        $profileKey = $this->profileKey($item);
        $profileDemand = $this->profileDemandFactor($profileKey);
        $affixDemand = $this->affixDemandFactor($item);

        return max(0.75, min(1.8, ($profileDemand + $affixDemand) / 2));
    }

    private function profileDemandFactor(string $profileKey): float
    {
        if ($profileKey === '' || !$this->tableExists('market_supply_demand')) {
            return (float) Config::get('market.pricing.default_demand_factor', 1.0);
        }

        $stmt = $this->pdo()->prepare('SELECT demand_factor FROM market_supply_demand WHERE profile_key = :profile_key LIMIT 1');
        $stmt->execute(['profile_key' => $profileKey]);
        $value = $stmt->fetchColumn();

        if ($value === false) {
            return (float) Config::get('market.pricing.default_demand_factor', 1.0);
        }

        return max(0.75, min(1.8, (float) $value));
    }

    private function affixDemandFactor(array $item): float
    {
        $affixes = $item['affixes'] ?? [];
        if ($affixes === []) {
            return (float) Config::get('market.pricing.default_demand_factor', 1.0);
        }

        $total = 0.0;
        $count = 0;
        foreach ($affixes as $affix) {
            $total += $this->affixDemandWeight((string) ($affix['code'] ?? ''));
            $count++;
        }

        return max(0.75, min(1.8, $total / max(1, $count)));
    }

    private function affixDemandWeight(string $affixCode): float
    {
        if ($affixCode === '' || !$this->tableExists('affix_demand_weights')) {
            return 1.0;
        }

        $stmt = $this->pdo()->prepare("SELECT demand_weight FROM affix_demand_weights WHERE affix_code = :affix_code AND status = 'active' LIMIT 1");
        $stmt->execute(['affix_code' => $affixCode]);
        $value = $stmt->fetchColumn();

        return $value !== false ? max(0.1, (float) $value) : 1.0;
    }

    private function npcSellRate(string $categoryCode, string $qualityBucket): float
    {
        $rates = (array) Config::get('market.npc_sell.rate_by_category', []);
        $rate = (float) ($rates[$categoryCode] ?? $rates['default'] ?? 0.65);
        $bonus = (array) Config::get('market.npc_sell.rare_bonus', []);
        $rate += (float) ($bonus[$qualityBucket] ?? 0.0);

        return min((float) Config::get('market.npc_sell.max_rate', 0.70), max(0.60, $rate));
    }

    private function upgradeLevel(array $item): int
    {
        foreach ($item['properties'] ?? [] as $property) {
            if ((string) ($property['code'] ?? '') === 'upgrade_level') {
                return max(0, (int) ($property['value'] ?? 0));
            }
        }

        return 0;
    }

    private function tableExists(string $table): bool
    {
        if ($this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo()->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
            $stmt->execute(['table' => $table]);

            return $stmt->fetchColumn() !== false;
        }

        return $this->pdo()->query('SHOW TABLES LIKE ' . $this->pdo()->quote($table))->fetchColumn() !== false;
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
