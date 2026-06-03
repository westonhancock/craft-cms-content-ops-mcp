<?php
declare(strict_types=1);

namespace westonhancock\editormcp\tools;

interface Tool
{
    public function name(): string;

    public function description(): string;

    /** JSON Schema for the tool's `arguments` */
    public function inputSchema(): array;

    /**
     * Execute the tool body. Caller (the registry) handles auth + scope + audit;
     * the tool body assumes Craft's identity has already been set to the user.
     *
     * @return array result payload (serialized to JSON for the MCP `content`)
     */
    public function execute(array $args): array;
}
