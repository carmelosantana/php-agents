<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Exception;

use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

/** A status-only HttpStatusException for a 5xx answer. */
final class ServerHttpStatusException extends HttpStatusException implements ServerExceptionInterface {}
