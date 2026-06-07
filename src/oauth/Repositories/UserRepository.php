<?php

declare(strict_types=1);

namespace westonhancock\editormcp\oauth\Repositories;

use Craft;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use westonhancock\editormcp\oauth\Entities\UserEntity;

/**
 * Proxies user identity lookups to Craft's user system.
 *
 * Note: this repository is only used by the password grant type, which is
 * disabled in v1 (OAuth 2.1 + PKCE only). The authorize endpoint resolves the
 * user via the Craft CP session instead — see OAuthController::authorize.
 */
class UserRepository implements UserRepositoryInterface
{
    /**
     * @param string $username
     * @param string $password
     * @param string $grantType
     */
    public function getUserEntityByUserCredentials(
        $username,
        $password,
        $grantType,
        ClientEntityInterface $clientEntity,
    ): ?UserEntityInterface {
        // Password grant is disabled in v1. Returning null forces the OAuth server
        // to reject any password-grant attempt that slips through configuration.
        return null;
    }

    public function getUserEntityByIdentifier(int|string $identifier): ?UserEntityInterface
    {
        $user = Craft::$app->getUsers()->getUserById((int) $identifier);
        if (!$user || $user->suspended || $user->pending || $user->locked) {
            return null;
        }
        return new UserEntity($user->id);
    }
}
