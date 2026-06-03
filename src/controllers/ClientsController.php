<?php
declare(strict_types=1);

namespace westonhancock\editormcp\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use westonhancock\editormcp\Plugin;
use westonhancock\editormcp\records\OAuthClientRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ClientsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requirePermission('accessPlugin-editor-mcp');
        $clients = OAuthClientRecord::find()->orderBy(['dateCreated' => SORT_DESC])->all();
        return $this->renderTemplate('editor-mcp/_cp/clients/index', [
            'clients' => $clients,
        ]);
    }

    public function actionApprove(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-editor-mcp');
        $id = (int) Craft::$app->getRequest()->getRequiredBodyParam('id');
        Plugin::$plugin->clients->approve($id);
        Craft::$app->getSession()->setNotice('Client approved.');
        return $this->redirect(UrlHelper::cpUrl('editor-mcp/clients'));
    }

    public function actionRevoke(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-editor-mcp');
        $id = (int) Craft::$app->getRequest()->getRequiredBodyParam('id');
        Plugin::$plugin->clients->revoke($id);
        Craft::$app->getSession()->setNotice('Client revoked and all its tokens invalidated.');
        return $this->redirect(UrlHelper::cpUrl('editor-mcp/clients'));
    }
}
