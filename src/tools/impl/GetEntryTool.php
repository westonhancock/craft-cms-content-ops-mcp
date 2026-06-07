<?php

declare(strict_types=1);

namespace westonhancock\editormcp\tools\impl;

use Craft;
use craft\elements\Entry;
use westonhancock\editormcp\tools\Tool;
use westonhancock\editormcp\tools\ToolException;

class GetEntryTool implements Tool
{
    public function name(): string
    {
        return 'get_entry';
    }

    public function description(): string
    {
        return 'Get the full content of a single entry by id. Includes all custom fields keyed by handle.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Entry id'],
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
        $query = Entry::find()->id($id)->status(null);
        if (isset($args['siteId'])) {
            $query->siteId((int) $args['siteId']);
        }
        /** @var Entry|null $entry */
        $entry = $query->one();
        if (!$entry) {
            throw new ToolException(-32008, "Entry not found: $id");
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can("viewEntries:" . $entry->getSection()->uid)) {
            throw new ToolException(-32004, 'No permission to view this entry');
        }

        $fields = [];
        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $fields[$field->handle] = $this->serializeFieldValue($entry->getFieldValue($field->handle));
        }

        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'status' => $entry->getStatus(),
            'sectionHandle' => $entry->getSection()?->handle,
            'typeHandle' => $entry->getType()->handle,
            'postDate' => $entry->postDate?->format(\DateTimeInterface::ATOM),
            'expiryDate' => $entry->expiryDate?->format(\DateTimeInterface::ATOM),
            'dateUpdated' => $entry->dateUpdated?->format(\DateTimeInterface::ATOM),
            'authorId' => $entry->authorId,
            'fields' => $fields,
        ];
    }

    private function serializeFieldValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if ($value instanceof \craft\elements\db\ElementQuery) {
            return array_map(static fn($el) => ['id' => $el->id, 'title' => $el->title ?? null],
                $value->all());
        }
        if (is_array($value)) {
            return array_map(fn($v) => $this->serializeFieldValue($v), $value);
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }
        return null;
    }
}
