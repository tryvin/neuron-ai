<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Plugins;

use JsonException;
use NeuronAI\Exceptions\PluginException;
use NeuronAI\MCP\Apps\McpAppSession;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Plugins\Plugin;
use NeuronAI\Skills\SkillTool;
use NeuronAI\Tests\Stubs\ArrayStorage;
use NeuronAI\Tools\HasMeta;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_get_contents;
use function implode;
use function json_encode;
use function parse_url;
use function serialize;
use function str_repeat;
use function stream_get_contents;
use function unserialize;
use function array_keys;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_HOST;

class PluginTest extends TestCase
{
    protected const STUBS = __DIR__.'/../Stubs/Plugins';

    /**
     * @throws JsonException
     */
    protected static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * A portable manifest with the given fields on top of the required ones.
     *
     * @param array<string, mixed> $fields
     *
     * @throws JsonException
     */
    protected static function manifest(array $fields = []): string
    {
        return self::json($fields + ['$schema' => Plugin::MANIFEST_SCHEMA, 'name' => 'acme']);
    }

    /**
     * A portable MCP configuration.
     *
     * @param array<string, mixed> $servers
     *
     * @throws JsonException
     */
    protected static function mcp(array $servers): string
    {
        return self::json(['$schema' => Plugin::MCP_SCHEMA, 'mcpServers' => $servers]);
    }

    protected function skill(string $name): string
    {
        return "---\nname: {$name}\ndescription: The {$name} skill.\n---\n\n# {$name}\n";
    }

    /**
     * A portable plugin with the given files, and a valid manifest unless the files provide one.
     *
     * @param array<string, string> $files
     *
     * @throws JsonException
     */
    protected function plugin(array $files = []): FakePlugin
    {
        return new FakePlugin(new ArrayStorage($files + ['plugin.json' => self::manifest()]));
    }

    /**
     * The names of the tools, in order.
     *
     * @param ToolInterface[] $tools
     *
     * @return string[]
     */
    protected function names(array $tools): array
    {
        return array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools);
    }

    public function test_loads_a_portable_plugin(): void
    {
        $plugin = $this->plugin([
            'skills/alpha/SKILL.md' => $this->skill('alpha'),
            'mcp.json' => self::mcp([
                'acme' => ['type' => 'streamable-http', 'url' => 'https://mcp.acme.example/mcp', 'headers' => ['X-Client' => 'neuron']],
            ]),
        ]);

        $this->assertSame([], $plugin->errors());
        // The MCP tools come first, so a tool named "skill" wins over the skill tool
        $this->assertSame(['server_tool', 'skill'], $this->names($plugin->tools()));
        $this->assertSame(
            [['url' => 'https://mcp.acme.example/mcp', 'headers' => ['X-Client' => 'neuron']]],
            $plugin->configs
        );
    }

    public function test_maps_the_sse_transport_to_the_asynchronous_connector(): void
    {
        $plugin = $this->plugin(['mcp.json' => self::mcp(['events' => ['type' => 'sse', 'url' => 'https://mcp.acme.example/sse']])]);
        $plugin->tools();

        $this->assertSame([], $plugin->errors());
        $this->assertSame(
            [['url' => 'https://mcp.acme.example/sse', 'headers' => [], 'async' => true]],
            $plugin->configs
        );
    }

    public function test_reports_unknown_manifest_fields_and_ignores_them(): void
    {
        $plugin = $this->plugin([
            'plugin.json' => self::manifest(['interface' => ['display_name' => 'Acme']]),
            'skills/alpha/SKILL.md' => $this->skill('alpha'),
        ]);

        $this->assertSame(["Plugin manifest 'plugin.json': the field 'interface' is unknown and has been ignored."], $plugin->errors());
        $this->assertSame(['skill'], $this->names($plugin->tools()));
    }

    public function test_ignores_the_extensions(): void
    {
        $this->assertSame([], $this->plugin(['plugin.json' => self::manifest(['extensions' => ['com.openai' => ['anything' => true]]])])->errors());
        $this->assertSame(
            ["Plugin manifest 'plugin.json': the field 'extensions' is not an object and has been ignored."],
            $this->plugin(['plugin.json' => self::manifest(['extensions' => 'nope'])])->errors()
        );
    }

    /**
     * @dataProvider invalid_manifests
     */
    public function test_rejects_an_invalid_portable_manifest(string $manifest, string $message): void
    {
        $this->expectException(PluginException::class);
        $this->expectExceptionMessage($message);

        $this->plugin(['plugin.json' => $manifest]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalid_manifests(): array
    {
        $schema = Plugin::MANIFEST_SCHEMA;
        $cases = [
            'not an object' => ['[]', 'must be a JSON object'],
            'invalid json' => ['{not json', 'is not valid JSON'],
            'missing schema' => [self::json(['name' => 'acme']), 'unsupported $schema'],
            'other schema version' => [self::json(['$schema' => 'https://agent-plugins.org/schemas/1.1.0/plugin.schema.json', 'name' => 'acme']), 'unsupported $schema'],
            'missing name' => [self::json(['$schema' => $schema]), "'name' is missing or invalid"],
            'version not a string' => [self::manifest(['version' => 1]), "the field 'version' must be a string"],
            'keywords not strings' => [self::manifest(['keywords' => ['a', 1]]), "the field 'keywords' must be an array of strings"],
            'author with an unknown field' => [self::manifest(['author' => ['name' => 'Acme', 'nick' => 'acme']]), "the field 'author' must be an object"],
            'author not an object' => [self::manifest(['author' => 'Acme']), "the field 'author' must be an object"],
            'extensions namespace not an object' => [self::manifest(['extensions' => ['com.openai' => 'nope']]), "every namespace of the field 'extensions' must be an object"],
        ];

        foreach (['Acme', 'acme--tools', 'acme..tools', '-acme', 'acme.', str_repeat('a', 65)] as $name) {
            $cases["invalid name '{$name}'"] = [self::manifest(['name' => $name]), "'name' is missing or invalid"];
        }

        return $cases;
    }

    public function test_loads_a_legacy_codex_plugin(): void
    {
        $plugin = new FakePlugin(new ArrayStorage([
            '.codex-plugin/plugin.json' => self::json(['name' => 'notion', 'description' => 'Search, read and update Notion.', 'mcpServers' => './config/mcp.json', 'skills' => './my-skills/']),
            'config/mcp.json' => self::json(['mcpServers' => ['notion' => [
                'type' => 'http',
                'url' => 'https://mcp.notion.com/mcp',
                'oauth_resource' => 'https://mcp.notion.com',
            ]]]),
            'my-skills/notion-search/SKILL.md' => $this->skill('notion-search'),
            // The default locations are not read
            '.mcp.json' => 'not even json',
            'skills/ignored/SKILL.md' => $this->skill('ignored'),
        ]));

        $this->assertSame([], $plugin->errors());
        $this->assertSame('notion', $plugin->name());
        $this->assertSame('Search, read and update Notion.', $plugin->description());
        $this->assertSame(['notion-search'], array_keys($plugin->skills()));
        $this->assertSame(['server_tool', 'skill'], $this->names($plugin->tools()));
        $this->assertSame([['url' => 'https://mcp.notion.com/mcp', 'headers' => []]], $plugin->configs);
    }

    public function test_prefers_the_codex_manifest_over_the_claude_one(): void
    {
        $plugin = new FakePlugin(new ArrayStorage([
            '.codex-plugin/plugin.json' => self::json(['name' => 'codex', 'skills' => 'codex-skills']),
            '.claude-plugin/plugin.json' => self::json(['name' => 'claude', 'skills' => 'claude-skills']),
            'codex-skills/alpha/SKILL.md' => $this->skill('alpha'),
            'claude-skills/beta/SKILL.md' => $this->skill('beta'),
        ]));

        $this->assertSame([], $plugin->errors());

        $description = $plugin->tools()[0]->getDescription();

        $this->assertNotNull($description);
        $this->assertStringContainsString('- alpha:', $description);
        $this->assertStringNotContainsString('- beta:', $description);
    }

    public function test_loads_a_legacy_claude_plugin(): void
    {
        $plugin = new FakePlugin(new ArrayStorage(['.claude-plugin/plugin.json' => self::json(['name' => 'claude'])]));

        $this->assertSame([], $plugin->errors());
        $this->assertSame([], $plugin->tools());
    }

    public function test_ignores_the_legacy_layout_when_plugin_json_exists(): void
    {
        $plugin = $this->plugin([
            '.codex-plugin/plugin.json' => 'not even json',
            '.mcp.json' => 'not even json',
            'skills/alpha/SKILL.md' => $this->skill('alpha'),
        ]);

        $this->assertSame([], $plugin->errors());
        $this->assertSame(['skill'], $this->names($plugin->tools()));
    }

    public function test_throws_without_any_manifest(): void
    {
        $this->expectException(PluginException::class);
        $this->expectExceptionMessage('No plugin manifest found');

        new FakePlugin(new ArrayStorage(['skills/alpha/SKILL.md' => $this->skill('alpha')]));
    }

    /**
     * @dataProvider invalid_legacy_manifests
     */
    public function test_rejects_an_invalid_legacy_manifest(string $manifest, string $message): void
    {
        $this->expectException(PluginException::class);
        $this->expectExceptionMessage($message);

        new FakePlugin(new ArrayStorage(['.codex-plugin/plugin.json' => $manifest]));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalid_legacy_manifests(): array
    {
        return [
            'invalid json' => ['{not json', "Plugin file '.codex-plugin/plugin.json' is not valid JSON"],
            'missing name' => [self::json(['version' => '1.0.0']), "'name' is missing or empty"],
            'blank name' => [self::json(['name' => '  ']), "'name' is missing or empty"],
            'not an object' => [self::json(['acme']), "'name' is missing or empty"],
        ];
    }

    public function test_falls_back_to_the_default_legacy_paths_and_reports(): void
    {
        $plugin = new FakePlugin(new ArrayStorage([
            '.claude-plugin/plugin.json' => self::json(['name' => 'claude', 'skills' => ['not', 'a', 'path'], 'mcpServers' => '../outside.json']),
            'skills/alpha/SKILL.md' => $this->skill('alpha'),
        ]));

        $this->assertSame([
            "Plugin manifest '.claude-plugin/plugin.json': the field 'skills' must be a relative path, 'skills' used instead.",
            "Plugin manifest '.claude-plugin/plugin.json': the field 'mcpServers' must be a relative path, '.mcp.json' used instead.",
        ], $plugin->errors());
        $this->assertSame(['skill'], $this->names($plugin->tools()));
    }

    public function test_a_missing_mcp_configuration_is_not_an_error(): void
    {
        $plugin = $this->plugin(['skills/alpha/SKILL.md' => $this->skill('alpha')]);

        $this->assertSame([], $plugin->errors());
        $this->assertSame(['skill'], $this->names($plugin->tools()));
    }

    public function test_throws_when_the_mcp_configuration_is_not_valid_json(): void
    {
        $this->expectException(PluginException::class);
        $this->expectExceptionMessage("Plugin file 'mcp.json' is not valid JSON");

        $this->plugin(['mcp.json' => '{not json']);
    }

    /**
     * @dataProvider invalid_portable_configurations
     */
    public function test_disables_the_mcp_servers_on_an_invalid_portable_configuration(string $config): void
    {
        $plugin = $this->plugin(['skills/alpha/SKILL.md' => $this->skill('alpha'), 'mcp.json' => $config]);

        // The other components of the plugin keep loading
        $this->assertSame(['skill'], $this->names($plugin->tools()));
        $this->assertCount(1, $plugin->errors());
        $this->assertStringStartsWith("MCP configuration 'mcp.json':", implode("\n", $plugin->errors()));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalid_portable_configurations(): array
    {
        return [
            'not an object' => ['[]'],
            'missing schema' => [self::json(['mcpServers' => []])],
            'other schema version' => [self::json(['$schema' => 'https://agent-plugins.org/schemas/1.1.0/mcp.schema.json', 'mcpServers' => []])],
            'extra field' => [self::json(['$schema' => Plugin::MCP_SCHEMA, 'mcpServers' => [], 'extra' => true])],
            'servers not an object' => [self::json(['$schema' => Plugin::MCP_SCHEMA, 'mcpServers' => []])],
        ];
    }

    public function test_reports_a_legacy_configuration_without_servers(): void
    {
        $plugin = new FakePlugin(new ArrayStorage([
            '.codex-plugin/plugin.json' => self::json(['name' => 'acme']),
            '.mcp.json' => self::json(['servers' => []]),
        ]));

        $this->assertSame(["MCP configuration '.mcp.json': 'mcpServers' must be an object. The MCP servers have not been loaded."], $plugin->errors());
    }

    public function test_skips_the_unsupported_transports_and_reports_them(): void
    {
        $plugin = new FakePlugin(new ArrayStorage([
            '.claude-plugin/plugin.json' => self::json(['name' => 'acme']),
            '.mcp.json' => self::json(['mcpServers' => [
                'local' => ['command' => 'npx', 'args' => ['-y', 'server']],
                'socket' => ['type' => 'ws', 'url' => 'https://mcp.acme.example/ws'],
                'remote' => ['type' => 'sdk'],
                'mystery' => ['type' => 'carrier-pigeon', 'url' => 'https://mcp.acme.example/mcp'],
                'stdio' => ['type' => 'stdio', 'command' => 'npx'],
            ]]),
        ]));

        $this->assertSame([], $plugin->configs);
        $this->assertSame([
            "MCP server 'local' in '.mcp.json' skipped: stdio servers are not supported.",
            "MCP server 'socket' in '.mcp.json' skipped: ws servers are not supported.",
            "MCP server 'remote' in '.mcp.json' skipped: sdk servers are not supported.",
            "MCP server 'mystery' in '.mcp.json' skipped: the transport type is unknown.",
            "MCP server 'stdio' in '.mcp.json' skipped: stdio servers are not supported.",
        ], $plugin->errors());
    }

    public function test_skips_an_invalid_entry_and_loads_its_siblings(): void
    {
        $plugin = $this->plugin(['mcp.json' => self::mcp([
            'extra-field' => ['type' => 'streamable-http', 'url' => 'https://mcp.acme.example/mcp', 'command' => 'npx'],
            'no-url' => ['type' => 'streamable-http'],
            'not-an-object' => 'https://mcp.acme.example/mcp',
            'good' => ['type' => 'streamable-http', 'url' => 'https://mcp.acme.example/mcp'],
        ])]);
        $plugin->tools();

        $this->assertSame([['url' => 'https://mcp.acme.example/mcp', 'headers' => []]], $plugin->configs);
        $this->assertSame([
            "MCP server 'extra-field' in 'mcp.json' skipped: the field 'command' is not allowed for a streamable-http server.",
            "MCP server 'no-url' in 'mcp.json' skipped: the url is missing or invalid.",
            "MCP server 'not-an-object' in 'mcp.json' skipped: the entry must be an object.",
        ], $plugin->errors());
    }

    /**
     * @dataProvider urls
     */
    public function test_applies_the_url_rules(string $url, bool $accepted): void
    {
        $plugin = $this->plugin(['mcp.json' => self::mcp(['acme' => ['type' => 'streamable-http', 'url' => $url]])]);
        $plugin->tools();

        $this->assertCount($accepted ? 1 : 0, $plugin->configs);
        $this->assertCount($accepted ? 0 : 1, $plugin->errors());
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function urls(): array
    {
        return [
            'https' => ['https://mcp.acme.example/mcp', true],
            'http on localhost' => ['http://localhost:8080/mcp', true],
            'http on 127.0.0.1' => ['http://127.0.0.1:8080/mcp', true],
            'http on the loopback range' => ['http://127.1.2.3/mcp', true],
            'http on ::1' => ['http://[::1]:8080/mcp', true],
            'http elsewhere' => ['http://mcp.acme.example/mcp', false],
            'http on a look-alike' => ['http://1270.0.0.1/mcp', false],
            'ftp' => ['ftp://mcp.acme.example/mcp', false],
            'user info' => ['https://user:pass@mcp.acme.example/mcp', false],
            'fragment' => ['https://mcp.acme.example/mcp#tools', false],
            'relative' => ['/mcp', false],
            'not a url' => ['not a url', false],
        ];
    }

    /**
     * @dataProvider header_sets
     */
    public function test_applies_the_header_rules(mixed $headers, bool $accepted): void
    {
        $plugin = $this->plugin(['mcp.json' => self::mcp([
            'acme' => ['type' => 'streamable-http', 'url' => 'https://mcp.acme.example/mcp', 'headers' => $headers],
        ])]);
        $plugin->tools();

        $this->assertCount($accepted ? 1 : 0, $plugin->configs);
        $this->assertCount($accepted ? 0 : 1, $plugin->errors());
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function header_sets(): array
    {
        return [
            'tokens and printable values' => [['Authorization' => 'Bearer token', 'X-Empty' => ''], true],
            'tab in the value' => [['X-Client' => "acme\t1"], true],
            'space in the name' => [['X Client' => 'acme'], false],
            'line break in the value' => [['X-Client' => "acme\r\nX-Injected: 1"], false],
            'non-string value' => [['X-Client' => 1], false],
            'duplicate name' => [['X-Client' => 'a', 'x-client' => 'b'], false],
            'not an object' => [['acme'], false],
        ];
    }

    public function test_reports_a_server_that_fails_to_connect_and_keeps_loading(): void
    {
        $plugin = $this->plugin([
            'skills/alpha/SKILL.md' => $this->skill('alpha'),
            'mcp.json' => self::mcp([
                'down' => ['type' => 'streamable-http', 'url' => 'https://down.acme.example/mcp'],
                'up' => ['type' => 'streamable-http', 'url' => 'https://mcp.acme.example/mcp'],
            ]),
        ]);
        $plugin->offline = 'down.acme.example';

        // The other server and the skills keep loading
        $this->assertSame(['server_tool', 'skill'], $this->names($plugin->tools()));
        $this->assertSame(["MCP server 'down': Connection refused."], $plugin->errors());
    }

    public function test_merges_the_headers_of_server_over_the_ones_of_the_plugin_and_contacts_it_once(): void
    {
        $plugin = $this->plugin(['mcp.json' => self::mcp([
            'acme' => ['type' => 'streamable-http', 'url' => 'https://mcp.acme.example/mcp', 'headers' => ['X-Client' => 'neuron', 'X-Version' => '1']],
        ])]);
        $plugin->server('acme', ['token' => 'secret', 'headers' => ['x-client' => 'override', 'X-Extra' => 'yes']]);
        $plugin->tools();
        $plugin->tools();

        // A header of the override replaces the header of the plugin with the same name, case-insensitively
        $this->assertSame([
            ['url' => 'https://mcp.acme.example/mcp', 'headers' => ['X-Version' => '1', 'x-client' => 'override', 'X-Extra' => 'yes'], 'token' => 'secret'],
        ], $plugin->configs);
    }

    public function test_lists_what_the_plugin_declares(): void
    {
        $plugin = $this->plugin([
            'plugin.json' => self::manifest(['description' => 'Search the Acme documentation.']),
            'skills/alpha/SKILL.md' => $this->skill('alpha'),
            'mcp.json' => self::mcp([
                'acme' => ['type' => 'streamable-http', 'url' => 'https://mcp.acme.example/mcp', 'headers' => ['X-Client' => 'neuron']],
                'events' => ['type' => 'sse', 'url' => 'https://mcp.acme.example/sse'],
            ]),
        ]);

        $this->assertSame('acme', $plugin->name());
        $this->assertSame('Search the Acme documentation.', $plugin->description());
        $this->assertSame(['alpha'], array_keys($plugin->skills()));
        $this->assertSame([
            'acme' => ['url' => 'https://mcp.acme.example/mcp', 'headers' => ['X-Client' => 'neuron']],
            'events' => ['url' => 'https://mcp.acme.example/sse', 'headers' => [], 'async' => true],
        ], $plugin->servers());

        // All of it is known before contacting anything
        $this->assertSame([], $plugin->configs);
    }

    public function test_passes_credentials_to_the_servers_of_a_plugin_you_did_not_write(): void
    {
        $plugin = $this->plugin(['mcp.json' => self::mcp([
            'acme' => ['type' => 'streamable-http', 'url' => 'https://mcp.acme.example/mcp'],
            'events' => ['type' => 'sse', 'url' => 'https://mcp.acme.example/sse'],
        ])]);

        // The names come from servers(): nobody has to read the files of the plugin
        foreach ($plugin->servers() as $name => $config) {
            $plugin->server($name, ['token' => 'token-for-'.parse_url((string) $config['url'], PHP_URL_HOST)]);
        }

        // A name that does not exist is ignored
        $plugin->server('unknown', ['token' => 'ignored']);
        $plugin->tools();

        $this->assertSame([
            ['url' => 'https://mcp.acme.example/mcp', 'headers' => [], 'token' => 'token-for-mcp.acme.example'],
            ['url' => 'https://mcp.acme.example/sse', 'headers' => [], 'async' => true, 'token' => 'token-for-mcp.acme.example'],
        ], $plugin->configs);
    }

    public function test_exposes_the_manifest_as_an_array(): void
    {
        $plugin = new FakePlugin(new ArrayStorage([
            '.codex-plugin/plugin.json' => self::json(['name' => 'canva', 'interface' => ['displayName' => 'Canva', 'logo' => './assets/logo.svg']]),
        ]));

        $manifest = $plugin->manifest();

        $this->assertSame('canva', $manifest['name']);
        $this->assertSame(['displayName' => 'Canva', 'logo' => './assets/logo.svg'], $manifest['interface']);
        $this->assertNull($plugin->description());
    }

    public function test_streams_an_asset_of_the_plugin(): void
    {
        $plugin = new FakePlugin(new ArrayStorage([
            'plugin.json' => self::manifest(),
            'assets/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
        ]));

        $stream = $plugin->openAsset('./assets/logo.svg');

        $this->assertIsResource($stream);
        $this->assertSame('<svg xmlns="http://www.w3.org/2000/svg"/>', stream_get_contents($stream));
        $this->assertNull($plugin->openAsset('assets/missing.svg'));
        $this->assertNull($plugin->openAsset('../secret.svg'));
        $this->assertNull($plugin->openAsset('/etc/passwd'));
    }

    public function test_contributes_no_skill_tool_without_valid_skills(): void
    {
        $plugin = $this->plugin(['skills/broken/SKILL.md' => "# Not a skill\n"]);

        $this->assertSame([], $plugin->tools());
        $this->assertSame(["Skill 'broken': SKILL.md has no frontmatter block, or it is not a mapping."], $plugin->errors());
    }

    public function test_loads_a_portable_plugin_from_a_directory(): void
    {
        $plugin = new FakePlugin(self::STUBS.'/portable');

        $this->assertSame([], $plugin->errors());
        $this->assertSame(['server_tool', 'skill'], $this->names($plugin->tools()));
        $this->assertSame(
            [['url' => 'https://mcp.acme.example/mcp', 'headers' => ['X-Client' => 'neuron']]],
            $plugin->configs
        );
    }

    public function test_loads_a_legacy_plugin_from_a_directory(): void
    {
        // Constructing a plugin does not contact its MCP servers
        $this->assertSame([], Plugin::make(self::STUBS.'/legacy-notion')->errors());

        $plugin = new FakePlugin(self::STUBS.'/legacy-notion');
        $skill = $plugin->tools()[1];

        $this->assertSame([['url' => 'https://mcp.notion.com/mcp', 'headers' => []]], $plugin->configs);

        // Branding comes from the interface block of the legacy manifest, as a plugin-relative path
        $this->assertSame('notion', $plugin->name());
        $logo = $plugin->manifest()['interface']['logo'];
        $this->assertSame('./assets/logo.svg', $logo);

        $stream = $plugin->openAsset($logo);

        $this->assertIsResource($stream);
        $this->assertSame(file_get_contents(self::STUBS.'/legacy-notion/assets/logo.svg'), stream_get_contents($stream));

        $this->assertInstanceOf(SkillTool::class, $skill);

        $description = $skill->getDescription();

        $this->assertNotNull($description);
        $this->assertStringContainsString('- notion-spec-to-implementation: Turn a Notion spec into an implementation plan.', $description);
        // The skill reads the files bundled in its folder
        $this->assertStringStartsWith('# Spec parsing', ($skill)('notion-spec-to-implementation', 'reference/spec-parsing.md'));
    }

    public function test_reuses_the_connector_that_provided_the_tools(): void
    {
        $plugin = $this->plugin([
            'mcp.json' => self::mcp([
                'acme' => ['type' => 'streamable-http', 'url' => 'https://mcp.acme.example/mcp'],
            ]),
        ]);

        $this->assertSame(['server_tool'], $this->names($plugin->tools()));
        $this->assertCount(1, $plugin->builtConnectors);

        $connector = $plugin->connectorFor('acme');

        $this->assertSame($plugin->builtConnectors[0], $connector);
        $this->assertSame($connector, $plugin->connectorFor('acme'));
        $this->assertCount(1, $plugin->builtConnectors);

        $session = $plugin->appSession('acme', [
            'view' => 'view_1',
            'tool' => 'server_tool',
            'callId' => 7,
        ]);

        $this->assertInstanceOf(McpAppSession::class, $session);
        $this->assertSame($connector->name(), $session->serverName());
        $this->assertSame('server_tool', $session->toolName());
        $this->assertCount(1, $plugin->builtConnectors);

        // The session reads the tool from the connector that already listed it.
        // A second connector would need another tools/list response.
        $initialized = $session->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'ui/initialize',
            'params' => ['appInfo' => ['name' => 'demo', 'version' => '1.0.0']],
        ]);

        $this->assertSame('server_tool', $initialized->response['result']['hostContext']['toolInfo']['tool']['name']);
    }

    public function test_app_sessions_are_bound_to_one_plugin_server(): void
    {
        $plugin = $this->plugin([
            'mcp.json' => self::mcp([
                'acme' => ['type' => 'streamable-http', 'url' => 'https://mcp.acme.example/mcp'],
                'other' => ['type' => 'streamable-http', 'url' => 'https://mcp.other.example/mcp'],
            ]),
        ]);

        $plugin->tools();

        $session = $plugin->appSession('other', ['view' => 'view_2', 'tool' => 'server_tool']);

        $this->assertSame($plugin->builtConnectors[1]->name(), $session->serverName());
        $this->assertCount(2, $plugin->builtConnectors);
    }

    public function test_an_unknown_server_has_no_connector_or_session(): void
    {
        $plugin = $this->plugin();

        $this->expectException(PluginException::class);

        $plugin->appSession('missing', ['view' => 'view_1', 'tool' => 'server_tool']);
    }

    public function test_prefixes_the_mcp_tools_and_records_the_plugin_identity(): void
    {
        $plugin = $this->plugin([
            'skills/alpha/SKILL.md' => $this->skill('alpha'),
            'mcp.json' => self::mcp([
                'acme' => ['type' => 'streamable-http', 'url' => 'https://mcp.acme.example/mcp'],
            ]),
        ])->prefix('acme_');

        $tools = $plugin->tools();

        // Only the MCP tools carry the prefix
        $this->assertSame(['acme_server_tool', 'skill'], $this->names($tools));

        $tool = $tools[0];

        $this->assertInstanceOf(HasMeta::class, $tool);

        $meta = $tool->getMeta();

        $this->assertSame('acme', $meta['mcp']['plugin']);
        $this->assertArrayHasKey('server', $meta['mcp']);
    }

    public function test_survives_serialization_and_rebuilds_connectors_lazily(): void
    {
        $plugin = $this->plugin([
            'skills/alpha/SKILL.md' => $this->skill('alpha'),
            'mcp.json' => self::mcp([
                'acme' => ['type' => 'streamable-http', 'url' => 'https://mcp.acme.example/mcp'],
            ]),
        ])->prefix('acme_')->server('acme', ['token' => 'secret']);

        // The tools and the connectors are not serialized
        $this->assertStringNotContainsString('McpConnector', serialize($plugin));

        $restored = unserialize(serialize($plugin));

        $this->assertInstanceOf(FakePlugin::class, $restored);
        $this->assertSame([], $restored->configs);
        $this->assertSame(['acme_server_tool', 'skill'], $this->names($restored->tools()));
        $this->assertSame(
            [['url' => 'https://mcp.acme.example/mcp', 'headers' => [], 'token' => 'secret']],
            $restored->configs
        );

        $tool = $restored->tools()[0];

        $this->assertInstanceOf(HasMeta::class, $tool);
        $this->assertSame('acme', $tool->getMeta()['mcp']['plugin']);
    }

    public function test_it_does_not_rebuild_a_connector_that_failed(): void
    {
        $plugin = new class (new ArrayStorage([
            'plugin.json' => self::manifest(),
            'mcp.json' => self::mcp([
                'acme' => ['type' => 'streamable-http', 'url' => 'https://mcp.acme.example/mcp'],
            ]),
        ])) extends FakePlugin {
            public int $attempts = 0;

            protected function connector(array $config): McpConnector
            {
                $this->attempts++;

                throw new PluginException('Connection refused');
            }
        };

        $this->assertSame([], $plugin->tools());
        $this->assertSame(["MCP server 'acme': Connection refused."], $plugin->errors());

        try {
            $plugin->connectorFor('acme');
            $this->fail('The stored failure must be rethrown.');
        } catch (PluginException $exception) {
            $this->assertSame('Connection refused', $exception->getMessage());
        }

        $this->assertSame(1, $plugin->attempts);
    }
}
