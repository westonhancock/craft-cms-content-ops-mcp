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
        $textareaToArray = ['allowedRedirectSchemes', 'redirectUriPatterns', 'highStakesScopes', 'assetMimeAllowlist', 'ipAllowlist'];
        foreach ($params as $k => $v) {
            if (!property_exists($settings, $k)) {
                continue;
            }
            if (is_string($v) && in_array($k, $textareaToArray, true)) {
                $v = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $v))));
            }
            $settings->$k = $this->coerceToPropertyType($settings, $k, $v);
        }
        Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());
        Craft::$app->getSession()->setNotice('Settings saved.');
        return $this->redirect(UrlHelper::cpUrl('editor-mcp/settings'));
    }

    /**
     * Coerce form-submitted scalars to the declared property type so typed
     * properties don't TypeError. Lightswitches post "1"/"0" or absent; number
     * fields post strings. Pass through anything that's already the right type
     * or an array (handled by caller).
     */
    private function coerceToPropertyType(object $obj, string $prop, mixed $v): mixed
    {
        $type = (new \ReflectionProperty($obj, $prop))->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return $v;
        }
        $name = $type->getName();
        $nullable = $type->allowsNull();
        if ($nullable && ($v === '' || $v === null)) {
            return null;
        }
        return match ($name) {
            'bool' => filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'int' => (int) $v,
            'string' => (string) $v,
            'array' => is_array($v) ? $v : [],
            default => $v,
        };
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
