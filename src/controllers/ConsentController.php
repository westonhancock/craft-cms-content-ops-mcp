<?php
declare(strict_types=1);

namespace westonhancock\editormcp\controllers;

use Craft;
use craft\web\Controller;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Nyholm\Psr7\Response as PsrResponse;
use westonhancock\editormcp\Plugin;
use westonhancock\editormcp\oauth\Entities\UserEntity;
use westonhancock\editormcp\web\PsrBridge;
use yii\web\BadRequestHttpException;
use yii\web\Response;
use yii\web\ServiceUnavailableHttpException;

/**
 * Handles the user's consent decision after the OAuth /authorize endpoint
 * renders the consent screen.
 *
 * CSRF: this endpoint inherits Craft's CP CSRF token because the consent
 * template renders inside the Craft session. PKCE binds the eventual code to
 * the originating client.
 */
class ConsentController extends Controller
{
    public function actionDecide(): Response
    {
        $settings = Plugin::$plugin->getSettings();
        if ($settings->killSwitch || !$settings->enabled) {
            throw new ServiceUnavailableHttpException('Editor MCP is disabled');
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            throw new BadRequestHttpException('Not authenticated');
        }

        $stateKey = (string) Craft::$app->getRequest()->getBodyParam('stateKey');
        $decision = (string) Craft::$app->getRequest()->getBodyParam('decision');
        if (!$stateKey) {
            throw new BadRequestHttpException('Missing state');
        }

        $session = Craft::$app->getSession();
        $stashed = $session->get($stateKey);
        if (!$stashed || !is_array($stashed) || !isset($stashed['request'])) {
            throw new BadRequestHttpException('Authorization request expired');
        }
        // One-shot consume of state to defeat replay.
        $session->remove($stateKey);

        /** @var AuthorizationRequest $authRequest */
        $authRequest = unserialize($stashed['request'], [
            'allowed_classes' => [
                AuthorizationRequest::class,
                \westonhancock\editormcp\oauth\Entities\ClientEntity::class,
                \westonhancock\editormcp\oauth\Entities\ScopeEntity::class,
                \westonhancock\editormcp\oauth\Entities\UserEntity::class,
            ],
        ]);
        if (!$authRequest instanceof AuthorizationRequest) {
            throw new BadRequestHttpException('Bad state payload');
        }

        $authRequest->setUser(new UserEntity($user->id));
        $authRequest->setAuthorizationApproved($decision === 'allow');

        $server = Plugin::$plugin->tokens->getAuthorizationServer();
        $psrResponse = new PsrResponse();
        try {
            $psrResponse = $server->completeAuthorizationRequest($authRequest, $psrResponse);
        } catch (OAuthServerException $e) {
            $psrResponse = $e->generateHttpResponse($psrResponse);
        }

        return PsrBridge::toCraft($psrResponse);
    }
}
