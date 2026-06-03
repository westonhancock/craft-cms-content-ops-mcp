<?php
declare(strict_types=1);

namespace westonhancock\editormcp\tools\impl;

use Craft;
use craft\elements\Asset;
use westonhancock\editormcp\tools\Tool;
use westonhancock\editormcp\tools\ToolException;

class GetAssetTool implements Tool
{
    public function name(): string { return 'get_asset'; }

    public function description(): string
    {
        return 'Get full metadata for a single asset by id, including its URL and any custom fields.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
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
        /** @var Asset|null $asset */
        $asset = Asset::find()->id($id)->one();
        if (!$asset) {
            throw new ToolException(-32008, "Asset not found: $id");
        }
        $user = Craft::$app->getUser()->getIdentity();
        $volume = $asset->getVolume();
        if (!$user || !$user->can("viewAssets:$volume->uid")) {
            throw new ToolException(-32004, 'No permission to view this asset');
        }
        $fields = [];
        foreach ($asset->getFieldLayout()?->getCustomFields() ?? [] as $f) {
            $val = $asset->getFieldValue($f->handle);
            $fields[$f->handle] = is_scalar($val) || $val === null ? $val : (string) $val;
        }
        return [
            'id' => $asset->id,
            'filename' => $asset->filename,
            'title' => $asset->title,
            'kind' => $asset->kind,
            'mimeType' => $asset->getMimeType(),
            'size' => $asset->size,
            'width' => $asset->width,
            'height' => $asset->height,
            'url' => $asset->getUrl(),
            'volume' => $volume->handle,
            'folderId' => $asset->folderId,
            'fields' => $fields,
            'alt' => $asset->alt,
        ];
    }
}
