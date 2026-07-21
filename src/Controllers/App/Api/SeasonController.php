<?php

namespace App\Controllers\App\Api;

use App\Core\Controller;
use App\Game\Seasons\Services\SeasonUnlockService;

class SeasonController extends Controller
{
    public function active(array $params = []): void
    {
        try {
            $seasons = (new SeasonUnlockService())->listActiveSeasons();
            $this->success([
                'seasons' => $seasons,
            ], 'Active seasons.');
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }
}
