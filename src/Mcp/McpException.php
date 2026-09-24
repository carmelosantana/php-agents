<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

/**
 * Base of the errors McpClient throws. It extends RuntimeException so a host can catch one
 * type for "this server is unavailable right now". Catching it does not cover everything
 * `src/Mcp` can throw: McpServer's constructor throws \InvalidArgumentException for a
 * protocol version or a limit it refuses, and McpToolkit throws \UnexpectedValueException
 * when an injected namer answers with something unusable. Neither is a server's doing.
 *
 * The tree is the files `ls src/Mcp/Mcp*Exception.php` prints. McpAuthException and
 * McpRedirectException declare a constructor that builds the message from the method name
 * and the HTTP status; McpRedirectException keeps the Location on the exception rather than
 * in the text for the same reason. McpProtocolException, McpTransportException,
 * McpUnsupportedVersionException and this class declare no constructor and take a free-form
 * message from their caller, so for those the guarantee is only as good as the call site.
 * A previous exception carries a message of its own: HttpExchange chains the HTTP client's
 * exception to the McpTransportException it throws for a transport failure, and that message
 * can quote the URL or the host (spec §2, amendment 16, 2026-09-24).
 *
 * McpRpcException declares a constructor that reads the server's own text: it splices up
 * to 200 bytes of `error.message`, which is where a server that echoes a configured header
 * value (an Authorization credential, say) or a session id back would reach a message.
 * McpRpcException does not redact: McpClient::redact() replaces both, wherever they occur
 * literally, before constructing one (spec §2, amendment 3, 2026-09-21). Anything else in
 * src/ that builds an McpRpcException out of server text owes the same redaction.
 * A host should treat these messages as untrusted text and log them on that footing.
 */
class McpException extends \RuntimeException {}
