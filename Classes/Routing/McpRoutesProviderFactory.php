<?php
declare(strict_types=1);

namespace Neos\Mcp\Routing;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Routing\RoutesProviderFactoryInterface;
use Neos\Mcp\McpServers;

#[Flow\Scope('singleton')]
final readonly class McpRoutesProviderFactory implements RoutesProviderFactoryInterface
{
    public function __construct(
        private McpServers $servers,
    ) {
    }

    /**
     * @param array<mixed> $options unused, the provider covers every server configured in Neos.Mcp.servers
     */
    public function createRoutesProvider(array $options): McpRoutesProvider
    {
        return new McpRoutesProvider($this->servers);
    }
}
