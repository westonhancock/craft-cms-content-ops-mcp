<?php
declare(strict_types=1);

namespace westonhancock\editormcp\services;

use Craft;
use DateTimeImmutable;
use westonhancock\editormcp\Plugin;
use westonhancock\editormcp\records\AuditEntryRecord;
use yii\base\Component;

/**
 * Per-call audit log.
 *
 * PII guard: by default we log only *structural* params — IDs, section handles,
 * field handles. We never log field *values* unless Settings::$auditVerbose is on,
 * which is intended for debug-only environments.
 */
class AuditService extends Component
{
    /** @var array<string,bool> field names recognized as structural */
    private const STRUCTURAL_FIELDS = [
        'id' => true,
        'section' => true,
        'type' => true,
        'volume' => true,
        'group' => true,
        'handle' => true,
        'status' => true,
        'folder' => true,
        'mimeType' => true,
        'filename' => true,
    ];

    public function log(array $payload): void
    {
        $settings = Plugin::$plugin->getSettings();

        $record = new AuditEntryRecord();
        $record->requestId = $payload['requestId'] ?? bin2hex(random_bytes(8));
        $record->userId = $payload['userId'] ?? null;
        $record->clientId = $payload['clientId'] ?? null;
        $record->tokenId = $payload['tokenId'] ?? null;
        $record->tool = $payload['tool'] ?? null;
        $record->scopes = isset($payload['scopes'])
            ? json_encode($payload['scopes'])
            : null;

        $params = $payload['params'] ?? [];
        $record->paramsStructural = json_encode($this->extractStructural($params));
        $record->paramsVerbose = $settings->auditVerbose
            ? json_encode($params, JSON_PARTIAL_OUTPUT_ON_ERROR)
            : null;

        $record->status = $payload['status'] ?? 'success';
        $record->errorCode = $payload['errorCode'] ?? null;
        $record->errorMessage = $payload['errorMessage'] ?? null;
        $record->ipAddress = $payload['ipAddress'] ?? null;
        $record->userAgent = $payload['userAgent'] ?? null;
        $record->durationMs = $payload['durationMs'] ?? null;
        $record->save(false);
    }

    public function prune(): int
    {
        $settings = Plugin::$plugin->getSettings();
        if ($settings->auditRetentionDays === null) {
            return 0;
        }
        $cutoff = (new DateTimeImmutable("-{$settings->auditRetentionDays} days"))
            ->format('Y-m-d H:i:s');
        return AuditEntryRecord::deleteAll(['<', 'dateCreated', $cutoff]);
    }

    private function extractStructural(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            if (isset(self::STRUCTURAL_FIELDS[$k])) {
                $out[$k] = is_scalar($v) || $v === null ? $v : '[non-scalar]';
            } elseif ($k === 'fields' && is_array($v)) {
                // Log the keys (field handles), not the values
                $out['fieldHandles'] = array_keys($v);
            } elseif ($k === 'filters' && is_array($v)) {
                $out['filters'] = $this->extractStructural($v);
            }
        }
        return $out;
    }
}
