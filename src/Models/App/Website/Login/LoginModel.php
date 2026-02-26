<?php

namespace App\Models\App\Website\Login;

use App\Core\Model;
use App\Utils\Functions\CacheManager;

class LoginModel extends Model
{
    private CacheManager $cache;

    public function __construct()
    {
        $this->table = 'access';
        $this->cache = new CacheManager('.login', 300);
        parent::__construct();
    }

    public function findAccountByEmail(string $email): array|false
    {

        $cacheKey = "account_email_" . strtolower($email);
        $cachedData = $this->cache->get($cacheKey);

        if ($cachedData !== null) {
            return $cachedData;
        }

        $sql = "SELECT 
                a.id, a.sf, a.password, a.user_email, a.user_phone, 
                a.user_token, a.admin_mode, a.validate, a.block_attempts, a.status, 
                p.username, p.full_name, p.birth_date
            FROM access a 
            JOIN profile p ON a.sf = p.acc_sf 
            WHERE a.user_email = :email
            LIMIT 1
        ";

        $result = $this->fetch($sql, ['email' => $email]);

        if ($result !== false) {
            $this->cache->set($cacheKey, $result);
        }

        return $result;
    }

    public function userLoginHistoric(array $notification = []): void
    {

        $this->setTable('historicals');
        $this->create($notification);
    
    }


    public function resetBlockAttempts(string $email): bool
    {
        $sql = "UPDATE {$this->table} SET block_attempts = 0 WHERE user_email = :email";
        $result = $this->fetch($sql, ['email' => $email]);
        if ($result) {
            $this->cache->delete("account_email_" . strtolower($email));
        }
        return $result;
    }

    public function addBlockAttempt(string $email): bool
    {
        $sql = "UPDATE {$this->table} SET block_attempts = block_attempts + 1 WHERE user_email = :email";
        $result = $this->fetch($sql, ['email' => $email]);
        if ($result) {
            $this->cache->delete("account_email_" . strtolower($email));
        }
        return $result;
    }

    public function blockAccount(string $email): bool
    {
        $sql = "UPDATE {$this->table} SET status = 2 WHERE user_email = :email";
        $result = $this->fetch($sql, ['email' => $email]);
        if ($result) {
            $this->cache->delete("account_email_" . strtolower($email));
        }
        return $result;
    }
}
