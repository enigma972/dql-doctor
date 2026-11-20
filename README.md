# DLQ Doctor

DLQ Doctor reviews Symfony Messenger failures and routes sensitive replays through human approval. Mercure updates the review UI through the hub embedded in FrankenPHP.

## Local demo

With Docker and Docker Compose v2 or newer, run `docker compose up -d --wait`. Compose builds the images, applies Doctrine migrations before starting the app, and starts the Messenger worker and outbox publisher. No host PHP, tenant header, tenant environment variable, or AI API key is required.

```sh
docker compose exec app php bin/console app:dlq:demo:seed
docker compose exec app php bin/console app:dlq:watch --once
```

The seed command queues a synthetic global failure. The first watch pass creates a pending proposal; a second recognizes the existing idempotency claim. The sample has no business side effects if approved and replayed.

The UI and MCP still require authentication. To enable the **local-only** `demo` operator, generate a hash for a password you choose:

```sh
read -rs -p 'Local demo password: ' DEMO_PASSWORD; printf '\n'
export DLQ_DEMO_PASSWORD_HASH="$(printf '%s' "$DEMO_PASSWORD" | docker compose exec -T app php -r 'echo password_hash(stream_get_contents(STDIN), PASSWORD_BCRYPT);')"
unset DEMO_PASSWORD
docker compose up -d --wait app
```

Open [http://localhost/proposals](http://localhost/proposals) and sign in as `demo`. Open the pending proposal and approve or reject it. To see the list update live, keep it open while running the seed and watch commands again. The browser subscribes to the private global topic. The demo authenticator is available only in `dev`; without a configured password hash the user cannot sign in.

If port 80 is busy, set both `APP_HTTP_PORT` and `MERCURE_PUBLIC_URL` before starting (for example `18086` and `http://localhost:18086/.well-known/mercure`). Source changes require `docker compose up -d --build --wait`. Use `docker compose down` to stop without deleting data.

See the [tenant-mode runbook](docs/tenant-modes.md) for deployment checks and the [MCP guide](docs/mcp.md) for client setup.
