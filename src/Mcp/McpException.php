<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

/**
 * Base of every error the MCP client throws. It extends RuntimeException so a host
 * can catch one type for "this server is unavailable right now".
 *
 * The constructors in this tree build their messages from the method name and the HTTP or
 * JSON-RPC status alone; none of them reads a configured header value or the stored session
 * id, and McpRedirectException keeps the Location on the exception rather than in the text
 * for the same reason. Two things qualify that. McpRpcException splices up to 200 bytes of
 * the server's own `error.message`, which is where a server that echoes a configured header
 * value (an Authorization credential, say) or a session id back would reach a message.
 * McpRpcException does not redact: McpClient::redact() removes both from that text before
 * constructing one (spec §2, amendment 3, 2026-09-21), and McpClient holds the only
 * construction of McpRpcException in src/ today, so anything new that builds one from server
 * text owes the same redaction. And McpProtocolException and McpTransportException take a free-form
 * message from their caller, so for those the guarantee is only as good as the call site.
 * A host should treat these messages as untrusted text and log them on that footing.
 */
class McpException extends \RuntimeException {}
