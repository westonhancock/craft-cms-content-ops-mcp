<?php
declare(strict_types=1);

namespace westonhancock\editormcp\oauth\Entities;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

class ClientEntity implements ClientEntityInterface
{
    use EntityTrait;
    use ClientTrait;

    /** Internal numeric PK from editormcp_clients */
    public int $internalId;

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setRedirectUri(array|string $uri): void
    {
        $this->redirectUri = $uri;
    }

    public function setConfidential(bool $confidential): void
    {
        $this->isConfidential = $confidential;
    }
}
