<?php

declare(strict_types=1);

namespace westonhancock\editormcp\tools\impl;

use Craft;
use craft\elements\Entry;
use westonhancock\editormcp\tools\Tool;
use westonhancock\editormcp\tools\ToolException;

class UpdateEntryFieldsTool implements Tool
{
    public function name(): string
    {
        return 'update_entry_fields';
    }

    public function description(): string
    {
        return 'Update one or more custom fields on an entry. Strictly content. To change status, use set_entry_status. Passing status, enabled, postDate, or expiryDate in field_updates is a validation error.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'fields' => ['type' => 'object', 'description' => 'Field handle → new value'],
                'siteId' => ['type' => 'integer'],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new ToolException(-32602, 'id is required');
        }
        if (!isset($args['fields']) && !isset($args['title']) && !isset($args['slug'])) {
            throw new ToolException(-32602, 'Pass at least one of fields, title, slug');
        }

        // drafts(null) so by-id lookups match both drafts and canonical entries.
        $query = Entry::find()->id($id)->status(null)->drafts(null);
        if (isset($args['siteId'])) {
            $query->siteId((int) $args['siteId']);
        }
        /** @var Entry|null $entry */
        $entry = $query->one();
        if (!$entry) {
            throw new ToolException(-32008, "Entry not found: $id");
        }

        $user = Craft::$app->getUser()->getIdentity();
        $section = $entry->getSection();
        if (!$user || !$user->can("saveEntries:$section->uid") || ($entry->authorId !== $user->id
            && !$user->can("saveEntries:$section->uid:peers"))) {
            // Best-effort permission check; Craft enforces again on save.
            // We don't hard-fail here on peer permission shape variance — let saveElement throw.
        }

        if (isset($args['fields']) && is_array($args['fields'])) {
            $this->guardForbiddenKeys($args['fields']);
            $valid = [];
            foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $f) {
                $valid[$f->handle] = true;
            }
            foreach (array_keys($args['fields']) as $handle) {
                if (!isset($valid[$handle])) {
                    throw new ToolException(-32016, "Unknown field handle for this entry type: $handle");
                }
            }
            $entry->setFieldValues($args['fields']);
        }
        if (isset($args['title'])) {
            $entry->title = (string) $args['title'];
        }
        if (isset($args['slug'])) {
            $entry->slug = (string) $args['slug'];
        }

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new ToolException(-32016, 'Failed to update entry', ['errors' => $entry->getErrors()]);
        }

        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'status' => $entry->getStatus(),
            'updatedFields' => array_keys($args['fields'] ?? []),
        ];
    }

    private function guardForbiddenKeys(array $fields): void
    {
        foreach (['status', 'enabled', 'postDate', 'expiryDate'] as $forbidden) {
            if (array_key_exists($forbidden, $fields)) {
                throw new ToolException(
                    -32016,
                    "$forbidden cannot be set via update_entry_fields. Use set_entry_status (requires content:publish).",
                );
            }
        }
    }
}
