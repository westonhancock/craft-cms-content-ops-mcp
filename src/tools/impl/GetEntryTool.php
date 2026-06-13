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

        // canView() folds in peer/draft restrictions that a bare
        // viewEntries:<section> check would leak past.
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !Craft::$app->getElements()->canView($entry, $user)) {
            throw new ToolException(-32004, 'No permission to view this entry');
        }

        $fields = $this->serializeFields($entry, 0);

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

    /** How deep to inline nested Matrix blocks before truncating. */
    private const MAX_MATRIX_DEPTH = 8;

    /**
     * Serialize every custom field on an element keyed by handle. Matrix blocks
     * are expanded inline (recursively); relation fields stay id/title stubs.
     */
    private function serializeFields(\craft\base\ElementInterface $element, int $depth): array
    {
        $fields = [];
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $fields[$field->handle] = $this->serializeFieldValue(
                $field,
                $element->getFieldValue($field->handle),
                $depth,
            );
        }
        return $fields;
    }

    private function serializeFieldValue(\craft\base\FieldInterface $field, mixed $value, int $depth): mixed
    {
        // Matrix holds nested entries owned by this element — inline their content
        // so the AI can read blocks without a follow-up get_entry per block id
        // (which also has no section, the source of the old crash).
        if ($field instanceof \craft\fields\Matrix) {
            if ($depth >= self::MAX_MATRIX_DEPTH) {
                return ['_truncated' => 'max nesting depth reached'];
            }
            $elements = $value instanceof \craft\elements\db\ElementQuery
                ? $value->all()
                : (is_array($value) ? $value : []);
            $blocks = [];
            foreach ($elements as $block) {
                if (!$block instanceof Entry) {
                    continue;
                }
                $blocks[] = [
                    'id' => $block->id,
                    'type' => $block->getType()->handle,
                    'fields' => $this->serializeFields($block, $depth + 1),
                ];
            }
            return $blocks;
        }
        return $this->serializeScalarOrRelation($value);
    }

    private function serializeScalarOrRelation(mixed $value): mixed
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
            return array_map(fn($v) => $this->serializeScalarOrRelation($v), $value);
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }
        return null;
    }
}
