<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use App\DlqDoctor\Enum\DlqProcessingOutcome;
use App\DlqDoctor\Context\TenantContextInterface;
use App\DlqDoctor\Messenger\TenantScope;
use InvalidArgumentException;

final readonly class DlqWatchService
{
    public function __construct(
        private FailedMessageTransport $failedTransport,
        private FailedMessageProcessor $processor,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function processBatch(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('The failure scan limit must be between 1 and 500.');
        }

        $tenantId = $this->tenantContext->tenantId();
        $outcomes = array_fill_keys(array_column(DlqProcessingOutcome::cases(), 'value'), 0);

        foreach ($this->failedTransport->all($limit) as $envelope) {
            if (!TenantScope::matches($envelope, $tenantId)) {
                continue;
            }

            $outcome = $this->processor->process($envelope);
            ++$outcomes[$outcome->value];
        }

        return $outcomes;
    }
}
