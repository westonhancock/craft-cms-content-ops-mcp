<?php

declare(strict_types=1);

namespace westonhancock\editormcp\console\controllers;

use craft\console\Controller;
use westonhancock\editormcp\Plugin;
use westonhancock\editormcp\services\KeyMaterial;
use yii\console\ExitCode;

/**
 * Prints the OAuth keys as env var lines for hosts that wipe storage on deploy.
 */
class KeysController extends Controller
{
    /**
     * @var bool Export the keys in storage/editor-mcp/keys instead of new ones,
     * so connected clients stay signed in after the switch.
     */
    public bool $fromStorage = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'generate') {
            $options[] = 'fromStorage';
        }
        return $options;
    }

    /**
     * Prints EDITOR_MCP_PRIVATE_KEY and EDITOR_MCP_ENCRYPTION_KEY lines for .env.
     */
    public function actionGenerate(): int
    {
        if ($this->fromStorage) {
            $keys = Plugin::$plugin->tokens->readStorageKeys();
        } else {
            $keys = [
                'privateKey' => KeyMaterial::generatePrivateKey(),
                'encryptionKey' => KeyMaterial::generateEncryptionKey(),
            ];
        }
        $privatePem = KeyMaterial::normalizePrivateKey($keys['privateKey']);
        $encryptionKey = KeyMaterial::normalizeEncryptionKey($keys['encryptionKey']);

        $this->stdout('EDITOR_MCP_PRIVATE_KEY=' . base64_encode($privatePem) . PHP_EOL);
        $this->stdout('EDITOR_MCP_ENCRYPTION_KEY=' . $encryptionKey . PHP_EOL);

        $this->stderr(PHP_EOL . 'Add these to your environment, then reference them in config/editor-mcp.php:' . PHP_EOL);
        $this->stderr("    'privateKey' => '\$EDITOR_MCP_PRIVATE_KEY'," . PHP_EOL);
        $this->stderr("    'encryptionKey' => '\$EDITOR_MCP_ENCRYPTION_KEY'," . PHP_EOL);
        if (!$this->fromStorage) {
            $this->stderr('These are new keys. Switching to them signs out every connected client once.' . PHP_EOL);
            $this->stderr('Use --from-storage to export the current keys instead.' . PHP_EOL);
        }
        return ExitCode::OK;
    }
}
