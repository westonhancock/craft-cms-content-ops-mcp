<?php

declare(strict_types=1);

namespace westonhancock\editormcp\tools\impl;

use Craft;
use westonhancock\editormcp\tools\Tool;
use westonhancock\editormcp\tools\ToolException;

class ListSectionFieldsTool implements Tool
{
    public function name(): string
    {
        return 'list_section_fields';
    }

    public function description(): string
    {
        return 'List the fields configured on each entry type in a section. Use this to discover what fields update_entry_fields can target.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'section' => ['type' => 'string', 'description' => 'Section handle'],
            ],
            'required' => ['section'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $args): array
    {
        $handle = $args['section'] ?? '';
        if (!is_string($handle) || $handle === '') {
            throw new ToolException(-32602, 'section is required');
        }
        $section = Craft::$app->getEntries()->getSectionByHandle($handle);
        if (!$section) {
            throw new ToolException(-32008, "Section not found: $handle");
        }
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can("viewEntries:$section->uid")) {
            throw new ToolException(-32004, 'No permission to view this section');
        }
        $types = [];
        foreach ($section->getEntryTypes() as $type) {
            $fields = [];
            foreach ($type->getFieldLayout()->getCustomFields() as $field) {
                $fields[] = [
                    'handle' => $field->handle,
                    'name' => $field->name,
                    'type' => $field::displayName(),
                    'instructions' => $field->instructions,
                    'required' => (bool) $field->required,
                ];
            }
            $types[] = [
                'handle' => $type->handle,
                'name' => $type->name,
                'fields' => $fields,
            ];
        }
        return [
            'section' => $section->handle,
            'entryTypes' => $types,
        ];
    }
}
