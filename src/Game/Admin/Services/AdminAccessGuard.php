<?php

namespace App\Game\Admin\Services;

use App\Http\HttpException;
use App\Utils\Config;

/**
 * Gate simples do painel de conteúdo.
 * Liberado se ADMIN_CONTENT_ENABLED=true, ou se APP_ENV não for prod/production.
 */
class AdminAccessGuard
{
    public function assertCanManageContent(): void
    {
        if ($this->canManageContent()) {
            return;
        }

        throw new HttpException('Not found.', 404);
    }

    public function canManageContent(): bool
    {
        if ((bool) Config::get('admin.content_enabled', false)) {
            return true;
        }

        $env = strtolower((string) Config::get('app.env', 'production'));

        return !in_array($env, ['prod', 'production'], true);
    }
}
