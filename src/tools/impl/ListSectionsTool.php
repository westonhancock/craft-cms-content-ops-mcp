<?php

declare(strict_types=1);

namespace westonhancock\editormcp\tools\impl;

use Craft;
use westonhancock\editormcp\tools\Tool;

class ListSectionsTool implements Tool
{
    public function name(): string
    {
        return 'list_sections';
    }

    public function description(): string
    {
        return 'List entry sections the current user can view. Returns handle, name, type, and the entry types each section supports.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
    }

    public function execute(array $args): array
    {
        $user = Craft::$app->getUser()->getIdentity();
        $sections = Craft::$app->getEntries()->getAllSections();
        $out = [];
        foreach ($sections as $section) {
            // Per-section permission check
            if (!$user || !$user->can("viewEntries:$section->uid")) {
                continue;
            }
            $entryTypes = [];
            foreach ($section->getEntryTypes() as $type) {
                $entryTypes[] = ['handle' => $type->handle, 'name' => $type->name];
            }
            $out[] = [
                'handle' => $section->handle,
                'name' => $section->name,
                'type' => $section->type,
                'entryTypes' => $entryTypes,
            ];
        }
        return ['sections' => $out];
    }
}
