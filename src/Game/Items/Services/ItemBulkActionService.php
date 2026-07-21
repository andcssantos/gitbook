<?php

namespace App\Game\Items\Services;

use App\Game\Inventory\InventoryException;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Support\DB;
use App\Validation\ValidationException;
use PDO;
use Throwable;

class ItemBulkActionService
{
    private const MAX_ITEMS = 40;

    private const ALLOWED_ACTIONS = [
        'LOCK_ITEM',
        'UNLOCK_ITEM',
        'FAVORITE_ITEM',
        'UNFAVORITE_ITEM',
        'WISHLIST_ITEM',
        'UNWISHLIST_ITEM',
    ];

    public function __construct(
        private ?PDO $pdo = null,
        private ?ItemActionExecuteService $executor = null
    ) {
        $this->executor ??= new ItemActionExecuteService($this->pdo);
    }

    public function execute(int $playerId, string $actionCode, array $itemPublicIds, bool $confirmed = true): array
    {
        $actionCode = strtoupper(trim($actionCode));
        if (!in_array($actionCode, self::ALLOWED_ACTIONS, true)) {
            throw new InventoryException('ITEM_BULK_ACTION_NOT_ALLOWED', 'Esta acao nao pode ser executada em lote.', 422);
        }

        $itemPublicIds = $this->normalizeItemIds($itemPublicIds);
        $batchId = bin2hex(random_bytes(8));
        $operationId = $this->startOperation($batchId, $playerId, $actionCode, count($itemPublicIds));
        $results = [];
        $succeeded = 0;
        $failed = 0;

        foreach ($itemPublicIds as $itemPublicId) {
            try {
                $result = $this->executor->execute($playerId, $itemPublicId, $actionCode, $confirmed);
                $results[] = [
                    'item_public_id' => $itemPublicId,
                    'success' => true,
                    'status' => 200,
                    'action' => (string) ($result['action'] ?? $actionCode),
                    'result' => $result,
                ];
                $succeeded++;
                $this->recordResult($operationId, $results[array_key_last($results)]);
                $this->recordItemHistory($playerId, $itemPublicId, $batchId, $actionCode, true, $result);
            } catch (InventoryException $e) {
                $results[] = [
                    'item_public_id' => $itemPublicId,
                    'success' => false,
                    'status' => $e->status(),
                    'code' => $e->errorCode(),
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ];
                $failed++;
                $this->recordResult($operationId, $results[array_key_last($results)]);
                $this->recordItemHistory($playerId, $itemPublicId, $batchId, $actionCode, false, [
                    'code' => $e->errorCode(),
                    'message' => $e->getMessage(),
                ]);
            } catch (Throwable $e) {
                $results[] = [
                    'item_public_id' => $itemPublicId,
                    'success' => false,
                    'status' => 500,
                    'code' => 'ITEM_BULK_ACTION_FAILED',
                    'message' => 'Nao foi possivel executar a acao neste item.',
                    'errors' => [],
                ];
                $failed++;
                $this->recordResult($operationId, $results[array_key_last($results)]);
                $this->recordItemHistory($playerId, $itemPublicId, $batchId, $actionCode, false, [
                    'code' => 'ITEM_BULK_ACTION_FAILED',
                    'message' => 'Nao foi possivel executar a acao neste item.',
                ]);
            }
        }

        $this->finishOperation($operationId, $succeeded, $failed);

        return [
            'batch_id' => $batchId,
            'action' => $actionCode,
            'requested' => count($itemPublicIds),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    public function findForPlayer(int $playerId, string $batchId): array
    {
        $batchId = strtolower(trim($batchId));
        if ($batchId === '' || !preg_match('/^[a-f0-9]{16,32}$/', $batchId)) {
            throw new InventoryException('ITEM_BULK_OPERATION_INVALID', 'Operacao em lote invalida.', 422);
        }

        if (!$this->tableExists('item_bulk_operations') || !$this->tableExists('item_bulk_operation_results')) {
            throw new InventoryException('ITEM_BULK_OPERATION_NOT_AVAILABLE', 'Historico de operacoes em lote indisponivel.', 503);
        }

        $stmt = $this->pdo()->prepare('SELECT *
            FROM item_bulk_operations
            WHERE player_id = :player_id AND batch_id = :batch_id
            LIMIT 1');
        $stmt->execute([
            'player_id' => $playerId,
            'batch_id' => $batchId,
        ]);
        $operation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($operation)) {
            throw new InventoryException('ITEM_BULK_OPERATION_NOT_FOUND', 'Operacao em lote nao encontrada.', 404);
        }

        $results = $this->operationResults((int) $operation['id']);

        return [
            'batch_id' => (string) $operation['batch_id'],
            'action' => (string) $operation['action_code'],
            'requested' => (int) $operation['requested_count'],
            'succeeded' => (int) $operation['succeeded_count'],
            'failed' => (int) $operation['failed_count'],
            'status' => (string) $operation['status'],
            'created_at' => (string) $operation['created_at'],
            'completed_at' => $operation['completed_at'] !== null ? (string) $operation['completed_at'] : null,
            'results' => $results,
        ];
    }

    private function normalizeItemIds(array $itemPublicIds): array
    {
        $normalized = [];
        foreach ($itemPublicIds as $value) {
            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }

            $publicId = trim((string) $value);
            if ($publicId === '' || mb_strlen($publicId) > 64) {
                continue;
            }

            $normalized[$publicId] = $publicId;
        }

        $ids = array_values($normalized);
        if ($ids === []) {
            throw new ValidationException(['item_public_ids' => ['required']]);
        }

        if (count($ids) > self::MAX_ITEMS) {
            throw new ValidationException(['item_public_ids' => ['max:' . self::MAX_ITEMS]]);
        }

        return $ids;
    }

    private function operationResults(int $operationId): array
    {
        $stmt = $this->pdo()->prepare('SELECT item_public_id, success, status_code, error_code, message, result_json, created_at
            FROM item_bulk_operation_results
            WHERE operation_id = :operation_id
            ORDER BY id ASC');
        $stmt->execute(['operation_id' => $operationId]);

        return array_map(static function (array $row): array {
            $decoded = null;
            if ($row['result_json'] !== null && $row['result_json'] !== '') {
                $value = json_decode((string) $row['result_json'], true);
                $decoded = is_array($value) ? $value : null;
            }

            return [
                'item_public_id' => (string) $row['item_public_id'],
                'success' => (bool) $row['success'],
                'status' => (int) $row['status_code'],
                'code' => $row['error_code'] !== null ? (string) $row['error_code'] : null,
                'message' => $row['message'] !== null ? (string) $row['message'] : null,
                'result' => $decoded,
                'created_at' => (string) $row['created_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function startOperation(string $batchId, int $playerId, string $actionCode, int $requested): ?int
    {
        if (!$this->tableExists('item_bulk_operations')) {
            return null;
        }

        $stmt = $this->pdo()->prepare('INSERT INTO item_bulk_operations (batch_id, player_id, action_code, requested_count, status)
            VALUES (:batch_id, :player_id, :action_code, :requested_count, :status)');
        $stmt->execute([
            'batch_id' => $batchId,
            'player_id' => $playerId,
            'action_code' => $actionCode,
            'requested_count' => $requested,
            'status' => 'running',
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function recordResult(?int $operationId, array $result): void
    {
        if ($operationId === null || !$this->tableExists('item_bulk_operation_results')) {
            return;
        }

        $payload = $result['success']
            ? ($result['result'] ?? null)
            : [
                'errors' => $result['errors'] ?? [],
            ];

        $stmt = $this->pdo()->prepare('INSERT INTO item_bulk_operation_results
            (operation_id, item_public_id, success, status_code, error_code, message, result_json)
            VALUES (:operation_id, :item_public_id, :success, :status_code, :error_code, :message, :result_json)');
        $stmt->execute([
            'operation_id' => $operationId,
            'item_public_id' => (string) ($result['item_public_id'] ?? ''),
            'success' => !empty($result['success']) ? 1 : 0,
            'status_code' => (int) ($result['status'] ?? 200),
            'error_code' => $result['code'] ?? null,
            'message' => $result['message'] ?? null,
            'result_json' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
        ]);
    }

    private function finishOperation(?int $operationId, int $succeeded, int $failed): void
    {
        if ($operationId === null || !$this->tableExists('item_bulk_operations')) {
            return;
        }

        $stmt = $this->pdo()->prepare('UPDATE item_bulk_operations
            SET succeeded_count = :succeeded_count,
                failed_count = :failed_count,
                status = :status,
                completed_at = CURRENT_TIMESTAMP
            WHERE id = :id');
        $stmt->execute([
            'id' => $operationId,
            'succeeded_count' => $succeeded,
            'failed_count' => $failed,
            'status' => $failed > 0 ? ($succeeded > 0 ? 'partial' : 'failed') : 'completed',
        ]);
    }

    private function recordItemHistory(int $playerId, string $itemPublicId, string $batchId, string $actionCode, bool $success, array $result): void
    {
        if (!$this->tableExists('item_history_events')) {
            return;
        }

        $item = (new ItemInstanceRepository($this->pdo()))->findByPublicIdAndOwner($itemPublicId, $playerId);
        if ($item === null) {
            return;
        }

        (new ItemSafetyService($this->pdo()))->record(
            $item,
            $playerId,
            $success ? 'bulk_action_applied' : 'bulk_action_rejected',
            [
                'batch_id' => $batchId,
                'action' => $actionCode,
                'success' => $success,
                'code' => $result['code'] ?? null,
                'message' => $result['message'] ?? null,
            ],
            $batchId . ':' . $itemPublicId . ':' . $actionCode
        );
    }

    private function tableExists(string $table): bool
    {
        if ($this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
            $stmt->execute(['table' => $table]);

            return (bool) $stmt->fetchColumn();
        }

        $stmt = $this->pdo()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
        $stmt->execute(['table' => $table]);

        return (bool) $stmt->fetchColumn();
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
