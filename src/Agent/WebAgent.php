<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent;

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Toolkit\WebToolkit;

final class WebAgent extends AbstractAgent
{
    private WebToolkit $web;

    public function __construct(
        ProviderInterface $provider,
        ?string $searchEndpoint = null,
        ?string $searchApiKey = null,
    ) {
        parent::__construct($provider);
        $this->web = new WebToolkit($searchEndpoint, $searchApiKey);
        $this->addToolkit($this->web);
    }

    public function instructions(): string
    {
        return <<<PROMPT
        You are a web research agent. You fetch web pages, search the internet, and call APIs.

        ## Rules
        - Use web_search for broad information discovery.
        - Use http_request for specific URLs or API calls.
        - Summarize web content concisely — don't dump raw HTML.
        - Cite your sources with URLs.
        - When done, call the `done` tool with your findings.
        PROMPT;
    }

    public function requiredCapabilities(): array
    {
        return [ModelCapability::Text, ModelCapability::Tools];
    }
}
