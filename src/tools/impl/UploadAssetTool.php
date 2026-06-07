<?php

declare(strict_types=1);

namespace westonhancock\editormcp\tools\impl;

use Craft;
use craft\elements\Asset;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use westonhancock\editormcp\Plugin;
use westonhancock\editormcp\tools\Tool;
use westonhancock\editormcp\tools\ToolException;

class UploadAssetTool implements Tool
{
    public function name(): string
    {
        return 'upload_asset';
    }

    public function description(): string
    {
        return 'Upload a file to an asset volume. File data is base64-encoded with a 25MB raw cap. Server validates MIME type; client-claimed MIME is ignored.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'volume' => ['type' => 'string', 'description' => 'Volume handle'],
                'filename' => ['type' => 'string'],
                'file_data_base64' => ['type' => 'string'],
                'folder' => ['type' => 'string', 'description' => 'Optional folder path (e.g. "uploads/2026")'],
                'title' => ['type' => 'string'],
                'alt' => ['type' => 'string'],
            ],
            'required' => ['volume', 'filename', 'file_data_base64'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $args): array
    {
        $settings = Plugin::$plugin->getSettings();

        $volumeHandle = $args['volume'] ?? '';
        $filename = $args['filename'] ?? '';
        $b64 = $args['file_data_base64'] ?? '';
        if (!is_string($volumeHandle) || !is_string($filename) || !is_string($b64)) {
            throw new ToolException(-32602, 'volume, filename, file_data_base64 are required');
        }
        // Reject decoded > limit
        if (strlen($b64) > (int) ($settings->assetUploadMaxBytes * 1.4)) {
            throw new ToolException(-32016, 'Upload too large');
        }
        $bytes = base64_decode($b64, true);
        if ($bytes === false) {
            throw new ToolException(-32602, 'file_data_base64 is not valid base64');
        }
        if (strlen($bytes) > $settings->assetUploadMaxBytes) {
            throw new ToolException(-32016, 'Upload exceeds asset size cap');
        }

        $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);
        if (!$volume) {
            throw new ToolException(-32008, "Volume not found: $volumeHandle");
        }
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can("saveAssets:$volume->uid")) {
            throw new ToolException(-32004, 'No permission to upload to this volume');
        }

        $tmp = AssetsHelper::tempFilePath(pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin');
        file_put_contents($tmp, $bytes);

        // Server-side MIME detection (never trust the client)
        $mime = FileHelper::getMimeType($tmp) ?? 'application/octet-stream';

        // Allowlist check
        if (!empty($settings->assetMimeAllowlist) && !in_array($mime, $settings->assetMimeAllowlist, true)) {
            @unlink($tmp);
            throw new ToolException(-32016, "MIME type not allowed: $mime");
        }

        // Pick destination folder
        $folderId = $this->resolveFolderId($volume, $args['folder'] ?? null);

        try {
            $asset = new Asset();
            $asset->tempFilePath = $tmp;
            $asset->filename = AssetsHelper::prepareAssetName($filename);
            $asset->newFolderId = $folderId;
            $asset->setScenario(Asset::SCENARIO_CREATE);
            if (isset($args['title'])) {
                $asset->title = (string) $args['title'];
            }
            if (isset($args['alt'])) {
                $asset->alt = (string) $args['alt'];
            }
            if (!Craft::$app->getElements()->saveElement($asset)) {
                throw new ToolException(-32016, 'Failed to save asset', ['errors' => $asset->getErrors()]);
            }
            return [
                'id' => $asset->id,
                'filename' => $asset->filename,
                'url' => $asset->getUrl(),
                'mimeType' => $asset->getMimeType(),
                'size' => $asset->size,
            ];
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function resolveFolderId($volume, ?string $folderPath): int
    {
        $assets = Craft::$app->getAssets();
        $root = $assets->getRootFolderByVolumeId($volume->id);
        if (!$folderPath) {
            return $root->id;
        }
        // Find or create nested folders
        $parts = array_values(array_filter(explode('/', trim($folderPath, '/'))));
        $current = $root;
        foreach ($parts as $name) {
            $existing = $assets->findFolders([
                'parentId' => $current->id,
                'name' => $name,
            ])[0] ?? null;
            if ($existing) {
                $current = $existing;
                continue;
            }
            $folder = new \craft\models\VolumeFolder();
            $folder->parentId = $current->id;
            $folder->volumeId = $volume->id;
            $folder->name = $name;
            $folder->path = ($current->path ?? '') . $name . '/';
            $assets->createFolder($folder);
            $current = $folder;
        }
        return $current->id;
    }
}
