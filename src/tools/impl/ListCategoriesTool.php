<?php
declare(strict_types=1);

namespace westonhancock\editormcp\tools\impl;

use Craft;
use craft\elements\Category;
use westonhancock\editormcp\tools\Tool;
use westonhancock\editormcp\tools\ToolException;

class ListCategoriesTool implements Tool
{
    public function name(): string { return 'list_categories'; }

    public function description(): string
    {
        return 'List categories in a category group. Returns id, title, slug, and parent for each.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'group' => ['type' => 'string', 'description' => 'Category group handle'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100],
            ],
            'required' => ['group'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $args): array
    {
        $handle = $args['group'] ?? '';
        if (!is_string($handle) || $handle === '') {
            throw new ToolException(-32602, 'group is required');
        }
        $group = Craft::$app->getCategories()->getGroupByHandle($handle);
        if (!$group) {
            throw new ToolException(-32008, "Category group not found: $handle");
        }
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can("viewCategories:$group->uid")) {
            throw new ToolException(-32004, 'No permission to view this category group');
        }
        $categories = Category::find()->group($group->handle)->limit((int) ($args['limit'] ?? 100))->all();
        return [
            'group' => $group->handle,
            'categories' => array_map(static fn(Category $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'slug' => $c->slug,
                'level' => $c->level,
                'parentId' => $c->getParent()?->id,
            ], $categories),
        ];
    }
}
