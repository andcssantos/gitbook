<?php

namespace App\Controllers\App\Dashboard;

use App\Utils\Construct\{Template};

class NotFoundController
{
    public function index()
    {
        Template::load($_ENV['MOD_ERROR_404']);
    }
}