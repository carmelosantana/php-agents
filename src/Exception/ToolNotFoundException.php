<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Exception;

final class ToolNotFoundException extends \RuntimeException
{
    public static function forName(string $name): self
    {
        return new self(sprintf('Unknown tool: %s', $name));
    }
}
