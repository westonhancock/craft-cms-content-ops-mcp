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
        // orderBy is allowlisted, not passed through — a raw string reaches Yii's
        // ORDER BY and column names containing "(" are left unquoted (SQL injection).
        $query->orderBy($this->parseOrderBy((string) ($args['orderBy'] ?? 'postDate DESC')));

        // Element queries don't apply canView(); filter the page so peer entries and
        // drafts the user can't see are never returned. total reflects viewable-only.
        $user = Craft::$app->getUser()->getIdentity();
        $entries = array_values(array_filter(
            $query->all(),
            static fn(Entry $e): bool => $user !== null && $e->canView($user),
        ));
        return [
            'total' => count($entries),
            'entries' => array_map([$this, 'summarize'], $entries),
        ];
    }

    /**
     * Allowlist orderBy to known columns + direction, returned as a Yii order
     * spec (column => SORT_*) so the value never reaches SQL as a raw string.
     */
    private function parseOrderBy(string $raw): array
    {
        static $columns = [
            'postdate' => 'postDate',
            'title' => 'title',
            'dateupdated' => 'dateUpdated',
            'datecreated' => 'dateCreated',
            'id' => 'elements.id',
        ];
        $parts = preg_split('/\s+/', trim($raw)) ?: [];
        $field = strtolower($parts[0] ?? 'postdate');
        $dir = strtoupper($parts[1] ?? 'DESC');
        if (!isset($columns[$field])) {
            throw new ToolException(
                -32602,
                'Unsupported orderBy field. Allowed: postDate, title, dateUpdated, dateCreated, id',
            );
        }
        return [$columns[$field] => $dir === 'ASC' ? SORT_ASC : SORT_DESC];
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
