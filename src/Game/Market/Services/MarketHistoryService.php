<?php

namespace App\Game\Market\Services;

use App\Support\DB;
use PDO;

class MarketHistoryService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function myListings(int $playerId): array
    {
        $stmt = $this->pdo()->prepare("SELECT ml.public_id, ml.profile_key, ml.price_premium, ml.listing_fee_premium,
                ml.status, ml.listed_at, ml.sold_at, ml.cancelled_at, ml.buyer_player_id,
                ii.public_id AS item_public_id, COALESCE(ii.item_name, id.name) AS item_name, id.code AS definition_code,
                ii.quality_bucket
            FROM market_listings ml
            INNER JOIN item_instances ii ON ii.id = ml.item_instance_id
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            WHERE ml.seller_player_id = :player_id
                AND (ml.status = 'active' OR ml.status IN ('cancelled', 'sold'))
            ORDER BY CASE WHEN ml.status = 'active' THEN 0 ELSE 1 END,
                COALESCE(ml.sold_at, ml.cancelled_at, ml.listed_at) DESC");
        $stmt->execute(['player_id' => $playerId]);
        return ['listings' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []];
    }

    public function myTransactions(int $playerId): array
    {
        $stmt = $this->pdo()->prepare('SELECT mt.public_id, mt.price_premium, mt.seller_fee_premium, mt.seller_net_premium, mt.created_at,
                mt.buyer_player_id, mt.seller_player_id, ml.public_id AS listing_public_id,
                ii.public_id AS item_public_id, COALESCE(ii.item_name, id.name) AS item_name, id.code AS definition_code,
                ii.quality_bucket
            FROM market_transactions mt
            INNER JOIN market_listings ml ON ml.id = mt.listing_id
            INNER JOIN item_instances ii ON ii.id = mt.item_instance_id
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            WHERE mt.buyer_player_id = :player_id OR mt.seller_player_id = :player_id
            ORDER BY mt.created_at DESC
            LIMIT 50');
        $stmt->execute(['player_id' => $playerId]);
        return ['transactions' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []];
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
