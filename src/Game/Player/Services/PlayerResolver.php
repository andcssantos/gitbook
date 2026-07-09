<?php

namespace App\Game\Player\Services;

use App\Game\Player\Repositories\PlayerRepository;
use App\Http\HttpException;
use App\Utils\Construct\Auth;

class PlayerResolver
{
    public function __construct(private ?PlayerRepository $players = null)
    {
        $this->players ??= new PlayerRepository();
    }

    public function requireCurrentPlayer(): array
    {
        $user = Auth::user();
        $playerId = is_array($user) ? (int) ($user['player_id'] ?? 0) : 0;
        if ($playerId <= 0) {
            throw new HttpException('Authenticated player not found in session.', 401);
        }

        $player = $this->players->findActiveById($playerId);
        if ($player === null) {
            throw new HttpException('Authenticated player is not active.', 403);
        }

        return $player;
    }
}
