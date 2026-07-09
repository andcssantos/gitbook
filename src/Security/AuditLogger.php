<?php

namespace App\Security;

use App\Http\Request;
use App\Support\DB;
use App\Utils\Config;
use PDO;
use Throwable;

class AuditLogger
{
    public function log(string $action, array $context = [], ?string $actor = null): void
    {
        $entry = $this->entry($action, $context, $actor);

        if ((string) Config::get('audit.driver', 'database') === 'database') {
            try {
                $this->writeDatabase($entry);
                return;
            } catch (Throwable $e) {
                error_log('[AuditLogger] database write failed: ' . $e->getMessage());
                if (!filter_var(Config::get('audit.fallback_to_file', true), FILTER_VALIDATE_BOOLEAN)) {
                    return;
                }
            }
        }

        $this->writeFile($entry);
    }

    private function entry(string $action, array $context = [], ?string $actor = null): array
    {
        return [
            'timestamp' => date(DATE_ATOM),
            'action' => $action,
            'actor' => $actor ?? $this->actor(),
            'ip' => Request::ip(),
            'method' => Request::method(),
            'path' => Request::path(),
            'context' => $this->redact($context),
        ];
    }

    private function writeDatabase(array $entry): void
    {
        $table = $this->table();
        $stmt = DB::pdo()->prepare("INSERT INTO {$table} (action, actor, ip, method, path, context_json, created_at) VALUES (:action, :actor, :ip, :method, :path, :context_json, :created_at)");
        $stmt->execute([
            'action' => $entry['action'],
            'actor' => $entry['actor'] ?: null,
            'ip' => $entry['ip'],
            'method' => $entry['method'],
            'path' => $entry['path'],
            'context_json' => json_encode($entry['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function writeFile(array $entry): void
    {
        $path = (string) Config::get('audit.path', __DIR__ . '/../../logs/audit.log');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function table(): string
    {
        $table = (string) Config::get('audit.table', 'game_audit_logs');
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            return 'game_audit_logs';
        }

        return $table;
    }

    private function actor(): ?string
    {
        $user = $_SESSION['user'] ?? null;
        if (!is_array($user)) {
            return null;
        }

        return (string) ($user['id'] ?? $user['sf'] ?? $user['email'] ?? $user['user_email'] ?? '');
    }

    private function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (preg_match('/password|token|secret|key/i', (string) $key)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = $this->redact($value);
            }
        }

        return $context;
    }
}
