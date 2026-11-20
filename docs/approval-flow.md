# Repair proposal review

## Protected review UI

- `GET /proposals` lists safe summaries for the authenticated tenant and requires `ROLE_DLQ_VIEWER`.
- `GET /proposals/{id}` displays the proposal payload and diff and requires `ROLE_DLQ_OPERATOR`.
- `POST /proposals/{id}/approve` and `POST /proposals/{id}/reject` require `ROLE_DLQ_OPERATOR`, a Symfony CSRF token, and a one-time approval token.
- The tenant is always derived from `TenantAwareUserInterface`; it is never accepted from a route, query parameter, or form field. The default security user provider is still a placeholder, so the application must be integrated with a real tenant-aware authenticator/provider before exposing these routes.

Opening a pending proposal issues a new 256-bit random token, stores only its SHA-256 hash, and places the raw token in the protected form. Reopening the page replaces the prior token. The token expires with the proposal and is consumed by the first successful approval or rejection. CSRF validation is an independent browser-session check.

## Processing and replay

The watcher creates the proposal, idempotency result, audit row, and Mercure outbox event in one PostgreSQL transaction. The original failed envelope remains in the failure transport while a proposal is pending, rejected, or expired; repeated scans recognize its completed proposal idempotency record and do not recreate or acknowledge it. This keeps the watcher independent of the human decision.

Approval takes a PostgreSQL write lock on the tenant-scoped proposal and rechecks its state, expiry, token, failed-envelope ID and tenant stamp, message class, normalized payload, Symfony validation, current replay policy, optional patch policy, and a separate replay idempotency key before dispatch. It sends a sanitized copy to the async transport, then atomically acknowledges the failure envelope, marks the proposal replayed, completes the key, writes the audit row, and records the Mercure event. Rejection consumes the token and records the decision but leaves the failed message available for separate operator handling.

RabbitMQ and PostgreSQL do not share a distributed transaction. The approval claim and `approved` state commit before sending to RabbitMQ so a concurrent request cannot enqueue the same proposal twice. If the send or the final PostgreSQL transaction fails, the proposal remains `approved` and the started idempotency record blocks automatic retries; an operator must reconcile whether the broker accepted the message. This favors avoiding duplicate replays over automatic recovery from an ambiguous send result.

## Mercure delivery

Proposal state events are committed to `dlq_mercure_outbox` in the same PostgreSQL transaction as their state and audit changes. The separate `outbox-publisher` Compose service runs `dlq:publish-outbox --loop`; it uses row locks with `SKIP LOCKED`, publishes private updates to the tenant topic, and marks each row published only after the hub accepts it. A failed publish leaves the event pending for a restart/retry. A crash after hub acceptance but before the database commit can cause a duplicate notification, so browser clients treat events as refresh hints, not commands.

Mercure event data contains only the proposal status. The review page uses the Symfony Mercure Twig helper to grant a private subscription to the current tenant's topic; the Stimulus controller refreshes the page when an update arrives. Payloads, tokens, approval decisions, and replay commands are never sent through Mercure.

Run one publish pass manually with `php bin/console dlq:publish-outbox`. For local Compose, the poller is started independently from the Messenger worker and publishes through FrankenPHP's embedded Mercure endpoint.
