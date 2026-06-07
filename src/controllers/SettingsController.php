<?php

declare(strict_types=1);

namespace westonhancock\editormcp\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use westonhancock\editormcp\Plugin;
use yii\web\Response;

class SettingsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requirePermission('accessPlugin-editor-mcp');
        $settings = Plugin::$plugin->getSettings();
        return $this->renderTemplate('editor-mcp/_cp/settings', [
            'settings' => $settings,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();
        $params = Craft::$app->getRequest()->getBodyParam('settings', []);
        $plugin = Plugin::$plugin;
        $settings = $plugin->getSettings();
        foreach ($params as $k => $v) {
            if (property_exists($settings, $k)) {
                if (is_string($v) && in_array($k, ['allowedRedirectSchemes', 'redirectUriPatterns', 'highStakesScopes', 'assetMimeAllowlist', 'ipAllowlist'], true)) {
                    $v = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $v))));
                }
                $settings->$k = $v;
            }
        }
        Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());
        Craft::$app->getSession()->setNotice('Settings saved.');
        return $this->redirect(UrlHelper::cpUrl('editor-mcp/settings'));
    }

    public function actionKillSwitch(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();
        $plugin = Plugin::$plugin;
        $settings = $plugin->getSettings();
        $settings->killSwitch = !$settings->killSwitch;
        Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());
        if ($settings->killSwitch) {
            $plugin->tokens->revokeAll();
            Craft::$app->getSession()->setNotice('Kill switch ON. All tokens revoked, endpoints returning 503.');
        } else {
            Craft::$app->getSession()->setNotice('Kill switch OFF. Endpoints back online.');
        }
        return $this->redirect(UrlHelper::cpUrl('editor-mcp/settings'));
    }
}
