<?php
declare(strict_types=1);

namespace westonhancock\editormcp\tools\impl;

use Craft;
use craft\elements\Entry;
use westonhancock\editormcp\Plugin;
use westonhancock\editormcp\tools\Tool;
use westonhancock\editormcp\tools\ToolException;

class CreateEntryTool implements Tool
{
    public function name(): string { return 'create_entry'; }

    public function description(): string
    {
        return 'Create a new entry in a section. status defaults to "draft"; passing any other status requires the content:publish scope.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'section' => ['type' => 'string'],
                'type' => ['type' => 'string', 'description' => 'Entry type handle (defaults to section\'s first type)'],
                'title' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'fields' => ['type' => 'object', 'description' => 'Custom field values keyed by handle'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'live', 'pending', 'disabled'], 'default' => 'draft'],
                'siteId' => ['type' => 'integer'],
            ],
            'required' => ['section'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $args): array
    {
        $sectionHandle = $args['section'] ?? null;
        if (!is_string($sectionHandle) || $sectionHandle === '') {
            throw new ToolException(-32602, 'section is required');
        }
        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
        if (!$section) {
            throw new ToolException(-32008, "Section not found: $sectionHandle");
        }

        $status = $args['status'] ?? 'draft';
        if ($status !== 'draft') {
            $scopes = Plugin::$plugin->toolRegistry->currentTokenClaims['scopes'] ?? [];
            if (!in_array('content:publish', $scopes, true)) {
                throw new ToolException(-32004, 'content:publish required to create entries in non-draft status');
            }
        }

        $typeHandle = $args['type'] ?? null;
        $entryTypes = $section->getEntryTypes();
        $entryType = null;
        if ($typeHandle) {
            foreach ($entryTypes as $t) {
                if ($t->handle === $typeHandle) { $entryType = $t; break; }
            }
            if (!$entryType) {
                throw new ToolException(-32602, "Entry type not found: $typeHandle");
            }
        } else {
            $entryType = $entryTypes[0] ?? null;
            if (!$entryType) {
                throw new ToolException(-32008, 'Section has no entry types');
            }
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can("createEntries:$section->uid")) {
            throw new ToolException(-32004, 'No permission to create entries in this section');
        }

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->authorId = $user->id;
        if (isset($args['siteId'])) {
            $entry->siteId = (int) $args['siteId'];
        }
        if (isset($args['title'])) {
            $entry->title = (string) $args['title'];
        }
        if (isset($args['slug'])) {
            $entry->slug = (string) $args['slug'];
        }

        if (isset($args['fields']) && is_array($args['fields'])) {
            $this->validateFieldHandles($entry, $args['fields']);
            $entry->setFieldValues($args['fields']);
        }

        if ($status === 'draft') {
            $entry->setScenario(Entry::SCENARIO_ESSENTIALS);
            // Save as draft
            if (!Craft::$app->getDrafts()->saveElementAsDraft($entry, $user->id)) {
                throw new ToolException(-32016, 'Failed to save draft', ['errors' => $entry->getErrors()]);
            }
        } else {
            $entry->enabled = $status !== 'disabled';
            if (!Craft::$app->getElements()->saveElement($entry)) {
                throw new ToolException(-32016, 'Failed to save entry', ['errors' => $entry->getErrors()]);
            }
        }

        return [
            'id' => $entry->id,
            'status' => $entry->getStatus(),
            'isDraft' => $entry->getIsDraft(),
            'title' => $entry->title,
            'slug' => $entry->slug,
        ];
    }

    private function validateFieldHandles(Entry $entry, array $fields): void
    {
        if (isset($fields['status']) || isset($fields['enabled']) || isset($fields['postDate']) || isset($fields['expiryDate'])) {
            throw new ToolException(-32016, 'status, enabled, postDate, and expiryDate must not be passed in fields. Use the status param or set_entry_status instead.');
        }
        $valid = [];
        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $f) {
            $valid[$f->handle] = true;
        }
        foreach (array_keys($fields) as $handle) {
            if (!isset($valid[$handle])) {
                throw new ToolException(-32016, "Unknown field handle for this entry type: $handle");
            }
        }
    }
}
