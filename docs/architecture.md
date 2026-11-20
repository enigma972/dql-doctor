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
- Symfony's Doctrine receiver scopes `all()` to its configured queue, not to an application tenant. Only messages implementing `TenantScopedMessage` receive a `TenantStamp`; the stamp is attached from an authenticated tenant-aware user in HTTP or the operator-provided process tenant in CLI/STDIO. Failed-message listing and lookup require a matching stamp and do not return raw payloads or exception text.
- The current list operation scans a bounded number of transport envelopes before filtering by tenant. It is suitable for the demo, but not a complete paginated listing at large queue volumes; production scale requires a tenant-indexed adapter or transport query.
- An HTTP caller without a tenant-aware authenticated user is denied; the process tenant fallback is available only under CLI SAPI and is intended for a separately configured STDIO process.
- A send to another transport and acknowledgement of the failed envelope are not assumed atomic. Idempotency must be persisted, and any stronger delivery guarantee must use an outbox or a transaction strategy verified for the selected transports.
- `FailedMessageTransport` is the application contract; `DoctrineFailedMessageTransport` adapts Symfony's listable Doctrine receiver and its required transport stamps.
- `app:dlq:watch` runs continuously by default (5-second poll interval), or scans one bounded batch with `--once`; each batch examines at most 500 envelopes and has no offset/cursor. Messages requiring approval are persisted as proposals and acknowledged from the failure transport, so they do not hold up the batch. LOW replay is automatic, MEDIUM replay is automatic and audited, and HIGH replay creates a pending proposal with a one-day expiry.
- Proposal payloads are normalized to JSON and capped at 65,536 bytes; unsupported or oversized payloads fail explicitly and are left in the failure transport.
- Pending proposals created by the watcher do not yet include an approval token or notification; the later approval-flow implementation must provision those before proposals can be acted on.
- Replay publishes to RabbitMQ before acknowledging PostgreSQL. These operations cannot be made atomic by the selected transport APIs. A crash between publishing and recording completion, or an exception after an idempotency claim enters `started`, can leave the failure for operator reconciliation; completed claims prevent duplicates and allow a stale failure copy to be acknowledged on a later scan.
- A processing exception is surfaced and stops the current command; the failure remains unacknowledged for a later invocation. A claimed operation that cannot be completed requires operator reconciliation.
- Failed-message scans do not provide tenant-indexed pagination; repeated bounded scans may revisit messages outside the current tenant. Failed-message, proposal, audit, and idempotency-record cleanup are not automated.

## Policy and proposal state

- `SensitivityLevel` is a value enum; `PolicyEvaluator` owns all decisions and reads the complete matrix from configuration.
- An unclassified message is HIGH by default.
- Pending human approval is stored as a proposal; no worker waits for the human decision.
- Approval must be tenant-scoped and revalidate the proposal state, token expiry, authorization, payload and policy before any dispatch.

## Real-time updates

- Run the Mercure Hub embedded in the FrankenPHP app container; do not run a separate Mercure service.
- Persist the embedded hub's Bolt transport in the `frankenphp_mercure_data` Docker volume.
- The previous standalone hub's volume is left untouched; its stored updates are not automatically migrated.
- The app and CLI worker publish through the internal `http://app/.well-known/mercure` endpoint; browsers subscribe through the public app endpoint. Both endpoints use the Symfony Mercure 1.0 JWT configuration.
- The development JWT secret is a public sample only and must be replaced for any non-local deployment.
- Mercure publication is not assumed to be atomic with database writes; use an outbox or explicitly document and test the chosen failure/retry behavior.

## MCP and AI integrations

- Do not implement MCP transport configuration until the exact SDK/bundle versions and supported transports are verified. HTTP Streamable and STDIO must not be conflated with legacy HTTP+SSE.
- The current environment does not expose a Context7 tool. Until it is available, use the installed source/docblocks and version-matched official documentation; record the exact package versions checked.
- Start with a deterministic agent. An external AI provider is optional and cannot decide policy, identity, tenant scope or authorization.
