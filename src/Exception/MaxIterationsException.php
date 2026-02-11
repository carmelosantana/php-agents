<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Exception;

final class MaxIterationsException extends \RuntimeException
{
    public static function reached(int $iterations): self
    {
        return new self(sprintf('Agent reached maximum iterations (%d) without completing.', $iterations));
    }
}
