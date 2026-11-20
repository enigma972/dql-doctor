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
- Symfony's Doctrine receiver scopes `all()` to its configured queue, not to an application tenant. Tenant metadata must be attached by trusted application code and checked before any failed-message details are exposed or acted on.
- A send to another transport and acknowledgement of the failed envelope are not assumed atomic. Idempotency must be persisted, and any stronger delivery guarantee must use an outbox or a transaction strategy verified for the selected transports.

## Policy and proposal state

- `SensitivityLevel` is a value enum; `PolicyEvaluator` owns all decisions and reads the complete matrix from configuration.
- An unclassified message is HIGH by default.
- Pending human approval is stored as a proposal; no worker waits for the human decision.
- Approval must be tenant-scoped and revalidate the proposal state, token expiry, authorization, payload and policy before any dispatch.

## Real-time updates

- Use the Symfony Mercure bundle with a local Mercure Hub for the demo.
- The development JWT secret is a public sample only and must be replaced for any non-local deployment.
- Mercure publication is not assumed to be atomic with database writes; use an outbox or explicitly document and test the chosen failure/retry behavior.

## MCP and AI integrations

- Do not implement MCP transport configuration until the exact SDK/bundle versions and supported transports are verified. HTTP Streamable and STDIO must not be conflated with legacy HTTP+SSE.
- The current environment does not expose a Context7 tool. Until it is available, use the installed source/docblocks and version-matched official documentation; record the exact package versions checked.
- Start with a deterministic agent. An external AI provider is optional and cannot decide policy, identity, tenant scope or authorization.
