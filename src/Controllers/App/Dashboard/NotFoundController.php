<?php

namespace App\Controllers\App\Dashboard;

use App\Utils\Construct\{Template};

class NotFoundController
{
    public function index()
    {
        http_response_code(404);
        Template::load($_ENV['MOD_ERROR_404']);
    }
}
