<?php
namespace App\Controllers\App\Website;

use App\Utils\Construct\{Template};

class HomeController
{

    public function index(): void
    {
        Template::load('home');
    }


}
