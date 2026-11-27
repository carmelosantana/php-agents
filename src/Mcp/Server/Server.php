<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp\Server;

final readonly class Server
{
    public const string UNKNOWN_TOOL = 'unknown tool';

    public function __construct(
        private ToolRegistry $registry,
        private string $name = 'kanboard-mcp-php',
        private string $version = '0.1.0',
    ) {}

    /**
     * Decode a single request, preserving the distinction between JSON objects and arrays.
     *
     * @return array<string, mixed>|null
     */
    public function dispatchJson(string $body, CallContext $ctx): ?array
    {
        try {
            $msg = json_decode($body, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->error(null, -32700, 'Parse error');
        }

        return $this->dispatch($msg instanceof \stdClass ? get_object_vars($msg) : [], $ctx);
    }

    /**
     * Return a JSON-RPC response, or null for a notification.
     *
     * @param array<array-key, mixed> $msg
     * @return array<string, mixed>|null
     */
    public function dispatch(array $msg, CallContext $ctx): ?array
    {
        $id = $msg['id'] ?? null;
        $method = $msg['method'] ?? null;
        if (
            ($msg['jsonrpc'] ?? null) !== '2.0'
            || !is_string($method)
            || ($id !== null && !is_int($id) && !is_string($id) && !(is_float($id) && is_finite($id)))
        ) {
            return $this->error(null, -32600, 'Invalid request');
        }

        if (!array_key_exists('id', $msg)) {
            return null;
        }

        $params = array_key_exists('params', $msg) ? $msg['params'] : new \stdClass();
        if (!is_array($params) && !$params instanceof \stdClass) {
            return $this->error($id, -32602, 'params must be an object or array');
        }

        return match ($method) {
            'initialize' => $this->result($id, [
                'protocolVersion' => Protocol::negotiate(((array) $params)['protocolVersion'] ?? null),
            ] + $this->metadata()),
            'server/discover' => $this->result($id, [
                'supportedVersions' => Protocol::VERSIONS,
            ] + $this->metadata()),
            'ping' => $this->result($id, new \stdClass()),
            'tools/list' => $this->result($id, [
                'tools' => array_map(
                    static fn (Tool $tool): array => $tool->definition(),
                    $this->registry->forProfile($ctx->profile)->list(),
                ),
            ]),
            'tools/call' => $this->callTool($id, $params, $ctx),
            default => $this->error($id, -32601, 'Method not found'),
        };
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        return [
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => $this->name, 'version' => $this->version],
        ];
    }

    /**
     * @param array<array-key, mixed>|\stdClass $params
     * @return array<string, mixed>
     */
    private function callTool(int|float|string|null $id, array|\stdClass $params, CallContext $ctx): array
    {
        $params = (array) $params;
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return $this->error($id, -32602, 'name must be a non-empty string');
        }

        $args = array_key_exists('arguments', $params) ? $params['arguments'] : new \stdClass();
        if (SchemaValidator::validate(['type' => 'object'], $args) !== null) {
            return $this->error($id, -32602, 'arguments must be an object');
        }

        $tool = $this->registry->forProfile($ctx->profile)->find($name);
        if ($tool === null) {
            return $this->result($id, $this->toolResult(self::UNKNOWN_TOOL, true));
        }

        $error = SchemaValidator::validate($tool->inputSchema, $args);
        if ($error !== null) {
            return $this->error($id, -32602, $error);
        }

        try {
            $output = ($tool->handler)(self::normalizeArguments((array) $args), $ctx);
            $text = json_encode($output, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (ToolError|\JsonException) {
            return $this->result($id, $this->toolResult('tool failed', true));
        }

        $result = $this->toolResult($text, false);
        if ($tool->outputSchema !== null && is_array($output)) {
            $result['structuredContent'] = $output;
        }

        return $this->result($id, $result);
    }

    /**
     * @param array<array-key, mixed> $args
     * @return array<array-key, mixed>
     */
    private static function normalizeArguments(array $args): array
    {
        return array_map(
            static fn (mixed $value): mixed => is_array($value) || $value instanceof \stdClass
                ? self::normalizeArguments((array) $value)
                : $value,
            $args,
        );
    }

    /** @return array<string, mixed> */
    private function toolResult(string $text, bool $isError): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError];
    }

    /** @return array<string, mixed> */
    private function result(int|float|string|null $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private function error(int|float|string|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
