<?php

namespace App\Console\Commands;

use App\Game\Market\Services\MarketSupplyDemandRecalculateService;

class MarketRecalculateCommand
{
    public function run(): int
    {
        $result = (new MarketSupplyDemandRecalculateService())->recalculate();

        if (!empty($result['skipped'])) {
            echo "Mercado: tabelas de economia ausentes. Nada a recalcular.\n";

            return 0;
        }

        echo sprintf(
            "Mercado: demanda recalculada para %d perfil(is) (janela de %d dia(s)).\n",
            (int) ($result['profiles_updated'] ?? 0),
            (int) ($result['sale_window_days'] ?? 7)
        );

        return 0;
    }
}
