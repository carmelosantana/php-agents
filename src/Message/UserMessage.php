<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Message;

use CarmeloSantana\PHPAgents\Contract\ImageResolverInterface;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Image\ImageInputException;

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
     * Each path is read through $resolver (for example `new LocalImageResolver([$uploadsDir])`),
     * which decides what may be read and returns checked bytes; the data URI carries the
     * sniffed MIME type. Paths without a resolver are refused.
     *
     * @param string[] $imagePaths
     * @throws ImageInputException when paths are given without a resolver, or the resolver refuses one.
     */
    public static function withImages(string $text, array $imagePaths, ?ImageResolverInterface $resolver = null): self
    {
        $content = [['type' => 'text', 'text' => $text]];

        if ($imagePaths === []) {
            return new self($content);
        }

        if ($resolver === null) {
            throw new ImageInputException(
                'Image paths need an ImageResolverInterface; pass new LocalImageResolver([...allowed roots]).',
            );
        }

        foreach ($imagePaths as $path) {
            $image = $resolver->resolve($path);

            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => "data:{$image->mimeType};base64," . base64_encode($image->bytes)],
            ];
        }

        return new self($content);
    }
}
