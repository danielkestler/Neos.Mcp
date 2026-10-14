<?php
declare(strict_types=1);

namespace Neos\Mcp\Tests\Functional;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\Api\Tests\Functional\Endpoint\EndpointTestCase;
use Neos\Flow\Security\Authentication\AuthenticationManagerInterface;
use Neos\Flow\Security\Authentication\Token\BearerToken;
use Neos\Flow\Security\Authentication\TokenAndProviderFactoryInterface;
use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * The preconfigured neos server at /mcp, with tokens bound to it (RFC 8707)
 */
class McpServerTest extends EndpointTestCase
{
    private const string RESOURCE = 'http://localhost/mcp';
    private const string PROTOCOL_VERSION = '2026-07-28';

    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager->get(UserService::class)->createUser('editor', 'password', 'Edith', 'Editor', ['Neos.Neos:Editor']);
        $this->addMachineClient('editor-machine', 'editor');
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function requestsWithoutTokenArePointedToTheResourceMetadata(): void
    {
        $response = $this->mcp('server/discover', [], null);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp"', $response->getHeaderLine('WWW-Authenticate'));
    }

    #[Test]
    public function tokensNotBoundToTheServerAreRejected(): void
    {
        foreach ([null, 'http://localhost/api'] as $resource) {
            $response = $this->mcp('server/discover', [], $this->boundToken('me.read', $resource));

            self::assertSame(401, $response->getStatusCode(), (string)$resource);
            self::assertStringContainsString('error="invalid_token"', $response->getHeaderLine('WWW-Authenticate'));
        }
    }

    #[Test]
    public function tokensBoundToTheServerAreNotAcceptedByTheApi(): void
    {
        self::assertSame(401, $this->get('/api/me', $this->boundToken('me.read'))->getStatusCode());
    }

    #[Test]
    public function discoverNamesTheServerAndItsTools(): void
    {
        $result = $this->rpcResult($this->mcp('server/discover', [], $this->boundToken('me.read')));

        self::assertSame([self::PROTOCOL_VERSION], $result['supportedVersions']);
        self::assertSame(['tools' => []], $result['capabilities']);
        self::assertSame('neos', $result['_meta']['io.modelcontextprotocol/serverInfo']['name']);
        self::assertStringContainsString('Never change "live" directly', $result['instructions'] ?? '');
    }

    #[Test]
    public function onlyToolsTheTokenGrantsAreListed(): void
    {
        $result = $this->rpcResult($this->mcp('tools/list', [], $this->boundToken('me.read contentrepositories.read')));
        $names = array_column($result['tools'], 'name');

        self::assertContains('getMe', $names);
        self::assertContains('listContentRepositories', $names);
        self::assertNotContains('createUser', $names);
        self::assertNotContains('listSites', $names);
    }

    #[Test]
    public function toolsTheAccountLacksThePrivilegeForAreNotListed(): void
    {
        // editors don't hold Neos.Api:Users.Read, the scope alone isn't enough
        $result = $this->rpcResult($this->mcp('tools/list', [], $this->boundToken('me.read users.read')));

        self::assertNotContains('listUsers', array_column($result['tools'], 'name'));
    }

    #[Test]
    public function toolsRunTheOperationAsTheTokensAccount(): void
    {
        $result = $this->rpcResult($this->mcp('tools/call', ['name' => 'getMe', 'arguments' => []], $this->boundToken('me.read')));

        self::assertFalse($result['isError'] ?? false);
        self::assertSame('editor', $result['structuredContent']['account']['identifier']);
        self::assertSame(['client' => 'editor-machine', 'scopes' => ['me.read']], $result['structuredContent']['token']);
    }

    #[Test]
    public function problemsOfTheOperationAreToolErrors(): void
    {
        $result = $this->rpcResult($this->mcp('tools/call', ['name' => 'getContentRepository', 'arguments' => ['contentRepositoryId' => 'unknown']], $this->boundToken('contentrepositories.read')));

        self::assertTrue($result['isError']);
        self::assertStringContainsString('404', $result['content'][0]['text']);
    }

    #[Test]
    public function toolsTheTokenDoesntGrantCannotBeCalled(): void
    {
        $response = $this->mcp('tools/call', ['name' => 'createUser', 'arguments' => []], $this->boundToken('me.read'));

        self::assertSame(-32602, self::json($response)['error']['code'] ?? null, (string)$response->getBody());
    }

    #[Test]
    public function requestsFromForeignOriginsAreRefused(): void
    {
        $response = $this->mcp('server/discover', [], $this->boundToken('me.read'), ['Origin' => 'https://attacker.example.com']);

        self::assertSame(403, $response->getStatusCode());
    }

    private function boundToken(string $scope, ?string $resource = self::RESOURCE): string
    {
        $arguments = ['grant_type' => 'client_credentials', 'client_id' => 'editor-machine', 'client_secret' => 'machine-secret', 'scope' => $scope];
        if ($resource !== null) {
            $arguments['resource'] = $resource;
        }
        $response = $this->browser->request('http://localhost/oauth/token', 'POST', $arguments);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        return json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR)['access_token'];
    }

    /**
     * A JSON-RPC request in the shape of MCP 2026-07-28: protocol version and capabilities in _meta and headers
     *
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     */
    private function mcp(string $method, array $params, ?string $bearer, array $headers = []): ResponseInterface
    {
        $this->resetAuthenticationState();
        $headers += [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
            'Mcp-Method' => $method,
        ];
        if (isset($params['name'])) {
            $headers['Mcp-Name'] = $params['name'];
        }
        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer ' . $bearer;
        }
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL_VERSION,
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR);
        return $this->browser->sendRequest(new ServerRequest('POST', self::RESOURCE, $headers, $body));
    }

    /**
     * @return array<string, mixed>
     */
    private function rpcResult(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $body = self::json($response);
        self::assertArrayHasKey('result', $body, (string)$response->getBody());
        return $body['result'];
    }

    /**
     * The browser runs all requests in one process: forget the previous request's authentication
     */
    private function resetAuthenticationState(): void
    {
        $this->inject($this->objectManager->get(AuthenticationManagerInterface::class), 'isAuthenticated', null);
        foreach ($this->objectManager->get(TokenAndProviderFactoryInterface::class)->getTokens() as $token) {
            if ($token instanceof BearerToken) {
                $this->inject($token, 'credentials', ['bearer' => '']);
            }
        }
    }
}
