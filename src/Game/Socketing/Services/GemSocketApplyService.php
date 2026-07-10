<?php

namespace App\Game\Socketing\Services;

use App\Game\Items\Repositories\ItemInstancePropertyRepository;
use App\Game\Socketing\Repositories\ItemInstanceSocketRepository;

class GemSocketApplyService
{
    public function __construct(
        private ?ItemInstanceSocketRepository $sockets = null,
        private ?GemSocketCompatibilityService $compatibility = null,
        private ?ItemInstancePropertyRepository $properties = null
    ) {
        $this->sockets ??= new ItemInstanceSocketRepository();
        $this->compatibility ??= new GemSocketCompatibilityService($this->sockets, $this->properties);
        $this->properties ??= new ItemInstancePropertyRepository();
    }

    public function apply(array $gem, array $target): array
    {
        $preview = $this->compatibility->preview($gem, $target);
        if (($preview['can_apply'] ?? false) !== true) {
            return [
                'action' => 'socket',
                'success' => false,
                'reason_code' => $preview['reason_code'] ?? 'SOCKET_INCOMPATIBLE',
                'reason_message' => $preview['reason_message'] ?? 'Incompatible target.',
            ];
        }

        $socket = $this->sockets->findFirstEmpty((int) $target['id'], true);
        if ($socket === null) {
            return [
                'action' => 'socket',
                'success' => false,
                'reason_code' => 'SOCKET_NO_EMPTY_SLOT',
                'reason_message' => 'Este item nao possui engastes vazios.',
            ];
        }

        $effect = $preview['gem_effect'];
        $propertyCode = (string) $effect['property'];
        $propertyValue = (float) $effect['value'];
        $socketIndex = (int) $socket['socket_index'];
        $source = 'socketed_gem_' . $socketIndex;

        $this->sockets->insertSocketedGem((int) $socket['id'], (int) $gem['id']);
        $this->sockets->markFilled((int) $socket['id']);

        $propertyDefinitionId = $this->properties->propertyDefinitionId($propertyCode);
        $this->properties->upsertNumeric(
            (int) $target['id'],
            $propertyDefinitionId,
            $propertyValue,
            $source
        );

        return [
            'action' => 'socket',
            'success' => true,
            'socket_index' => $socketIndex,
            'gem_public_id' => (string) $gem['public_id'],
            'target_public_id' => (string) $target['public_id'],
            'applied_effect' => [
                'property' => $propertyCode,
                'property_name' => (string) ($effect['property_name'] ?? $propertyCode),
                'value' => $propertyValue,
                'source' => $source,
            ],
        ];
    }
}
