<?php

declare(strict_types=1);

use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\Messages\Message;

/**
 * Local JSON records for the example: one Neuron chat history per conversation
 * and small metadata files for view sidecars.
 */
final class Store
{
    protected string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? __DIR__.'/var';

        foreach (['conversations', 'views', 'history'] as $directory) {
            if (!\is_dir($this->root.'/'.$directory)) {
                \mkdir($this->root.'/'.$directory, 0o777, true);
            }
        }
    }

    public function history(string $conversationId): FileChatHistory
    {
        return new FileChatHistory($this->root.'/history', $conversationId);
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(string $conversationId): array
    {
        $data = $this->read($this->root.'/conversations/'.$conversationId.'.json');

        return \is_array($data) ? $data : ['views' => [], 'stagedContext' => null];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function saveMetadata(string $conversationId, array $metadata): void
    {
        $this->write($this->root.'/conversations/'.$conversationId.'.json', $metadata);
    }

    public function nextViewId(string $conversationId): string
    {
        $metadata = $this->metadata($conversationId);
        $views = \is_array($metadata['views'] ?? null) ? $metadata['views'] : [];

        return 'view_'.$conversationId.'_'.(\count($views) + 1);
    }

    /**
     * @param array<string, mixed> $view
     */
    public function addView(string $conversationId, array $view): void
    {
        $metadata = $this->metadata($conversationId);
        $views = \is_array($metadata['views'] ?? null) ? $metadata['views'] : [];
        $views[] = $view['id'];
        $metadata['views'] = $views;
        $this->saveMetadata($conversationId, $metadata);
        $this->saveView($view);
    }

    /**
     * @param array<string, mixed> $view
     */
    public function saveView(array $view): void
    {
        $this->write($this->root.'/views/'.$view['id'].'.json', $view);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findView(string $viewId): ?array
    {
        $data = $this->read($this->root.'/views/'.$viewId.'.json');

        return \is_array($data) ? $data : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function conversation(string $conversationId): array
    {
        $metadata = $this->metadata($conversationId);
        $viewIds = \is_array($metadata['views'] ?? null) ? $metadata['views'] : [];
        $views = [];

        foreach ($viewIds as $viewId) {
            $view = \is_string($viewId) ? $this->findView($viewId) : null;

            if (\is_array($view)) {
                $views[] = $view;
            }
        }

        $messages = \array_map(
            static fn (Message $message): array => $message->jsonSerialize(),
            $this->history($conversationId)->getMessages()
        );

        return [
            'id' => $conversationId,
            'messages' => $messages,
            'views' => $views,
            'stagedContext' => $metadata['stagedContext'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function read(string $path): ?array
    {
        if (!\is_file($path)) {
            return null;
        }

        $contents = \file_get_contents($path);
        $data = \is_string($contents) ? \json_decode($contents, true) : null;

        return \is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function write(string $path, array $data): void
    {
        \file_put_contents($path, \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), \LOCK_EX);
    }
}
