<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Resources;

use InvalidArgumentException;
use Uzbek\Wlcm\Partner\Data\FiscalReceipt;
use Uzbek\Wlcm\Partner\Data\FiscalRefundResponse;
use Uzbek\Wlcm\Partner\Data\FiscalRegisterResponse;
use Uzbek\Wlcm\Partner\PartnerClient;

final readonly class Fiscalization
{
    public function __construct(private PartnerClient $client) {}

    public function get(?int $fiscalId = null, ?int $paymentId = null, ?string $externalId = null): FiscalReceipt
    {
        $query = array_filter([
            'external_id' => $externalId,
            'fiscal_id' => $fiscalId,
            'payment_id' => $paymentId,
        ], fn (mixed $v): bool => $v !== null);

        if ($query === []) {
            throw new InvalidArgumentException('At least one of fiscalId, paymentId, externalId required');
        }

        $result = $this->client->get('/fiscalization', $query);
        /** @var FiscalReceiptShape $result */

        return FiscalReceipt::fromArray($result);
    }

    /** @param list<array<string, mixed>> $items */
    public function register(array $items, ?int $paymentId = null, ?string $externalId = null): FiscalRegisterResponse
    {
        if ($items === [] || ($paymentId === null && $externalId === null)) {
            throw new InvalidArgumentException('items plus paymentId or externalId required');
        }

        $payload = array_filter([
            'external_id' => $externalId,
            'items' => $items,
            'payment_id' => $paymentId,
        ], fn (mixed $v): bool => $v !== null);

        $result = $this->client->post('/fiscalization/register', $payload);
        /** @var FiscalRegisterResponseShape $result */

        return FiscalRegisterResponse::fromArray($result);
    }

    public function refund(?int $paymentId = null, ?string $externalId = null): FiscalRefundResponse
    {
        if ($paymentId === null && $externalId === null) {
            throw new InvalidArgumentException('paymentId or externalId required');
        }

        $payload = array_filter([
            'external_id' => $externalId,
            'payment_id' => $paymentId,
        ], fn (mixed $v): bool => $v !== null);

        $result = $this->client->post('/fiscalization/refund', $payload);
        /** @var FiscalRefundResponseShape $result */

        return FiscalRefundResponse::fromArray($result);
    }
}
