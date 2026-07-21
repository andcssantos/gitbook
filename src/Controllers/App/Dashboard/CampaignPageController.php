<?php

namespace App\Controllers\App\Dashboard;

use App\Utils\Construct\Template;

class CampaignPageController
{
    public function index(): void
    {
        Template::load('campaign');
    }
}
