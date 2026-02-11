<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Message;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Enum\Role;

final readonly class UserMessage implements MessageInterface
{
    /**
     * @param string|array<array{type: string, text?: string, image_url?: array<string, mixed>}> $content
     */
    public function __construct(
        private string|array $content,
    ) {}

    public function role(): Role
    {
        return Role::User;
    }

    public function content(): string|array
    {
        return $this->content;
    }

    public function toolCalls(): array
    {
        return [];
    }

    public function toolCallId(): ?string
    {
        return null;
    }

    public function toArray(): array
    {
        return [
            'role' => 'user',
            'content' => $this->content,
        ];
    }

    /**
     * Create a multimodal message with text and images.
     *
     * @param string[] $imagePaths
     */
    public static function withImages(string $text, array $imagePaths): self
    {
        $content = [['type' => 'text', 'text' => $text]];

        foreach ($imagePaths as $path) {
            if (!file_exists($path)) {
                continue;
            }

            $fileContent = file_get_contents($path);
            if ($fileContent === false) {
                continue;
            }

            $base64 = base64_encode($fileContent);
            $mime = mime_content_type($path) ?: 'image/png';

            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => "data:{$mime};base64,{$base64}"],
            ];
        }

        return new self($content);
    }
}
