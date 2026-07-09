<?php

namespace App\Controllers\App\Dashboard;

use App\Utils\Construct\Template;

class InventoryController
{
    public function index(): void
    {
        Template::load('inventory');
    }
}
