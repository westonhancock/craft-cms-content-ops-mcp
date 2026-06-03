<?php
declare(strict_types=1);

namespace westonhancock\editormcp\tools\impl;

use Craft;
use westonhancock\editormcp\tools\Tool;

class ListAssetVolumesTool implements Tool
{
    public function name(): string { return 'list_asset_volumes'; }

    public function description(): string
    {
        return 'List asset volumes the current user can view, with their handles and supported file kinds.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
    }

    public function execute(array $args): array
    {
        $user = Craft::$app->getUser()->getIdentity();
        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        $out = [];
        foreach ($volumes as $volume) {
            if (!$user || !$user->can("viewAssets:$volume->uid")) {
                continue;
            }
            $out[] = [
                'handle' => $volume->handle,
                'name' => $volume->name,
                'kind' => $volume->fs?->handle ?? null,
                'canUpload' => $user->can("saveAssets:$volume->uid"),
            ];
        }
        return ['volumes' => $out];
    }
}
