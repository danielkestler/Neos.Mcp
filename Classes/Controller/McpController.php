<?php
declare(strict_types=1);

namespace Neos\Mcp\Controller;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Controller\ControllerInterface;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Mcp\McpServers;
use Neos\OAuth\Security\AuthorizationServerMetadata;
use Neos\OAuth\Security\IssuerNotConfigured;
use Neos\OAuth\Security\OAuthContext;
use Neos\OAuth\Security\ProtectedResources;
use Neos\OpenApi\FlowAdapter\CompiledApis;
use Neos\OpenApi\Http\AuthContextProvider;
use Neos\OpenApi\Mcp\ExposedApi;
use Neos\OpenApi\Mcp\McpServer;
use Neos\OpenApi\Mcp\ServerInfo;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;

/**
 * The endpoint of the MCP server `__server` (see McpRoutesProvider): checks the access token, then hands the request
 * to neos/openapi-mcp, which offers the operations of the server's APIs as tools
 *
 * The tools run in this request: their operations see the same authenticated token, which is bound to this server
 * (RFC 8707), and the same scope and privilege checks apply as over the API itself
 */
#[Flow\Scope('singleton')]
final readonly class McpController implements ControllerInterface
{
    public function __construct(
        private McpServers $servers,
        private CompiledApis $compiledApis,
        private OAuthContext $oauthContext,
        private ProtectedResources $protectedResources,
        private AuthorizationServerMetadata $authorizationServer,
        private ObjectManagerInterface $objectManager,
        private ConfigurationManager $configurationManager,
        private LoggerInterface $logger,
    ) {
    }

    public function processRequest(ActionRequest $request): ResponseInterface
    {
        $serverName = $request->getInternalArgument('__server');
        if (!is_string($serverName)) {
            throw new \InvalidArgumentException('Missing internal argument "__server"', 1792000201);
        }
        $httpRequest = $request->getHttpRequest();
        $protectedResource = $this->servers->protectedResource($serverName);
        if ($protectedResource !== null && $this->oauthContext->authenticatedGrant() === null) {
            return $this->unauthorized($protectedResource, $httpRequest->hasHeader('Authorization'));
        }
        // Flow's body parsing may have read the stream already
        if ($httpRequest->getBody()->isSeekable()) {
            $httpRequest->getBody()->rewind();
        }
        return $this->server($serverName)->handle($httpRequest);
    }

    private function server(string $serverName): McpServer
    {
        $factory = new HttpFactory();
        $includedTags = $this->servers->includedTags($serverName);
        $apis = [];
        $authContextProviders = [];
        foreach ($this->servers->apis($serverName) as $apiName) {
            $apis[] = ExposedApi::fromCompiledApi(
                $this->compiledApis->get($apiName),
                $this->compiledApis->requestHandler($apiName),
                $includedTags !== [] ? $includedTags : null,
                $this->servers->excludedTags($serverName),
                '/' . $this->compiledApis->uriPrefix($apiName),
            );
            $authContextProvider = $this->authContextProvider($apiName);
            if ($authContextProvider !== null) {
                $authContextProviders[get_class($authContextProvider)] = $authContextProvider;
            }
        }
        if (count($authContextProviders) > 1) {
            throw new \InvalidArgumentException(sprintf('The APIs of the MCP server "%s" have different authContextProviders, which is not supported yet', $serverName), 1792000202);
        }

        return new McpServer(
            new ServerInfo(
                $serverName,
                $this->servers->version($serverName),
                title: $this->servers->title($serverName),
                instructions: $this->servers->instructions($serverName),
            ),
            $apis,
            $factory,
            $factory,
            $factory,
            array_map(
                fn (string $objectName): MiddlewareInterface => $this->objectManager->get($objectName),
                $this->servers->operationMiddlewares($serverName),
            ),
            array_values($authContextProviders)[0] ?? null,
            [
                // MCP clients aren't browsers: no cross-origin access
                new CorsMiddleware(),
                new DnsRebindingProtectionMiddleware($this->allowedHosts($serverName), $factory, $factory),
            ],
            $this->logger,
        );
    }

    /**
     * The API's authContextProvider as configured for the FlowAdapter, which also decides which tools a caller sees
     */
    private function authContextProvider(string $apiName): ?AuthContextProvider
    {
        $objectName = $this->configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
            sprintf('Neos.OpenApi.FlowAdapter.apis.%s.authContextProvider', $apiName),
        );
        if (!is_string($objectName) || $objectName === '') {
            return null;
        }
        $authContextProvider = $this->objectManager->get($objectName);
        return $authContextProvider instanceof AuthContextProvider ? $authContextProvider : null;
    }

    /**
     * @return list<string>
     */
    private function allowedHosts(string $serverName): array
    {
        $allowedHosts = $this->servers->allowedHosts($serverName);
        try {
            $issuerHost = parse_url($this->authorizationServer->issuer(), PHP_URL_HOST);
            if (is_string($issuerHost)) {
                $allowedHosts[] = $issuerHost;
            }
        } catch (IssuerNotConfigured) {
        }
        return array_values(array_unique($allowedHosts));
    }

    /**
     * Points the client to the resource's metadata (RFC 9728), where it finds the authorization server
     */
    private function unauthorized(string $protectedResource, bool $tokenGiven): ResponseInterface
    {
        $parameters = [];
        try {
            $parameters[] = sprintf('resource_metadata="%s"', $this->protectedResources->metadataUrl($protectedResource));
        } catch (IssuerNotConfigured) {
        }
        if ($tokenGiven) {
            // invalid, expired, revoked, or issued for another resource
            $parameters[] = 'error="invalid_token"';
        }
        return new Response(401, ['WWW-Authenticate' => rtrim('Bearer ' . implode(', ', $parameters)), 'Content-Type' => 'application/json'], json_encode(['error' => 'unauthorized', 'error_description' => 'An access token for this MCP server is required'], JSON_THROW_ON_ERROR));
    }
}
