<?php
declare(strict_types=1);

namespace westonhancock\editormcp\tools\impl;

use Craft;
use westonhancock\editormcp\tools\Tool;
use westonhancock\editormcp\tools\ToolException;

class GetGlobalTool implements Tool
{
    public function name(): string { return 'get_global'; }

    public function description(): string
    {
        return 'Get a global set\'s content by handle. Returns all custom field values keyed by handle.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['handle' => ['type' => 'string']],
            'required' => ['handle'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $args): array
    {
        $handle = $args['handle'] ?? '';
        if (!is_string($handle) || $handle === '') {
            throw new ToolException(-32602, 'handle is required');
        }
        $set = Craft::$app->getGlobals()->getSetByHandle($handle);
        if (!$set) {
            throw new ToolException(-32008, "Global set not found: $handle");
        }
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can("editGlobalSet:$set->uid") && !$user->can("viewGlobalSet:$set->uid")) {
            // Craft only has edit perms on globals; we allow either.
            throw new ToolException(-32004, 'No permission to view this global set');
        }
        $fields = [];
        foreach ($set->getFieldLayout()?->getCustomFields() ?? [] as $f) {
            $val = $set->getFieldValue($f->handle);
            $fields[$f->handle] = is_scalar($val) || $val === null ? $val : (string) $val;
        }
        return ['handle' => $set->handle, 'name' => $set->name, 'fields' => $fields];
    }
}
