<?php

declare(strict_types=1);

namespace westonhancock\editormcp\tools\impl;

use Craft;
use craft\elements\Entry;
use westonhancock\editormcp\tools\Tool;
use westonhancock\editormcp\tools\ToolException;

class SetEntryStatusTool implements Tool
{
    public function name(): string
    {
        return 'set_entry_status';
    }

    public function description(): string
    {
        return 'Publish, unpublish, or disable an entry. Requires the content:publish scope.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['live', 'pending', 'disabled']],
                'postDate' => ['type' => 'string', 'format' => 'date-time'],
            ],
            'required' => ['id', 'status'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        $status = $args['status'] ?? null;
        if ($id <= 0 || !is_string($status)) {
            throw new ToolException(-32602, 'id and status are required');
        }

        /** @var Entry|null $entry */
        // drafts(null) so by-id lookups match both drafts and canonical entries.
        $entry = Entry::find()->id($id)->status(null)->drafts(null)->one();
        if (!$entry) {
            throw new ToolException(-32008, "Entry not found: $id");
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can("saveEntries:" . $entry->getSection()->uid)) {
            throw new ToolException(-32004, 'No permission to change this entry\'s status');
        }

        $entry->enabled = $status !== 'disabled';
        if (isset($args['postDate'])) {
            try {
                $entry->postDate = new \DateTime((string) $args['postDate']);
            } catch (\Exception) {
                throw new ToolException(-32602, 'postDate is not a valid datetime');
            }
        } elseif ($status === 'live' && !$entry->postDate) {
            $entry->postDate = new \DateTime();
        }

        // If currently a draft, apply it.
        if ($entry->getIsDraft() && $status !== 'disabled') {
            $entry = Craft::$app->getDrafts()->applyDraft($entry);
        }

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new ToolException(-32016, 'Failed to set entry status', ['errors' => $entry->getErrors()]);
        }

        return [
            'id' => $entry->id,
            'status' => $entry->getStatus(),
            'postDate' => $entry->postDate?->format(\DateTimeInterface::ATOM),
        ];
    }
}
