<?php

namespace App\Controllers\App\Dashboard;

use App\Game\Admin\Services\AdminAccessGuard;
use App\Http\HttpException;
use App\Utils\Construct\Template;

class AdminContentController
{
    public function index(): void
    {
        try {
            (new AdminAccessGuard())->assertCanManageContent();
        } catch (HttpException $e) {
            http_response_code($e->status());
            echo 'Painel admin indisponivel. Defina ADMIN_CONTENT_ENABLED=true no .env.';
            return;
        }

        Template::load('admin-content');
    }
}
