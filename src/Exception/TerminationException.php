<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Exception;

/**
 * Thrown by a tool to signal that the agent loop should terminate immediately.
 *
 * When a tool throws this exception, AbstractAgent catches it, records the
 * message as a successful tool result, and returns an Output without
 * continuing to the next iteration. This allows tools like "restart" or
 * "shutdown" to cleanly exit the agent loop while still persisting the
 * conversation state.
 *
 * The exception message is used as the tool result content and the final
 * Output content.
 */
final class TerminationException extends \RuntimeException {}
