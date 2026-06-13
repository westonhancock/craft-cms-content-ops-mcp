<?php

declare(strict_types=1);

namespace westonhancock\editormcp\web;

use Craft;
use craft\web\Response as CraftResponse;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Tiny adapter between Craft's request/response (Yii) and PSR-7.
 *
 * league/oauth2-server speaks PSR-7 throughout, so every OAuth endpoint
 * round-trips through here.
 */
final class PsrBridge
{
    public static function fromGlobals(): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
        return $creator->fromGlobals();
    }

    public static function toCraft(ResponseInterface $psr): CraftResponse
    {
        $response = Craft::$app->getResponse();
        $response->statusCode = $psr->getStatusCode();
        $response->format = CraftResponse::FORMAT_RAW;
        foreach ($psr->getHeaders() as $name => $values) {
            $response->getHeaders()->set($name, implode(', ', $values));
        }
        $response->content = (string) $psr->getBody();
        return $response;
    }
}
