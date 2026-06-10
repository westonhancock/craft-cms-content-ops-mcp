<?php

declare(strict_types=1);

namespace westonhancock\editormcp\controllers;

use Craft;
use craft\web\Controller;
use League\OAuth2\Server\Exception\OAuthServerException;
use westonhancock\editormcp\Plugin;
use westonhancock\editormcp\tools\ToolException;
use westonhancock\editormcp\web\PsrBridge;
use yii\helpers\IpHelper;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;
use yii\web\UnauthorizedHttpException;

/**
 * MCP Streamable HTTP transport (single endpoint, POST for client→server, GET for SSE).
 *
 * Authentication: every request must carry a Bearer access token. The token
 * is validated by TokenService and resolves to a Craft user; the tool body
 * executes as that user via ImpersonationService.
 *
 * Per the MCP spec, this endpoint speaks JSON-RPC 2.0.
 */
class McpController extends Controller
{
    public $enableCsrfValidation = false;
    protected array|int|bool $allowAnonymous = true; // we authenticate via bearer instead

    public function actionServer(): Response
    {
        $this->guard();
        $method = Craft::$app->getRequest()->getMethod();

        return match ($method) {
            'POST' => $this->handlePost(),
            'GET' => $this->handleGet(),
            'DELETE' => $this->handleDelete(),
            default => throw new BadRequestHttpException("Method $method not allowed"),
        };
    }

    private function handlePost(): Response
    {
        $tokenClaims = $this->authenticate();
        $this->enforceRateLimit($tokenClaims);
        $body = (string) Craft::$app->getRequest()->getRawBody();
        $message = json_decode($body, true);
        if (!is_array($message)) {
            return $this->jsonRpcError(null, -32700, 'Parse error');
        }

        // Batch (array of requests/notifications)
        if (array_is_list($message) && !empty($message)) {
            $out = [];
            foreach ($message as $msg) {
                $result = $this->dispatch($msg, $tokenClaims);
                if ($result !== null) {
                    $out[] = $result;
                }
            }
            if (empty($out)) {
                return $this->asEmptyResponse(202);  // all notifications
            }
            return $this->asJson($out);
        }

        $result = $this->dispatch($message, $tokenClaims);
        if ($result === null) {
            return $this->asEmptyResponse(202);
        }
        return $this->asJson($result);
    }

    private function handleGet(): Response
    {
        // We authenticate even GETs so anonymous probes don't hold a connection.
        $this->authenticate();
        // Open an SSE stream. v1 emits a single comment and closes — no server-initiated
        // events to push. Future versions may push notifications here.
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()
            ->set('Content-Type', 'text/event-stream')
            ->set('Cache-Control', 'no-cache, no-transform')
            ->set('X-Accel-Buffering', 'no');
        $response->content = ": connected\n\n";
        return $response;
    }

    private function handleDelete(): Response
    {
        $this->authenticate();
        return $this->asEmptyResponse(204);
    }

    /**
     * @return array|null  the JSON-RPC response, or null for notifications
     */
    private function dispatch(array $message, array $tokenClaims): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        $params = $message['params'] ?? [];

        if (!is_string($method)) {
            return $this->errorBody($id, -32600, 'Invalid request');
        }

        // Notifications: no id, no response.
        $isNotification = !array_key_exists('id', $message);

        try {
            $result = match ($method) {
                'initialize' => $this->mcpInitialize($params),
                'notifications/initialized' => null,
                'ping' => new \stdClass(),
                'tools/list' => $this->mcpToolsList(),
                'tools/call' => $this->mcpToolsCall($params, $tokenClaims),
                default => throw new ToolException(-32601, "Method not found: $method"),
            };
        } catch (ToolException $e) {
            if ($isNotification) {
                return null;
            }
            return $this->errorBody($id, $e->getJsonRpcCode(), $e->getMessage(), $e->getData());
        } catch (\Throwable $e) {
            Craft::error($e->getMessage() . "\n" . $e->getTraceAsString(), 'editor-mcp');
            if ($isNotification) {
                return null;
            }
            return $this->errorBody($id, -32603, 'Internal error');
        }

        if ($isNotification) {
            return null;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    private function mcpInitialize(array $params): array
    {
        return [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'craft-editor-mcp',
                'version' => '0.1.0',
            ],
        ];
    }

    private function mcpToolsList(): array
    {
        $tools = [];
        foreach (Plugin::$plugin->toolRegistry->all() as $tool) {
            $tools[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];
        }
        return ['tools' => $tools];
    }

    private function mcpToolsCall(array $params, array $tokenClaims): array
    {
        $name = $params['name'] ?? null;
        $args = $params['arguments'] ?? [];
        if (!is_string($name)) {
            throw new ToolException(-32602, 'name required');
        }
        $started = microtime(true);
        try {
            $result = Plugin::$plugin->toolRegistry->invoke($name, is_array($args) ? $args : [], $tokenClaims);
            Plugin::$plugin->audit->log([
                'requestId' => bin2hex(random_bytes(8)),
                'userId' => $tokenClaims['userId'],
                'tokenId' => null,
                'tool' => $name,
                'scopes' => $tokenClaims['scopes'],
                'params' => is_array($args) ? $args : [],
                'status' => 'success',
                'ipAddress' => Craft::$app->getRequest()->getUserIP(),
                'userAgent' => Craft::$app->getRequest()->getUserAgent(),
                'durationMs' => (int) ((microtime(true) - $started) * 1000),
            ]);
            return [
                'content' => [
                    ['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)],
                ],
                'isError' => false,
            ];
        } catch (ToolException $e) {
            Plugin::$plugin->audit->log([
                'requestId' => bin2hex(random_bytes(8)),
                'userId' => $tokenClaims['userId'],
                'tool' => $name,
                'scopes' => $tokenClaims['scopes'],
                'params' => is_array($args) ? $args : [],
                'status' => $e->getJsonRpcCode() === -32004 ? 'denied' : 'error',
                'errorCode' => (string) $e->getJsonRpcCode(),
                'errorMessage' => $e->getMessage(),
                'ipAddress' => Craft::$app->getRequest()->getUserIP(),
                'userAgent' => Craft::$app->getRequest()->getUserAgent(),
                'durationMs' => (int) ((microtime(true) - $started) * 1000),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            // Unexpected exception from a tool path — record it before bubbling
            // so the audit log captures every invocation, not just the ones
            // that fail through the ToolException convention.
            Plugin::$plugin->audit->log([
                'requestId' => bin2hex(random_bytes(8)),
                'userId' => $tokenClaims['userId'],
                'tool' => $name,
                'scopes' => $tokenClaims['scopes'],
                'params' => is_array($args) ? $args : [],
                'status' => 'error',
                'errorCode' => 'internal',
                'errorMessage' => $e->getMessage(),
                'ipAddress' => Craft::$app->getRequest()->getUserIP(),
                'userAgent' => Craft::$app->getRequest()->getUserAgent(),
                'durationMs' => (int) ((microtime(true) - $started) * 1000),
            ]);
            throw new ToolException(-32603, 'Internal error', previous: $e);
        }
    }

    private function authenticate(): array
    {
        try {
            return Plugin::$plugin->tokens->authenticateRequest(PsrBridge::fromGlobals());
        } catch (OAuthServerException $e) {
            Craft::$app->getResponse()->getHeaders()->set(
                'WWW-Authenticate',
                sprintf('Bearer error="%s", error_description="%s"',
                    $e->getErrorType(), $e->getMessage()),
            );
            throw new UnauthorizedHttpException($e->getMessage());
        }
    }

    private function guard(): void
    {
        $settings = Plugin::$plugin->getSettings();
        if ($settings->killSwitch) {
            throw new HttpException(503, 'Editor MCP kill switch is active');
        }
        if (!$settings->enabled) {
            throw new HttpException(503, 'Editor MCP is disabled');
        }
        $req = Craft::$app->getRequest();
        if (!$req->getIsSecureConnection() && Craft::$app->env !== 'dev') {
            throw new BadRequestHttpException('HTTPS required');
        }
        $this->enforceIpAllowlist();
    }

    /**
     * Optional CIDR allowlist for the MCP transport (Settings::$ipAllowlist).
     * Empty list = no restriction. Browser-facing OAuth pages are deliberately
     * not gated — this is for "tool calls only via VPN / Cloudflare Access"
     * deployments where humans still need to reach the consent screen.
     */
    private function enforceIpAllowlist(): void
    {
        $allowlist = Plugin::$plugin->getSettings()->ipAllowlist;
        if (empty($allowlist)) {
            return;
        }
        $ip = Craft::$app->getRequest()->getUserIP() ?? '';
        foreach ($allowlist as $range) {
            try {
                if ($ip !== '' && IpHelper::inRange($ip, $range)) {
                    return;
                }
            } catch (\Throwable) {
                Craft::warning("Invalid CIDR in ipAllowlist: $range", 'editor-mcp');
            }
        }
        throw new ForbiddenHttpException('IP address not allowed');
    }

    /**
     * Sliding per-user request cap (Settings::$rateLimitPerUserPerMinute, 0 =
     * unlimited) using fixed one-minute windows in the app cache. A user who
     * gets rejected 5 times within an hour fires a `rate_limit_anomaly`
     * security event — once per hour, not per rejection.
     */
    private function enforceRateLimit(array $tokenClaims): void
    {
        $limit = Plugin::$plugin->getSettings()->rateLimitPerUserPerMinute;
        if ($limit <= 0) {
            return;
        }
        $cache = Craft::$app->getCache();
        $userId = $tokenClaims['userId'];
        $minuteKey = "editor-mcp:rl:$userId:" . intdiv(time(), 60);
        $count = (int) $cache->get($minuteKey) + 1;
        $cache->set($minuteKey, $count, 120);
        if ($count <= $limit) {
            return;
        }

        $hourKey = "editor-mcp:rl-hits:$userId:" . intdiv(time(), 3600);
        $hits = (int) $cache->get($hourKey) + 1;
        $cache->set($hourKey, $hits, 7200);
        if ($hits === 5) {
            Plugin::$plugin->security->notify('rate_limit_anomaly', [
                'userId' => $userId,
                'rejectionsThisHour' => $hits,
            ]);
        }

        throw new TooManyRequestsHttpException('Rate limit exceeded');
    }

    private function jsonRpcError(mixed $id, int $code, string $message): Response
    {
        return $this->asJson($this->errorBody($id, $code, $message));
    }

    private function errorBody(mixed $id, int $code, string $message, ?array $data = null): array
    {
        $err = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $err['data'] = $data;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $err];
    }

    private function asEmptyResponse(int $status): Response
    {
        $r = Craft::$app->getResponse();
        $r->setStatusCode($status);
        $r->format = Response::FORMAT_RAW;
        $r->content = '';
        return $r;
    }
}
