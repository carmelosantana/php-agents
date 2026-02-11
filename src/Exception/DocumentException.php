<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Exception;

final class DocumentException extends \RuntimeException
{
    public static function missingEmbedding(): self
    {
        return new self('Document must have embedding set');
    }
}
