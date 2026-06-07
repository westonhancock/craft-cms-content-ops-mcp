<?php

declare(strict_types=1);

namespace westonhancock\editormcp\tools\impl;

use Craft;
use craft\elements\Entry;
use westonhancock\editormcp\tools\Tool;
use westonhancock\editormcp\tools\ToolException;

class FindEntriesTool implements Tool
{
    public function name(): string
    {
        return 'find_entries';
    }

    public function description(): string
    {
        return 'Search entries the current user can see. Filter by section, status, search keywords, slug, or related entries. Returns up to limit entries with id, title, slug, status, postDate, and section.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'section' => ['type' => 'string', 'description' => 'Section handle. Required.'],
                'status' => [
                    'oneOf' => [
                        ['type' => 'string', 'enum' => ['live', 'pending', 'expired', 'disabled']],
                        ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'search' => ['type' => 'string', 'description' => 'Keyword search against title and content'],
                'slug' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                'orderBy' => ['type' => 'string', 'default' => 'postDate DESC'],
            ],
            'required' => ['section'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $args): array
    {
        $handle = $args['section'] ?? null;
        if (!is_string($handle) || $handle === '') {
            throw new ToolException(-32602, 'section is required');
        }
        $section = Craft::$app->getEntries()->getSectionByHandle($handle);
        if (!$section) {
            throw new ToolException(-32008, "Section not found: $handle");
        }

        $query = Entry::find()->section($section->handle);
        if (isset($args['status'])) {
            $query->status($args['status']);
        } else {
            $query->status(null);
        }
        if (!empty($args['search'])) {
            $query->search((string) $args['search']);
        }
        if (!empty($args['slug'])) {
            $query->slug((string) $args['slug']);
        }
        $query->limit((int) ($args['limit'] ?? 25));
        $query->offset((int) ($args['offset'] ?? 0));
        $query->orderBy((string) ($args['orderBy'] ?? 'postDate DESC'));

        // Craft enforces per-section permission natively against the impersonated user.
        $entries = $query->all();
        return [
            'total' => (int) (clone $query)->limit(null)->offset(null)->count(),
            'entries' => array_map([$this, 'summarize'], $entries),
        ];
    }

    private function summarize(Entry $entry): array
    {
        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'status' => $entry->getStatus(),
            'sectionHandle' => $entry->getSection()?->handle,
            'typeHandle' => $entry->getType()->handle,
            'postDate' => $entry->postDate?->format(\DateTimeInterface::ATOM),
            'dateUpdated' => $entry->dateUpdated?->format(\DateTimeInterface::ATOM),
        ];
    }
}
