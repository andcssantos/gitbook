<?php
namespace App\Controllers\App\Dashboard;

use App\Utils\Construct\{Template};

class HomeController
{


    public function index(): void
    {
        Template::load('home');
    }


}
