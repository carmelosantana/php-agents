<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Exception;

use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

/** A status-only HttpStatusException for a 4xx answer. */
final class ClientHttpStatusException extends HttpStatusException implements ClientExceptionInterface {}
