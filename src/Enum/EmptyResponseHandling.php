<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Enum;

/**
 * Policy for handling a provider turn that produced no content and no
 * tool calls.
 *
 * Some local serving stacks (notably Ollama with qwen/gemma thinking
 * models) route the entire completion into the reasoning channel and
 * leave content empty. The agent loop applies one of these strategies
 * instead of silently looping to max iterations.
 */
enum EmptyResponseHandling: string
{
    /** Legacy behavior: silently retry until max iterations. */
    case Ignore = 'ignore';

    /** Inject a corrective message and retry; exit with EmptyResponse after the retry cap. */
    case Nudge = 'nudge';

    /** Nudge retries first; on exhaustion return accumulated reasoning as the answer. */
    case NudgeThenFallback = 'nudge_then_fallback';

    /** Immediately return accumulated reasoning as the answer when present. */
    case Fallback = 'fallback';
}
