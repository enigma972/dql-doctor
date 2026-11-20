# Tenant modes runbook

| | Local global demo | Multi-tenant deployment |
|---|---|---|
| Context | `NullTenantResolver`, `require_tenant: false` | `require_tenant: true`, tenant bound to an authenticated identity or trusted process |
| Messenger and database | Explicit `GlobalTenantStamp`, `tenant_id IS NULL` | Matching `TenantStamp` and `tenant_id` only |
| Mercure | `/dlq-doctor/proposals` | `/dlq-doctor/proposals/{encodedTenantId}`; exact topic per browser |
| Authentication | Optional dev-only local demo user; routes stay protected | Real application authenticator and user provider; no demo user |

Use the [README](../README.md) to start the local demo. The Compose migration service runs `doctrine:migrations:migrate --no-interaction`; check `docker compose logs migrate outbox-publisher` if startup or live updates fail. A failed migration prevents app startup. Removing volumes destroys the local database and the Mercure backlog; do not do so on an existing deployment.

## Multi-tenant deployment

1. Set the parameter in `config/packages/prod/dlq_doctor.yaml`:

   ```yaml
   parameters:
       dlq_doctor.require_tenant: true
   ```

2. Deploy with `APP_ENV=prod`, TLS, unique secrets, restricted broker credentials, and real authentication. Set the same `MERCURE_COOKIE_NAME=__Secure-mercure_access_token` on Symfony and the hub, and configure `MERCURE_PUBLIC_URL` to the public HTTPS hub endpoint. The principal must implement `TenantAwareUserInterface`, deriving its tenant from server-side membership data. Keep the default null resolver if principal identity is sufficient. Keep viewer and operator roles separate.
3. If operationally needed, bind `App\DlqDoctor\Context\TenantResolverInterface` to `App\DlqDoctor\Context\HeaderOrEnvTenantResolver` in the deployment's services configuration. Only trust explicitly configured proxy addresses; the proxy must strip any incoming `X-Tenant-ID` and may add only a value matching the authenticated principal's tenant. The header does not authenticate a user. For CLI processes, use `MCP_TENANT_ID` only as per-process deployment configuration, never request input or caller identity. MCP STDIO is not enabled; do not enable it without an equivalent authorization model.
4. Back up PostgreSQL and apply Doctrine migrations before starting the app and workers. Before switching an existing installation to strict mode, drain pending global Mercure events in the existing mode and reconcile any global failed messages separately; do not implicitly reassign them to a tenant.
5. Verify the app against a tenant-aware user and a user without a tenant. Missing context raises `MissingTenantContextException` rather than falling back to global storage; a conflicting trusted header is denied. Unstamped messages match neither tenant nor global scope. Opaque cross-tenant proposal IDs are not visible or actionable.

These settings support isolation and audit controls. They do not establish or certify ISO 27001 or PCI DSS compliance.
