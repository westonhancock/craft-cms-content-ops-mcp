<?php

declare(strict_types=1);

namespace westonhancock\editormcp\tools;

use westonhancock\editormcp\Plugin;
use westonhancock\editormcp\tools\impl\CreateEntryTool;
use westonhancock\editormcp\tools\impl\DeleteEntryTool;
use westonhancock\editormcp\tools\impl\FindAssetsTool;
use westonhancock\editormcp\tools\impl\FindEntriesTool;
use westonhancock\editormcp\tools\impl\GetAssetTool;
use westonhancock\editormcp\tools\impl\GetEntryTool;
use westonhancock\editormcp\tools\impl\GetGlobalTool;
use westonhancock\editormcp\tools\impl\ListAssetVolumesTool;
use westonhancock\editormcp\tools\impl\ListCategoriesTool;
use westonhancock\editormcp\tools\impl\ListSectionFieldsTool;
use westonhancock\editormcp\tools\impl\ListSectionsTool;
use westonhancock\editormcp\tools\impl\SetEntryStatusTool;
use westonhancock\editormcp\tools\impl\UpdateEntryFieldsTool;
use westonhancock\editormcp\tools\impl\UploadAssetTool;
use westonhancock\editormcp\tools\impl\WhoAmITool;
use yii\base\Component;

class ToolRegistry extends Component
{
    /** @var array<string, Tool> */
    private array $tools = [];

    /** Request-scoped token claims, set during invoke() and readable by tools that need it (e.g. who_am_i). */
    public ?array $currentTokenClaims = null;

    public function init(): void
    {
        parent::init();
        foreach ([
            new ListSectionsTool(),
            new ListSectionFieldsTool(),
            new ListAssetVolumesTool(),
            new FindEntriesTool(),
            new GetEntryTool(),
            new CreateEntryTool(),
            new UpdateEntryFieldsTool(),
            new SetEntryStatusTool(),
            new DeleteEntryTool(),
            new FindAssetsTool(),
            new GetAssetTool(),
            new UploadAssetTool(),
            new ListCategoriesTool(),
            new GetGlobalTool(),
            new WhoAmITool(),
        ] as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /** @return Tool[] */
    public function all(): array
    {
        return array_values($this->tools);
    }

    public function get(string $name): Tool
    {
        if (!isset($this->tools[$name])) {
            throw new ToolException(-32008, "Unknown tool: $name");
        }
        return $this->tools[$name];
    }

    /**
     * Invoke a tool with auth checks.
     *
     * @param array{userId:int, clientId:string, scopes:string[], tokenId:string} $tokenClaims
     */
    public function invoke(string $name, array $args, array $tokenClaims): array
    {
        $tool = $this->get($name);

        if (!Plugin::$plugin->permissions->hasScope($tokenClaims['scopes'], $name)) {
            $required = Plugin::$plugin->permissions->requiredScope($name);
            throw new ToolException(-32004, "Scope $required required for $name");
        }

        $user = Plugin::$plugin->permissions->loadUser($tokenClaims['userId']);
        if (!$user) {
            throw new ToolException(-32001, 'User is not active');
        }

        $this->currentTokenClaims = $tokenClaims;
        try {
            return Plugin::$plugin->impersonation->runAs($user, fn() => $tool->execute($args));
        } finally {
            $this->currentTokenClaims = null;
        }
    }
}
