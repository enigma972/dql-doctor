# DLQ Doctor

DLQ Doctor reviews Symfony Messenger failures and routes sensitive replays through human approval. Mercure updates the review UI through the hub embedded in FrankenPHP.

## Local demo

### Prerequisites

- Docker Engine and Docker Compose v2
- An available host port 80 (or set `APP_HTTP_PORT` as shown below)

No host PHP, tenant header, tenant environment variable, or AI API key is required. Compose builds the app and CLI worker, applies migrations, and starts PostgreSQL, RabbitMQ, the Messenger worker, and the outbox publisher.

```sh
docker compose up -d --wait
docker compose exec app php bin/console app:chaos:inject
```

The command queues four global synthetic messages onto RabbitMQ. The worker deliberately fails each message through its configured retries before Messenger moves it to the Doctrine failure transport. The default retry delays total about seven seconds; wait for them before scanning:

```sh
sleep 10
docker compose exec app php bin/console app:dlq:watch --once
```

Expected batch: one LOW automatic replay and three approval proposals (MEDIUM, HIGH, and unclassified, which defaults to HIGH). The replayed LOW message succeeds on the worker; proposal-required messages remain in the failure transport until reviewed. HIGH deletion is forbidden by policy. Re-run `app:chaos:inject` to add another set of scenarios.

The local demo uses deterministic diagnosis by default (`APP_DEMO_DETERMINISTIC=true`), so no paid model API is used. To try the optional OpenAI-backed diagnosis, set `APP_DEMO_DETERMINISTIC=false` and provide `OPENAI_API_KEY` before starting the containers. AI output is a suggestion, not authorization to replay, patch, or discard a message.

### Review the proposals

The UI and MCP endpoints remain protected. To enable the **dev-only** `demo` operator, generate a hash from a password you choose and restart the app:

```sh
read -rs -p 'Local demo password: ' DEMO_PASSWORD; printf '\n'
export DLQ_DEMO_PASSWORD_HASH="$(printf '%s' "$DEMO_PASSWORD" | docker compose exec -T app php -r 'echo password_hash(stream_get_contents(STDIN), PASSWORD_BCRYPT);')"
unset DEMO_PASSWORD
docker compose up -d --wait app
```

Open [http://localhost/proposals](http://localhost/proposals) and sign in as `demo`. Review the MEDIUM, HIGH, or unclassified proposal. Approve or reject a proposal; the original failure is replayed only after approval. Keep the page open while injecting and scanning another set to see private Mercure updates.

Patch dry runs validate a proposed payload and report a diff; the current UI does not submit or apply a patched payload. Automatic LOW-risk patch application is not implemented. LOW failures demonstrate audited automatic replay, not patching.

### Ports, configuration, and troubleshooting

- The app is published on loopback at port 80 by default. If that port is in use, export both values before Compose:

  ```sh
  export APP_HTTP_PORT=18086
  export MERCURE_PUBLIC_URL=http://localhost:18086/.well-known/mercure
  docker compose up -d --wait
  ```

- PostgreSQL and RabbitMQ are reachable by service name from the Compose network and are not published to the host by default.
- For startup or migration problems, inspect `docker compose ps -a` and `docker compose logs migrate app`.
- For retries and DLQ transitions, inspect `docker compose logs worker`; run `app:dlq:watch --once` only after retry delivery has finished.
- For live updates, inspect `docker compose logs outbox-publisher app`. The publisher and browser share the configured Mercure cookie name.
- After source or Dockerfile changes, rebuild with `docker compose up -d --build --wait`.

Stop the stack without deleting persistent data with `docker compose down`. The database and embedded Mercure data use named volumes. Do not use `docker compose down -v` unless you intentionally want to erase them; DLQ records, proposals, and audit records do not have automatic cleanup.

## Scope and limitations

- The failure transport is Doctrine/PostgreSQL. Scanning is bounded (up to 500 envelopes), not tenant-indexed or paginated, and can revisit messages in large queues.
- Publishing a replay to RabbitMQ and acknowledging its PostgreSQL failure record are not one atomic transaction. Ambiguous failures require operator reconciliation.
- The local demo uses the permissive global tenant mode and development credentials. Use the [tenant-mode runbook](docs/tenant-modes.md) for strict production configuration and trust boundaries.
- These controls do not establish or certify ISO 27001 or PCI DSS compliance.

See the [architecture decisions](docs/architecture.md), [tenant-mode runbook](docs/tenant-modes.md), and [MCP guide](docs/mcp.md) for more detail.
