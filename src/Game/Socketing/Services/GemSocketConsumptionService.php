<?php

namespace App\Game\Socketing\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Items\Repositories\ItemInstanceRepository;

class GemSocketConsumptionService
{
    public function __construct(
        private ?ContainerRepository $containers = null,
        private ?ItemInstanceRepository $items = null
    ) {
        $this->containers ??= new ContainerRepository();
        $this->items ??= new ItemInstanceRepository();
    }

    public function consume(array $gem): void
    {
        $this->containers->deletePlacementByItemId((int) $gem['id']);
        $this->items->updateState((int) $gem['id'], 'socketed');
    }
}
