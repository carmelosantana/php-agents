<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;

/** Usage and request metadata for a single inference request. */
final readonly class UsageReport
{
    /** @internal Carries the public operation to the request site. */
    public const string OPERATION_OPTION = '__php_agents_usage_operation';

    public function __construct(
        public string $operation,
        public string $provider,
        public string $requestedModel,
        public string $model,
        public ?Usage $usage,
        public ?string $requestId,
        public ?string $responseId,
        public ProviderFinishReason $finishReason,
        public int $durationMs,
        public bool $failed,
    ) {}
}
