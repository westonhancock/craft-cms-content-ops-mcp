<?php
declare(strict_types=1);

namespace westonhancock\editormcp\tools\impl;

use Craft;
use craft\elements\Asset;
use westonhancock\editormcp\tools\Tool;
use westonhancock\editormcp\tools\ToolException;

class FindAssetsTool implements Tool
{
    public function name(): string { return 'find_assets'; }

    public function description(): string
    {
        return 'Search assets the current user can see in a volume. Filter by filename, kind (image, pdf, etc.), and search keywords.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'volume' => ['type' => 'string', 'description' => 'Volume handle'],
                'filename' => ['type' => 'string'],
                'kind' => ['type' => 'string', 'description' => 'image, pdf, video, audio, etc.'],
                'search' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            ],
            'required' => ['volume'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $args): array
    {
        $handle = $args['volume'] ?? null;
        if (!is_string($handle) || $handle === '') {
            throw new ToolException(-32602, 'volume is required');
        }
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($handle);
        if (!$volume) {
            throw new ToolException(-32008, "Volume not found: $handle");
        }
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can("viewAssets:$volume->uid")) {
            throw new ToolException(-32004, 'No permission to view this volume');
        }

        $query = Asset::find()->volume($volume->handle);
        if (!empty($args['filename'])) {
            $query->filename((string) $args['filename']);
        }
        if (!empty($args['kind'])) {
            $query->kind((string) $args['kind']);
        }
        if (!empty($args['search'])) {
            $query->search((string) $args['search']);
        }
        $query->limit((int) ($args['limit'] ?? 25));
        $query->offset((int) ($args['offset'] ?? 0));

        $assets = $query->all();
        return [
            'total' => (int) (clone $query)->limit(null)->offset(null)->count(),
            'assets' => array_map(static fn(Asset $a) => [
                'id' => $a->id,
                'filename' => $a->filename,
                'title' => $a->title,
                'kind' => $a->kind,
                'mimeType' => $a->getMimeType(),
                'size' => $a->size,
                'width' => $a->width,
                'height' => $a->height,
                'url' => $a->getUrl(),
            ], $assets),
        ];
    }
}
