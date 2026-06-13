<?php

declare(strict_types=1);

namespace westonhancock\editormcp\tools;

class ToolException extends \RuntimeException
{
    /**
     * JSON-RPC error codes:
     *   -32700 parse, -32600 invalid request, -32601 method not found,
     *   -32602 invalid params, -32603 internal,
     *   -32001 unauthorized, -32004 forbidden (insufficient scope or permission),
     *   -32008 not found, -32016 validation, -32020 rate limited
     */
    public function __construct(
        private readonly int $jsonRpcCode,
        string $message,
        private readonly ?array $data = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getJsonRpcCode(): int
    {
        return $this->jsonRpcCode;
    }

    public function getData(): ?array
    {
        return $this->data;
    }
}
