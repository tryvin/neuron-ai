<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Providers\AIProviderInterface;

final class AppAgent extends Agent
{
    public function __construct(
        protected McpConnector $connector,
        AIProviderInterface $aiProvider,
    ) {
        parent::__construct();
        $this->setAiProvider($aiProvider);
        $this->addTool($connector->tools());
    }

    public function connector(): McpConnector
    {
        return $this->connector;
    }

    /**
     * One connector configuration for the agent and for the app proxy: an app
     * view may only call the server that declared its tool.
     */
    public static function makeConnector(): McpConnector
    {
        $config = [
            'name' => self::env('MCP_NAME', 'demo'),
            'url' => self::env('MCP_URL', 'http://127.0.0.1:3001/mcp'),
            'timeout' => 30,
            'extensions' => [
                'io.modelcontextprotocol/ui' => [
                    'mimeTypes' => ['text/html;profile=mcp-app'],
                ],
            ],
        ];

        $token = \getenv('MCP_TOKEN');

        if (\is_string($token) && $token !== '') {
            $config['token'] = $token;
        }

        return McpConnector::make($config);
    }

    protected static function env(string $name, string $default): string
    {
        $value = \getenv($name);

        return \is_string($value) && $value !== '' ? $value : $default;
    }
}
