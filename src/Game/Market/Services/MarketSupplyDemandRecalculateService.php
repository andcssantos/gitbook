<?php

namespace App\Game\Market\Services;

use App\Support\DB;
use App\Utils\Config;
use PDO;

class MarketSupplyDemandRecalculateService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function recalculate(): array
    {
        if (!$this->tableExists('market_supply_demand')) {
            return ['profiles_updated' => 0, 'skipped' => true];
        }

        $windowDays = max(1, (int) Config::get('market.recalculate.sale_window_days', 7));
        $demandPerSale = (float) Config::get('market.recalculate.demand_per_sale', 0.05);
        $demandPerListing = (float) Config::get('market.recalculate.demand_per_listing', 0.02);
        $minDemand = (float) Config::get('market.recalculate.min_demand_factor', 0.75);
        $maxDemand = (float) Config::get('market.recalculate.max_demand_factor', 1.8);
        $defaultDemand = (float) Config::get('market.pricing.default_demand_factor', 1.0);

        $listingCounts = $this->listingCountsByProfile();
        $saleCounts = $this->saleCountsByProfile($windowDays);
        $profileKeys = array_values(array_unique(array_merge(array_keys($listingCounts), array_keys($saleCounts))));

        $updated = 0;
        foreach ($profileKeys as $profileKey) {
            $listings = (int) ($listingCounts[$profileKey] ?? 0);
            $sales = (int) ($saleCounts[$profileKey] ?? 0);
            $demandFactor = $defaultDemand + ($sales * $demandPerSale) - ($listings * $demandPerListing);
            $demandFactor = max($minDemand, min($maxDemand, $demandFactor));

            $this->upsertProfile($profileKey, $listings, $sales, $demandFactor);
            $updated++;
        }

        return [
            'profiles_updated' => $updated,
            'sale_window_days' => $windowDays,
            'skipped' => false,
        ];
    }

    private function listingCountsByProfile(): array
    {
        if (!$this->tableExists('market_listings')) {
            return [];
        }

        $stmt = $this->pdo()->query("SELECT profile_key, COUNT(*) AS total
            FROM market_listings
            WHERE status = 'active'
            GROUP BY profile_key");

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['profile_key']] = (int) $row['total'];
        }

        return $counts;
    }

    private function saleCountsByProfile(int $windowDays): array
    {
        if (!$this->tableExists('market_transactions') || !$this->tableExists('market_listings')) {
            return [];
        }

        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sinceExpr = $driver === 'sqlite'
            ? "datetime('now', '-' || :window_days || ' days')"
            : 'DATE_SUB(NOW(), INTERVAL :window_days DAY)';

        $stmt = $this->pdo()->prepare("SELECT ml.profile_key, COUNT(*) AS total
            FROM market_transactions mt
            INNER JOIN market_listings ml ON ml.id = mt.listing_id
            WHERE mt.created_at >= {$sinceExpr}
            GROUP BY ml.profile_key");
        $stmt->bindValue('window_days', $windowDays, PDO::PARAM_INT);
        $stmt->execute();

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['profile_key']] = (int) $row['total'];
        }

        return $counts;
    }

    private function upsertProfile(string $profileKey, int $listings, int $sales, float $demandFactor): void
    {
        if ($this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo()->prepare('INSERT INTO market_supply_demand (profile_key, similar_listings_count, recent_sale_count, demand_factor)
                VALUES (:profile_key, :similar_listings_count, :recent_sale_count, :demand_factor)
                ON CONFLICT(profile_key) DO UPDATE SET
                    similar_listings_count = excluded.similar_listings_count,
                    recent_sale_count = excluded.recent_sale_count,
                    demand_factor = excluded.demand_factor');
        } else {
            $stmt = $this->pdo()->prepare('INSERT INTO market_supply_demand (profile_key, similar_listings_count, recent_sale_count, demand_factor)
                VALUES (:profile_key, :similar_listings_count, :recent_sale_count, :demand_factor)
                ON DUPLICATE KEY UPDATE
                    similar_listings_count = VALUES(similar_listings_count),
                    recent_sale_count = VALUES(recent_sale_count),
                    demand_factor = VALUES(demand_factor)');
        }

        $stmt->execute([
            'profile_key' => $profileKey,
            'similar_listings_count' => $listings,
            'recent_sale_count' => $sales,
            'demand_factor' => round($demandFactor, 4),
        ]);
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
        return $this->pdo ?? DB::pdo();
    }
}
