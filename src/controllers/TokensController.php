<?php

declare(strict_types=1);

namespace westonhancock\editormcp\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use westonhancock\editormcp\records\AccessTokenRecord;
use westonhancock\editormcp\records\RefreshTokenRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class TokensController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-editor-mcp');

        $rows = (new \craft\db\Query())
            ->select([
                't.id', 't.tokenId', 't.scopes', 't.expiresAt', 't.lastUsedAt',
                't.revokedAt', 't.userId', 't.dateCreated',
                'c.clientId', 'c.name as clientName',
                'u.username',
            ])
            ->from(['t' => '{{%editormcp_access_tokens}}'])
            ->innerJoin(['c' => '{{%editormcp_clients}}'], 'c.id = t.clientId')
            ->leftJoin(['u' => '{{%users}}'], 'u.id = t.userId')
            ->orderBy('t.dateCreated DESC')
            ->limit(500)
            ->all();

        return $this->renderTemplate('editor-mcp/_cp/tokens/index', [
            'rows' => $rows,
        ]);
    }

    public function actionRevoke(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-editor-mcp');
        $id = (int) Craft::$app->getRequest()->getRequiredBodyParam('id');
        $token = AccessTokenRecord::findOne($id);
        if (!$token) {
            throw new NotFoundHttpException('Token not found');
        }
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $token->revokedAt = $now;
        $token->save(false);
        // Revoke the paired refresh tokens too
        RefreshTokenRecord::updateAll(
            ['revokedAt' => $now],
            ['accessTokenId' => $token->id, 'revokedAt' => null],
        );
        Craft::$app->getSession()->setNotice('Token revoked.');
        return $this->redirect(UrlHelper::cpUrl('editor-mcp/tokens'));
    }
}
