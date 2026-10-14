# Neos.Mcp

An [MCP](https://modelcontextprotocol.io) server for Neos on `/mcp`: every operation of the Neos API (Neos.Api) is a
tool, built by [neos/openapi-mcp](https://github.com/danielkestler/neos-openapi-mcp). It speaks MCP 2026-07-28
(stateless Streamable HTTP).

## Usage

Install the package next to Neos.Api and Neos.OAuth, then add the server to a client, e.g. Claude Code:

```sh
claude mcp add --transport http neos https://example.com/mcp
```

The client logs in through Neos.OAuth with a client ID metadata document or dynamic client registration (both
disabled by default, see `Neos.OAuth.clientIdMetadataDocuments` and `dynamicClientRegistration`). Tokens must be
bound to `/mcp` (RFC 8707). A tool runs as the token's account: it is only listed if the token's scopes and the
account's privileges allow it, as over the API.

The server is configured in `Neos.Mcp.servers.neos`, see `Configuration/Settings.yaml`. Other hosts than the OAuth
issuer's go into `allowedHosts`.

## License

MIT, see [LICENSE](LICENSE).
