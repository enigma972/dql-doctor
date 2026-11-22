# MCP server

The application exposes the DLQ Doctor tools through the experimental [Symfony MCP Bundle](https://symfony.com/doc/current/ai/bundles/mcp-bundle.html) `0.15.0` and `mcp/sdk` `0.9.0`, resolved in `composer.lock`. The Symfony bundle supports HTTP Streamable and STDIO; this application enables HTTP Streamable only. STDIO remains disabled until it can use an explicit caller identity and tenant boundary equivalent to the HTTP security model.

## Security

The endpoint is `POST /mcp` (with the other Streamable HTTP protocol methods handled by the bundle) and is localhost-only by default. `config/packages/security.yaml` requires `ROLE_DLQ_VIEWER` for the endpoint. Listing, contract inspection, and dry-run require that role; replay and discard additionally require `ROLE_DLQ_OPERATOR`. Tenant users are scoped to their authenticated tenant. With the local global demo user, tools see only explicitly global failures. No tool accepts a tenant ID.

The MCP firewall is separate from the session-based web login. In development it accepts HTTP Basic using the same Doctrine demo account; browser sessions are not accepted. Outside development there is no MCP authenticator configured by default. Configure a production authenticator and a tenant-aware user provider before connecting a remote client. Do not expose the endpoint remotely by changing `http.allowed_hosts` alone: allowed hosts are not authentication. Use HTTPS for remote access.

## Tools

| Tool | Purpose and safeguards |
|---|---|
| `dlq_list_failures` | Returns bounded summaries for the caller's tenant only; limit is 1–500 (default 100). |
| `dlq_inspect_contract` | Takes a tenant-owned failed-message ID, then inspects that message's class; arbitrary class names are not accepted. |
| `dlq_dry_run_patch` | Takes an ID and proposed JSON object; performs no write, replay, or acknowledgement. Existing limits apply: 65,536 bytes, 20,000 values, and 64 nesting levels. |
| `dlq_replay_message` | Requires `ROLE_DLQ_OPERATOR` and delegates to the policy-driven processor, including auditing and idempotency. An approval-required message creates a proposal instead of being replayed. |
| `dlq_discard_message` | Requires `ROLE_DLQ_OPERATOR` and applies the configured discard policy, tenant check, idempotency, and audit logging. A `discard_requested` audit record is written before acknowledgement and `discarded` after it. `approval` and `forbidden` decisions are rejected. The current policy requires approval for LOW/MEDIUM and forbids HIGH, so no current sensitivity level can be discarded through this tool. |

Tool input and output JSON Schemas are declared explicitly in `src/Mcp/DlqDoctorTools.php`. Each serialized tool response is capped at 262,144 bytes. Sensitive envelope payloads and exception text are not returned by the listing tool.

## Client configuration

Configure a compatible MCP client to use the Streamable HTTP URL for the local app:

```json
{
  "mcpServers": {
    "dlq-doctor": {
      "url": "http://localhost/mcp"
    }
  }
}
```

Supply credentials through the client's HTTP authentication settings. For the local Compose demo, see the [README](../README.md) for loading the dev-only operator fixture. Production requires a real tenant-aware identity. The application installs `nyholm/psr7` to supply the PSR-17 HTTP factory used by the bundle.

## Verification

List the registered tools and inspect their descriptions with:

```bash
php bin/console debug:mcp
```
