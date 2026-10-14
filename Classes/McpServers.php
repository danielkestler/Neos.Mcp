<?php
declare(strict_types=1);

namespace Neos\Mcp;

use Neos\Flow\Annotations as Flow;

/**
 * The MCP servers configured in Neos.Mcp.servers
 */
#[Flow\Scope('singleton')]
final class McpServers
{
    /**
     * @var array<string, array<string, mixed>|null>
     */
    #[Flow\InjectConfiguration(path: 'servers', package: 'Neos.Mcp')]
    protected array $servers = [];

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map('strval', array_keys(array_filter($this->servers, static fn ($server) => $server !== null)));
    }

    /**
     * The URI path of the server's endpoint, relative to the base URI and without leading or trailing slashes
     */
    public function uriPrefix(string $serverName): string
    {
        $uriPrefix = trim((string)($this->settings($serverName)['uriPrefix'] ?? ''), '/');
        if ($uriPrefix === '') {
            throw new \InvalidArgumentException(sprintf('The MCP server "%s" has no uriPrefix', $serverName), 1792000101);
        }
        return $uriPrefix;
    }

    public function title(string $serverName): string
    {
        return (string)($this->settings($serverName)['name'] ?? $serverName);
    }

    public function version(string $serverName): string
    {
        return (string)($this->settings($serverName)['version'] ?? '1.0.0');
    }

    public function instructions(string $serverName): ?string
    {
        $instructions = $this->settings($serverName)['instructions'] ?? null;
        return is_string($instructions) && $instructions !== '' ? $instructions : null;
    }

    /**
     * @return list<string> names from Neos.OpenApi.FlowAdapter.apis
     */
    public function apis(string $serverName): array
    {
        return self::enabledKeys($this->settings($serverName)['apis'] ?? []);
    }

    /**
     * @return list<string>
     */
    public function includedTags(string $serverName): array
    {
        return array_values(array_map('strval', (array)($this->settings($serverName)['tags']['include'] ?? [])));
    }

    /**
     * @return list<string>
     */
    public function excludedTags(string $serverName): array
    {
        return array_values(array_map('strval', (array)($this->settings($serverName)['tags']['exclude'] ?? [])));
    }

    /**
     * @return list<class-string> the object names of the PSR-15 middlewares around each operation call, in order
     */
    public function operationMiddlewares(string $serverName): array
    {
        $middlewares = array_filter((array)($this->settings($serverName)['operationMiddlewares'] ?? []), static fn ($middleware) => is_string($middleware) && $middleware !== '');
        /** @var list<class-string> */
        return array_values($middlewares);
    }

    /**
     * The key of the server in Neos.OAuth.protectedResources, null if it is open to anonymous requests
     */
    public function protectedResource(string $serverName): ?string
    {
        $protectedResource = $this->settings($serverName)['protectedResource'] ?? null;
        return is_string($protectedResource) && $protectedResource !== '' ? $protectedResource : null;
    }

    /**
     * @return list<string> the hosts the Origin or Host header may name, against DNS rebinding
     */
    public function allowedHosts(string $serverName): array
    {
        return array_values(array_map('strval', (array)($this->settings($serverName)['allowedHosts'] ?? [])));
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(string $serverName): array
    {
        $settings = $this->servers[$serverName] ?? null;
        if ($settings === null) {
            throw new \InvalidArgumentException(sprintf('There is no MCP server "%s" in Neos.Mcp.servers', $serverName), 1792000102);
        }
        return $settings;
    }

    /**
     * @return list<string>
     */
    private static function enabledKeys(mixed $map): array
    {
        return is_array($map) ? array_map('strval', array_keys(array_filter($map))) : [];
    }
}
