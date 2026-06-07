<?php

declare(strict_types=1);

namespace westonhancock\editormcp\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Response as PsrResponse;
use westonhancock\editormcp\oauth\Repositories\ScopeRepository;
use westonhancock\editormcp\Plugin;
use westonhancock\editormcp\web\PsrBridge;
use yii\web\BadRequestHttpException;
use yii\web\HttpException;
use yii\web\Response;

/**
 * OAuth 2.1 endpoints.
 *
 *   GET/POST  /oauth/authorize
 *   POST      /oauth/token
 *   POST      /oauth/register   (Dynamic Client Registration)
 *   POST      /oauth/revoke
 *   GET       /.well-known/oauth-authorization-server
 *   GET       /.well-known/oauth-protected-resource
 *
 * The authorize endpoint is the only one that interacts with the Craft CP
 * session — it requires the user to be logged in, and re-uses Craft's login
 * flow (which 2FA plugins hook into transparently).
 */
class OAuthController extends Controller
{
    public $enableCsrfValidation = false;
    protected array|int|bool $allowAnonymous = [
        'metadata' => self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE,
        'protected-resource-metadata' => self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE,
        'register' => self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE,
        'token' => self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE,
        'revoke' => self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE,
        // authorize must be reachable anonymously — actionAuthorize() redirects
        // unauthenticated callers to Craft's login flow with a return URL.
        'authorize' => self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE,
        // elevate checks session inside and rejects unauthenticated callers.
        'elevate' => self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE,
    ];

    public function beforeAction($action): bool
    {
        $this->guard();
        return parent::beforeAction($action);
    }

    public function actionMetadata(): Response
    {
        $base = UrlHelper::siteUrl();
        return $this->asJson([
            'issuer' => rtrim($base, '/'),
            'authorization_endpoint' => UrlHelper::siteUrl('oauth/authorize'),
            'token_endpoint' => UrlHelper::siteUrl('oauth/token'),
            'registration_endpoint' => UrlHelper::siteUrl('oauth/register'),
            'revocation_endpoint' => UrlHelper::siteUrl('oauth/revoke'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'scopes_supported' => array_keys(ScopeRepository::SCOPES),
            'service_documentation' => 'https://github.com/westonhancock/craft-cms-content-ops-mcp',
        ]);
    }

    public function actionProtectedResourceMetadata(): Response
    {
        return $this->asJson([
            'resource' => UrlHelper::siteUrl('mcp'),
            'authorization_servers' => [rtrim(UrlHelper::siteUrl(), '/')],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => array_keys(ScopeRepository::SCOPES),
        ]);
    }

    public function actionRegister(): Response
    {
        $body = Craft::$app->getRequest()->getBodyParams()
            ?: json_decode((string) Craft::$app->getRequest()->getRawBody(), true)
            ?: [];

        try {
            $registered = Plugin::$plugin->clients->register(
                $body,
                Craft::$app->getRequest()->getUserIP() ?? '',
            );
            return $this->asJson($registered)->setStatusCode(201);
        } catch (\RuntimeException $e) {
            return $this->asJson(['error' => 'rate_limited', 'error_description' => $e->getMessage()])
                ->setStatusCode(429);
        } catch (\InvalidArgumentException $e) {
            return $this->asJson(['error' => 'invalid_client_metadata', 'error_description' => $e->getMessage()])
                ->setStatusCode(400);
        }
    }

    public function actionToken(): Response
    {
        $server = Plugin::$plugin->tokens->getAuthorizationServer();
        $psrRequest = PsrBridge::fromGlobals();
        $psrResponse = new PsrResponse();
        try {
            $psrResponse = $server->respondToAccessTokenRequest($psrRequest, $psrResponse);
        } catch (OAuthServerException $e) {
            $psrResponse = $e->generateHttpResponse($psrResponse);
        }
        return PsrBridge::toCraft($psrResponse);
    }

    public function actionAuthorize(): Response
    {
        $server = Plugin::$plugin->tokens->getAuthorizationServer();
        $psrRequest = PsrBridge::fromGlobals();
        $psrResponse = new PsrResponse();

        try {
            $authRequest = $server->validateAuthorizationRequest($psrRequest);
        } catch (OAuthServerException $e) {
            return PsrBridge::toCraft($e->generateHttpResponse($psrResponse));
        }

        // Reject if PKCE method isn't S256.
        $codeChallengeMethod = $authRequest->getCodeChallengeMethod();
        if ($codeChallengeMethod !== 'S256') {
            return PsrBridge::toCraft(
                OAuthServerException::invalidRequest('code_challenge_method', 'Only S256 is supported')
                    ->generateHttpResponse($psrResponse),
            );
        }

        $scopes = array_map(static fn($s) => $s->getIdentifier(), $authRequest->getScopes());
        $highStakes = array_filter($scopes, fn($s) => Plugin::$plugin->permissions->isHighStakes($s));

        $prompt = Craft::$app->getRequest()->getQueryParam('prompt', '');
        $needsFreshLogin = !empty($highStakes) || $prompt === 'login';

        $userComponent = Craft::$app->getUser();
        $craftUser = $userComponent->getIdentity();
        // For high-stakes scopes (publish/delete) or prompt=login, require an
        // elevated session — Craft's native concept of "freshly re-authed".
        // Its duration is the site's $elevatedSessionDuration (default 5 min).
        $sessionFreshEnough = !$needsFreshLogin || $userComponent->getHasElevatedSession();

        if (!$craftUser) {
            // Send to the CP login page — the only place editor identity lives
            // (and where 2FA challenges run). After login, Craft uses the
            // return URL we set to bring the user back to /oauth/authorize.
            $userComponent->setReturnUrl(Craft::$app->getRequest()->getAbsoluteUrl());
            return $this->redirect(UrlHelper::cpUrl(
                Craft::$app->getConfig()->getGeneral()->getLoginPath(),
            ));
        }

        if (!$sessionFreshEnough) {
            // User IS logged in, but their elevated session has expired (or
            // never elevated since this request requires it). Redirecting to
            // /admin/login would loop — they're already authenticated, so the
            // CP would just bounce them to the dashboard. Render an in-band
            // password re-confirmation prompt instead. This matches OAuth 2.1
            // `prompt=login` semantics and reuses Craft's startElevatedSession.
            return $this->renderTemplate(
                'editor-mcp/oauth/elevate',
                [
                    'continuationUrl' => Craft::$app->getRequest()->getAbsoluteUrl(),
                    'highStakes' => $highStakes,
                    'user' => $craftUser,
                    'error' => Craft::$app->getSession()->getFlash('mcpElevateError'),
                ],
                View::TEMPLATE_MODE_CP,
            );
        }

        // Stash the validated AuthorizationRequest in the session so the
        // consent action can resume it.
        $session = Craft::$app->getSession();
        $stateKey = 'editor-mcp:authRequest:' . bin2hex(random_bytes(8));
        $session->set($stateKey, [
            'request' => serialize($authRequest),
            'forcedFreshLogin' => $needsFreshLogin,
            'createdAt' => time(),
        ]);

        // Render consent in CP template mode so the `_layouts/cp` extend in
        // the template resolves. The user is already authenticated against the
        // CP, so showing them the consent screen with CP chrome is the
        // intended UX.
        return $this->renderTemplate(
            'editor-mcp/oauth/consent',
            [
                'authRequest' => $authRequest,
                'scopes' => $scopes,
                'scopeDescriptions' => ScopeRepository::SCOPES,
                'clientName' => $this->resolveClientDisplayName($authRequest),
                'stateKey' => $stateKey,
                'redirectUri' => $authRequest->getRedirectUri() ?? '',
                'user' => $craftUser,
                'highStakes' => $highStakes,
            ],
            View::TEMPLATE_MODE_CP,
        );
    }

    public function actionRevoke(): Response
    {
        $token = Craft::$app->getRequest()->getBodyParam('token');
        if (!is_string($token) || $token === '') {
            throw new BadRequestHttpException('token required');
        }
        // Either an access token JTI or a refresh token id. Try both.
        $accessRepo = new \westonhancock\editormcp\oauth\Repositories\AccessTokenRepository();
        $refreshRepo = new \westonhancock\editormcp\oauth\Repositories\RefreshTokenRepository();
        $accessRepo->revokeAccessToken($token);
        $refreshRepo->forceRevoke($token);
        return $this->asJson(['revoked' => true]);
    }

    /**
     * Handles the password re-confirmation form shown when high-stakes scopes
     * are requested but the user's elevated session has expired. On success,
     * redirects back to the originally-requested /oauth/authorize URL so the
     * normal flow can resume with consent.
     */
    public function actionElevate(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\User $user */
        $user = Craft::$app->getUser();
        $identity = $user->getIdentity();
        if (!$identity) {
            // Misuse — elevation only makes sense for an already-logged-in user.
            throw new BadRequestHttpException('Not authenticated');
        }
        $password = (string) Craft::$app->getRequest()->getRequiredBodyParam('password');
        $continuationUrl = (string) Craft::$app->getRequest()->getRequiredBodyParam('continuationUrl');

        // Open-redirect guard: only allow continuation to our own authorize endpoint.
        $authorizeBase = UrlHelper::siteUrl('oauth/authorize');
        if (!str_starts_with($continuationUrl, $authorizeBase)) {
            throw new BadRequestHttpException('Invalid continuation URL');
        }

        $valid = $identity->password !== null
            && Craft::$app->getSecurity()->validatePassword($password, $identity->password);
        if (!$valid) {
            Craft::$app->getSession()->setFlash('mcpElevateError', 'Incorrect password. Please try again.');
            return $this->redirect($continuationUrl);
        }

        // Mirror what Craft's login() does to set the elevated session — there
        // is no public API, but the session key and computation are stable.
        $duration = Craft::$app->getConfig()->getGeneral()->elevatedSessionDuration;
        if ($duration > 0) {
            Craft::$app->getSession()->set($user->elevatedSessionTimeoutParam, time() + $duration);
        }

        return $this->redirect($continuationUrl);
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
        if (!$req->getIsSecureConnection() && Craft::$app->env !== 'dev' && $req->getHostName() !== 'localhost') {
            throw new BadRequestHttpException('HTTPS required');
        }
    }

    private function resolveClientDisplayName($authRequest): string
    {
        $client = $authRequest->getClient();
        if (method_exists($client, 'getName') && $client->getName()) {
            return (string) $client->getName();
        }
        return $client->getIdentifier();
    }

}
