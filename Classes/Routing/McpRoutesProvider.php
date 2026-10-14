<?php
declare(strict_types=1);

namespace Neos\Mcp\Routing;

use Neos\Flow\Mvc\Routing\Route;
use Neos\Flow\Mvc\Routing\Routes;
use Neos\Flow\Mvc\Routing\RoutesProviderInterface;
use Neos\Mcp\McpServers;

/**
 * One route per server configured in Neos.Mcp.servers, for every HTTP method: the controller answers those MCP
 * doesn't use with a 405
 */
final readonly class McpRoutesProvider implements RoutesProviderInterface
{
    public function __construct(
        private McpServers $servers,
    ) {
    }

    public function getRoutes(): Routes
    {
        $routes = [];
        foreach ($this->servers->names() as $serverName) {
            $route = new Route();
            $route->setName(sprintf('MCP server %s', $serverName));
            $route->setUriPattern($this->servers->uriPrefix($serverName));
            $route->setDefaults([
                '@package' => 'Neos.Mcp',
                '@controller' => 'Mcp',
                '@action' => 'handle',
                '__server' => $serverName,
            ]);
            $routes[] = $route;
        }
        return Routes::create(...$routes);
    }
}
