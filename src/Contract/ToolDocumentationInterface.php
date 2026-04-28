<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

interface ToolDocumentationInterface
{
    public function useWhen(): ?string;

    /**
     * @return list<string>
     */
    public function examples(): array;
}