<?php

declare(strict_types=1);

namespace westonhancock\editormcp\tools\impl;

use Craft;
use craft\elements\Entry;
use westonhancock\editormcp\tools\Tool;
use westonhancock\editormcp\tools\ToolException;

class DeleteEntryTool implements Tool
{
    public function name(): string
    {
        return 'delete_entry';
    }

    public function description(): string
    {
        return 'Soft-delete an entry. Requires the content:delete scope. The entry can be restored from Craft\'s trash within retention.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
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
        /** @var Entry|null $entry */
        // drafts(null) so by-id lookups match both drafts and canonical entries.
        $entry = Entry::find()->id($id)->status(null)->drafts(null)->one();
        if (!$entry) {
            throw new ToolException(-32008, "Entry not found: $id");
        }
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can("deleteEntries:" . $entry->getSection()->uid)) {
            throw new ToolException(-32004, 'No permission to delete this entry');
        }
        if (!Craft::$app->getElements()->deleteElement($entry)) {
            throw new ToolException(-32016, 'Failed to delete entry');
        }
        return ['id' => $id, 'deleted' => true];
    }
}
