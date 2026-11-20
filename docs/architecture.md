# DLQ Doctor architecture decisions

## Verified project baseline

- The application requires PHP 8.4 or newer and resolves Symfony FrameworkBundle 8.1.11.
- The runtime container currently uses PHP 8.5.12.
- The application uses Doctrine ORM 3.8 and PostgreSQL.
- The app is an internal Symfony module under `src/DlqDoctor/`, not a reusable Symfony bundle.

## Message transports

- Use RabbitMQ through `symfony/amqp-messenger` for the `async` transport.
- Keep the `failed` transport on Doctrine/PostgreSQL so failed envelopes can be inspected through Symfony Messenger's transport API.
- The installed Symfony 8.1.11 Doctrine transport implements `ListableReceiverInterface`, including `all(?int $limit)` and `find($id)`. Envelopes returned by the Doctrine receiver include a `DoctrineReceivedStamp`, which its `ack()` and `reject()` methods require.
- Do not query or mutate `messenger_messages` directly. Work through the transport API and its stamps.
- Symfony's Doctrine receiver scopes `all()` to its configured queue, not to an application tenant. Only messages implementing `TenantScopedMessage` receive a `TenantStamp`; new dispatches derive it from an authenticated tenant-aware user or the operator-provided process tenant in CLI/STDIO, and reject conflicting caller-supplied stamps. Worker-received envelopes preserve their transported stamp; broker credentials and producer access must therefore remain restricted to trusted application services.
- Failed-message listing and lookup derive tenant scope from `TenantContext`, require `ROLE_DLQ_VIEWER`, and return summaries rather than raw envelopes or exception text. Repair-proposal summaries use the same tenant boundary and exclude payloads, patches, diffs, and approval-token hashes. An authenticated proposal review UI is available under `/proposals`; there is no general HTTP or STDIO endpoint for arbitrary DLQ operations.
- `TenantContext` treats an authenticated `TenantAwareUserInterface` as authoritative and rejects any conflicting configured resolver result. `NullTenantResolver` is the default, so `resolve()` returns `null` for an unscoped request/process when `dlq_doctor.require_tenant` is false. Set it to `true` to throw `MissingTenantContextException` when no tenant is available.
- `HeaderOrEnvTenantResolver` is opt-in by binding `TenantResolverInterface` to that service instead of `NullTenantResolver`. `X-Tenant-ID` is considered only on requests from a configured trusted proxy and only as a check against the authenticated tenant; a request header never grants identity or authorization. The proxy must strip client-supplied values before setting this header. `MCP_TENANT_ID` is read only in CLI/STDIO processes and is trusted deployment configuration. MCP tool schemas do not accept tenant identifiers. Production must bind tenants to authenticated identities, enable `require_tenant`, and ensure any proxy handling tenant headers strips client-supplied values; these controls do not by themselves establish ISO 27001 or PCI-DSS compliance.
- Tenant-scoped Messenger dispatches receive a `TenantStamp`; an explicitly enabled global dispatch receives a distinct `GlobalTenantStamp`. A missing stamp matches neither scope. DLQ listing, patch dry-run, replay, discard, and proposal operations match only the active tenant or explicitly global-stamped envelopes. A null tenant context queries only rows whose `tenant_id IS NULL`; it never includes tenant-scoped records.
- Audit, idempotency, proposal, and Mercure outbox records allow a null tenant for the explicit global scope. Their tenant identity is derived from `TenantContext`, not caller arguments. The PostgreSQL migration uses `NULLS NOT DISTINCT` for tenant/key uniqueness so global idempotency claims remain unique. Audit actors come from the authenticated identity; CLI worker events have no authenticated actor. Audit context is JSON-validated and capped at 4,096 bytes.
- The current list operation scans a bounded number of transport envelopes before filtering by tenant. It is suitable for the demo, but not a complete paginated listing at large queue volumes; production scale requires a tenant-indexed adapter or transport query.
- HTTP callers always need the appropriate application role. In strict mode they also need an authenticated tenant binding; the dev-only demo login intentionally has no tenant. See the [tenant-mode runbook](./tenant-modes.md) for proxy and CLI trust boundaries.
- A send to another transport and acknowledgement of the failed envelope are not assumed atomic. Idempotency must be persisted, and any stronger delivery guarantee must use an outbox or a transaction strategy verified for the selected transports.
- `FailedMessageTransport` is the application contract; `DoctrineFailedMessageTransport` adapts Symfony's listable Doctrine receiver and its required transport stamps.
- `app:dlq:watch` runs continuously by default (5-second poll interval), or scans one bounded batch with `--once`; each batch examines at most 500 envelopes and has no offset/cursor. Messages requiring approval are persisted as proposals and left in the failure transport until the review decision, so scanning never waits for a human. LOW replay is automatic and audited; MEDIUM and HIGH replay create pending proposals with a one-day expiry.
- The dev-only `app:chaos:inject` command dispatches four global synthetic failures to RabbitMQ. Each deliberately fails through the configured retry count before reaching the Doctrine failure transport. A LOW replay succeeds on its next handler attempt; MEDIUM, HIGH, and unclassified (HIGH by default) remain pending approval. This fixture has no business side effects.
- Proposal payloads are normalized to JSON and capped at 65,536 bytes; unsupported or oversized payloads fail explicitly and are left in the failure transport.
- Pending proposals issue a fresh single-use review token when opened. Only its hash is stored; the protected Twig UI also uses session CSRF tokens. Approval and rejection require `ROLE_DLQ_OPERATOR` and recheck tenant, state, expiry, payload, policy, and idempotency before replay; see [approval-flow.md](./approval-flow.md).
- Replay publishes to RabbitMQ before acknowledging PostgreSQL. These operations cannot be made atomic by the selected transport APIs. An approval claim is committed before the broker send to prevent duplicate concurrent replays; an ambiguous failure leaves the proposal approved and requires operator reconciliation.
- A processing exception is surfaced and stops the current command; the failure remains unacknowledged for a later invocation. A claimed operation that cannot be completed requires operator reconciliation.
- Failed-message scans do not provide tenant-indexed pagination; repeated bounded scans may revisit messages outside the current tenant. Failed-message, proposal, audit, and idempotency-record cleanup are not automated.

## Policy and proposal state

- `SensitivityLevel` is a value enum; `PolicyEvaluator` owns all decisions and reads the complete matrix from configuration.
- An unclassified message is HIGH by default.
- Pending human approval is stored as a proposal; no worker waits for the human decision.
- Approval is scoped to the current tenant or global scope and revalidates the proposal state, token expiry, authorization, payload and policy before any dispatch.
- `ContractInspector` builds an informational JSON Schema from extracted PHP property types, constructor requirements, backed enums, and a limited set of Symfony Validator constraints. Unsupported class/getter/property constraints, custom groups, nested object details, and ambiguous PHP array shapes/item types are reported rather than silently translated; the schema is not a substitute for Symfony validation.
- `PatchDryRunService` reads a tenant-owned failed envelope, deserializes only into that envelope's original message class, rejects extra attributes and type errors, validates with Symfony's default group, and returns bounded validation issues and a field-level diff. It has no replay, acknowledgement, persistence, or entity-manager dependency; patch and diff JSON are each capped at 65,536 bytes.
- The policy matrix marks LOW patches `auto_audit`, but the application currently exposes only a validation-only patch dry run; it has no workflow to submit or automatically apply a patched payload. LOW demo failures therefore demonstrate audited automatic replay, not patching.

## Real-time updates

- Run the Mercure Hub embedded in the FrankenPHP app container; do not run a separate Mercure service.
- Persist the embedded hub's Bolt transport in the `frankenphp_mercure_data` Docker volume.
- The previous standalone hub's volume is left untouched; its stored updates are not automatically migrated.
- The app and CLI worker publish through the internal `http://app/.well-known/mercure` endpoint; browsers subscribe through the public app endpoint. Both endpoints use the Symfony Mercure 1.0 JWT configuration.
- Proposal topics are `http://localhost/dlq-doctor/proposals` for global mode and `http://localhost/dlq-doctor/proposals/{encodedTenantId}` for a tenant. Publisher JWTs allow the tenant URL pattern and add the exact global topic only when `dlq_doctor.require_tenant` is false. Browser subscription JWTs are minted for the exact topic passed by the current tenant/global context; they do not receive a cross-tenant selector.
- Proposal state events use a PostgreSQL transactional outbox and the independent `outbox-publisher` Compose service. Events are private and contain status-only refresh hints capped by the outbox payload bound; delivery is at least once, so clients refresh idempotently.
- The development JWT secret is a public sample only and must be replaced for any non-local deployment.
- Outbox persistence is atomic with proposal state and audit changes. Hub publication is retried after failure; a crash between hub acceptance and outbox commit may repeat a refresh event but cannot repeat a replay.

## MCP and AI integrations

- `DlqDoctorAgentInterface` separates diagnostic suggestions from server-side policy. Deterministic mode is the default and works offline; the optional Symfony AI/OpenAI adapter is selected only when `APP_DEMO_DETERMINISTIC=false`, requires `OPENAI_API_KEY`, has no tools, and returns bounded structured output. It cannot authorize or perform DLQ actions. External mode sends normalized message payload and schema to OpenAI; see [ai-agent.md](./ai-agent.md).
- The MCP server uses the experimental Symfony MCP Bundle 0.15.0 and `mcp/sdk` 0.9.0. Its HTTP endpoint uses Streamable HTTP at `/mcp`, is localhost-only by default, requires `ROLE_DLQ_VIEWER`, and exposes only `App\Mcp\` tools. Replay and discard additionally require `ROLE_DLQ_OPERATOR`. The bundle supports STDIO too, but this application leaves it disabled until an equivalent trusted caller identity and tenant-binding mechanism is defined.
- MCP tools derive tenant identity from the authenticated `TenantAwareUserInterface`; callers cannot select a tenant. Replay delegates to `FailedMessageProcessor`; discard uses policy, idempotency, and audit services; contract inspection and patch dry-run use current-tenant message IDs rather than arbitrary message class names.
- A permitted discard claims an idempotency key, records `discard_requested`, acknowledges the failure, records `discarded`, then completes the claim. The audit write and transport acknowledgement are not assumed atomic; failures after the claim may require operator reconciliation.
- The default in-memory security provider is a placeholder without configured tenant-aware users. A real authenticator/provider must be configured before connecting an MCP client; allowing a host in MCP configuration is not authentication.
- The actual MCP tool schemas, operation limits, client example, and the current policy outcomes for discard are documented in [mcp.md](./mcp.md).
- The current environment does not expose a Context7 tool. Until it is available, use the installed source/docblocks and version-matched official documentation; record the exact package versions checked.
- Start with a deterministic agent. An external AI provider is optional and cannot decide policy, identity, tenant scope or authorization.
