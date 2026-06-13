<?php

declare(strict_types=1);

namespace westonhancock\editormcp\controllers;

use Craft;
use craft\web\Controller;
use westonhancock\editormcp\records\AuditEntryRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class AuditController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requirePermission('accessPlugin-editor-mcp');
        $filters = [
            'user' => Craft::$app->getRequest()->getQueryParam('user'),
            'tool' => Craft::$app->getRequest()->getQueryParam('tool'),
            'status' => Craft::$app->getRequest()->getQueryParam('status'),
            'since' => Craft::$app->getRequest()->getQueryParam('since'),
        ];

        $q = (new \craft\db\Query())
            ->select([
                'a.id', 'a.requestId', 'a.tool', 'a.status', 'a.errorCode',
                'a.errorMessage', 'a.durationMs', 'a.ipAddress', 'a.dateCreated',
                'a.userId', 'u.username',
            ])
            ->from(['a' => '{{%editormcp_audit_entries}}'])
            ->leftJoin(['u' => '{{%users}}'], '[[u.id]] = [[a.userId]]')
            ->orderBy('a.dateCreated DESC')
            ->limit(200);

        if (!empty($filters['user'])) {
            $q->andWhere(['like', 'u.username', $filters['user']]);
        }
        if (!empty($filters['tool'])) {
            $q->andWhere(['a.tool' => $filters['tool']]);
        }
        if (!empty($filters['status'])) {
            $q->andWhere(['a.status' => $filters['status']]);
        }
        if (!empty($filters['since'])) {
            $q->andWhere(['>=', 'a.dateCreated', $filters['since']]);
        }
        return $this->renderTemplate('editor-mcp/_cp/audit/index', [
            'rows' => $q->all(),
            'filters' => $filters,
        ]);
    }

    public function actionView(int $id): Response
    {
        $this->requirePermission('accessPlugin-editor-mcp');
        $entry = AuditEntryRecord::findOne($id);
        if (!$entry) {
            throw new NotFoundHttpException('Audit entry not found');
        }
        return $this->renderTemplate('editor-mcp/_cp/audit/view', [
            'entry' => $entry,
        ]);
    }
}
