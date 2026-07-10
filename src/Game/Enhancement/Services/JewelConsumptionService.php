<?php

namespace App\Game\Enhancement\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Items\Repositories\ItemInstanceRepository;

class JewelConsumptionService
{
    public function __construct(
        private ?ContainerRepository $containers = null,
        private ?ItemInstanceRepository $items = null
    ) {
        $this->containers ??= new ContainerRepository();
        $this->items ??= new ItemInstanceRepository();
    }

    public function consume(array $jewel): void
    {
        $this->containers->deletePlacementByItemId((int) $jewel['id']);
        $this->items->deleteById((int) $jewel['id']);
    }
}
