<?php
declare(strict_types=1);

namespace westonhancock\editormcp\services;

use Craft;
use craft\elements\User;
use yii\base\Component;

/**
 * Runs a callable in a scope where Craft's auth identity is set to a specific
 * user. This makes element queries and saves honor that user's per-section /
 * per-element permissions natively — no parallel authorization code.
 *
 * The identity is restored on exit even if the callable throws.
 *
 * Note: this changes Craft::$app->getUser()->getIdentity() for the duration of
 * the callable. It does NOT start a session — there is no cookie, no CSRF token
 * issued. Pure in-process identity binding.
 */
class ImpersonationService extends Component
{
    public function runAs(User $user, callable $fn): mixed
    {
        $userComponent = Craft::$app->getUser();
        $previous = $userComponent->getIdentity();
        try {
            $userComponent->setIdentity($user);
            return $fn();
        } finally {
            $userComponent->setIdentity($previous);
        }
    }
}
