<?php

declare(strict_types=1);

namespace westonhancock\editormcp\oauth\Entities;

use League\OAuth2\Server\Entities\UserEntityInterface;

class UserEntity implements UserEntityInterface
{
    public function __construct(private readonly int|string $id)
    {
    }

    public function getIdentifier(): int|string
    {
        return $this->id;
    }
}
