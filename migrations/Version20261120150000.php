<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow global DLQ records and make tenant-scoped uniqueness treat NULL tenants as equal.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dlq_audit_log ALTER COLUMN tenant_id DROP NOT NULL');
        $this->addSql('ALTER TABLE dlq_idempotency_record ALTER COLUMN tenant_id DROP NOT NULL');
        $this->addSql('ALTER TABLE dlq_repair_proposal ALTER COLUMN tenant_id DROP NOT NULL');
        $this->addSql('ALTER TABLE dlq_mercure_outbox ALTER COLUMN tenant_id DROP NOT NULL');

        $this->addSql('DROP INDEX uniq_dlq_idempotency_tenant_key');
        $this->addSql('CREATE UNIQUE INDEX uniq_dlq_idempotency_tenant_key ON dlq_idempotency_record (tenant_id, idempotency_key) NULLS NOT DISTINCT');
        $this->addSql('DROP INDEX uniq_dlq_proposal_tenant_idempotency');
        $this->addSql('CREATE UNIQUE INDEX uniq_dlq_proposal_tenant_idempotency ON dlq_repair_proposal (tenant_id, idempotency_key) NULLS NOT DISTINCT');
    }

    public function down(Schema $schema): void
    {
        foreach (['dlq_audit_log', 'dlq_idempotency_record', 'dlq_repair_proposal', 'dlq_mercure_outbox'] as $table) {
            $this->abortIf(
                (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE tenant_id IS NULL', $table)) > 0,
                sprintf('Cannot make %s.tenant_id non-nullable while global records exist.', $table),
            );
        }

        $this->addSql('DROP INDEX uniq_dlq_idempotency_tenant_key');
        $this->addSql('CREATE UNIQUE INDEX uniq_dlq_idempotency_tenant_key ON dlq_idempotency_record (tenant_id, idempotency_key)');
        $this->addSql('DROP INDEX uniq_dlq_proposal_tenant_idempotency');
        $this->addSql('CREATE UNIQUE INDEX uniq_dlq_proposal_tenant_idempotency ON dlq_repair_proposal (tenant_id, idempotency_key)');

        $this->addSql('ALTER TABLE dlq_audit_log ALTER COLUMN tenant_id SET NOT NULL');
        $this->addSql('ALTER TABLE dlq_idempotency_record ALTER COLUMN tenant_id SET NOT NULL');
        $this->addSql('ALTER TABLE dlq_repair_proposal ALTER COLUMN tenant_id SET NOT NULL');
        $this->addSql('ALTER TABLE dlq_mercure_outbox ALTER COLUMN tenant_id SET NOT NULL');
    }
}
