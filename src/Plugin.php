<?php
declare(strict_types=1);

namespace westonhancock\editormcp;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\User;
use craft\events\ModelEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\UrlHelper;
use craft\web\UrlManager;
use craft\web\twig\variables\Cp;
use craft\events\RegisterCpNavItemsEvent;
use westonhancock\editormcp\events\UserLifecycleHandler;
use westonhancock\editormcp\models\Settings;
use westonhancock\editormcp\services\AuditService;
use westonhancock\editormcp\services\ClientService;
use westonhancock\editormcp\services\ImpersonationService;
use westonhancock\editormcp\services\PermissionService;
use westonhancock\editormcp\services\TokenService;
use westonhancock\editormcp\tools\ToolRegistry;
use yii\base\Event;

/**
 * Editor MCP plugin entry.
 *
 * Service component accessors are typed via the @property hints below so IDEs
 * resolve them through the Yii component DI system.
 *
 * @property-read TokenService $tokens
 * @property-read PermissionService $permissions
 * @property-read ImpersonationService $impersonation
 * @property-read AuditService $audit
 * @property-read ClientService $clients
 * @property-read ToolRegistry $toolRegistry
 */
class Plugin extends BasePlugin
{
    public static ?Plugin $plugin = null;

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            'tokens' => TokenService::class,
            'permissions' => PermissionService::class,
            'impersonation' => ImpersonationService::class,
            'audit' => AuditService::class,
            'clients' => ClientService::class,
            'toolRegistry' => ToolRegistry::class,
        ]);

        $this->registerRoutes();
        $this->registerUserLifecycleEvents();
        $this->registerCpNav();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('editor-mcp/_cp/settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->controller->redirect(
            UrlHelper::cpUrl('editor-mcp/settings'),
        );
    }

    private function registerRoutes(): void
    {
        // Site routes — public OAuth + MCP endpoints
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event): void {
                $event->rules['GET .well-known/oauth-authorization-server'] = 'editor-mcp/o-auth/metadata';
                $event->rules['GET .well-known/oauth-protected-resource'] = 'editor-mcp/o-auth/protected-resource-metadata';
                $event->rules['POST oauth/register'] = 'editor-mcp/o-auth/register';
                $event->rules['POST oauth/token'] = 'editor-mcp/o-auth/token';
                $event->rules['GET oauth/authorize'] = 'editor-mcp/o-auth/authorize';
                $event->rules['POST oauth/authorize'] = 'editor-mcp/o-auth/authorize';
                $event->rules['POST oauth/consent'] = 'editor-mcp/consent/decide';
                $event->rules['POST oauth/revoke'] = 'editor-mcp/o-auth/revoke';
                // MCP transport
                $event->rules['POST mcp'] = 'editor-mcp/mcp/server';
                $event->rules['GET mcp'] = 'editor-mcp/mcp/server';
                $event->rules['DELETE mcp'] = 'editor-mcp/mcp/server';
            },
        );

        // CP routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event): void {
                $event->rules['editor-mcp'] = 'editor-mcp/tokens/index';
                $event->rules['editor-mcp/tokens'] = 'editor-mcp/tokens/index';
                $event->rules['editor-mcp/tokens/<id:\d+>'] = 'editor-mcp/tokens/view';
                $event->rules['editor-mcp/audit'] = 'editor-mcp/audit/index';
                $event->rules['editor-mcp/audit/<id:\d+>'] = 'editor-mcp/audit/view';
                $event->rules['editor-mcp/settings'] = 'editor-mcp/settings/index';
                $event->rules['editor-mcp/clients'] = 'editor-mcp/clients/index';
                $event->rules['editor-mcp/clients/<id:\d+>'] = 'editor-mcp/clients/view';
                // Settings → plugins listing also routes here via settingsHtml
            },
        );
    }

    private function registerUserLifecycleEvents(): void
    {
        Event::on(
            User::class,
            User::EVENT_AFTER_SAVE,
            [UserLifecycleHandler::class, 'onAfterSave'],
        );
        Event::on(
            User::class,
            User::EVENT_BEFORE_DELETE,
            [UserLifecycleHandler::class, 'onBeforeDelete'],
        );
    }

    private function registerCpNav(): void
    {
        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_CP_NAV_ITEMS,
            function (RegisterCpNavItemsEvent $event): void {
                if (!Craft::$app->getUser()->checkPermission('accessPlugin-editor-mcp')) {
                    return;
                }
                $event->navItems[] = [
                    'url' => 'editor-mcp',
                    'label' => 'Editor MCP',
                    'icon' => '@editor-mcp/icon-mask.svg',
                    'subnav' => [
                        'tokens' => ['label' => 'Tokens', 'url' => 'editor-mcp/tokens'],
                        'clients' => ['label' => 'Clients', 'url' => 'editor-mcp/clients'],
                        'audit' => ['label' => 'Audit Log', 'url' => 'editor-mcp/audit'],
                        'settings' => ['label' => 'Settings', 'url' => 'editor-mcp/settings'],
                    ],
                ];
            },
        );
    }
}
