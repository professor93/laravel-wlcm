<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

use Uzbek\Wlcm\Enums\PaymentState;
use Uzbek\Wlcm\Support\Tiyin;

/**
 * @phpstan-type OrderStatusShape array{order_id?: WlcmJsonScalar, amount?: WlcmJsonScalar, status?: WlcmJsonScalar, state?: WlcmJsonScalar, is_paid?: WlcmJsonScalar, is_pending?: WlcmJsonScalar, is_cancelled?: WlcmJsonScalar, is_completed?: WlcmJsonScalar, status_explanation?: array<string, mixed>, extra_data?: array<string, mixed>, payment_id?: WlcmJsonScalar, gateway?: WlcmJsonScalar, transaction_id?: WlcmJsonScalar, performed_at?: WlcmJsonScalar}
 */
final readonly class OrderStatus
{
    /**
     * @param  array<string, mixed>  $statusExplanation
     * @param  array<string, mixed>  $extraData
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public int $orderId,
        public float $amount,
        public string $status,
        public ?int $state,
        public bool $isPaid,
        public bool $isPending,
        public bool $isCancelled,
        public bool $isCompleted,
        public array $statusExplanation,
        public array $extraData,
        public ?int $paymentId,
        public ?string $gateway,
        public ?string $transactionId,
        public ?string $performedAt,
        public array $raw,
    ) {}

    /** @param OrderStatusShape $data */
    public static function fromArray(array $data): self
    {
        // Paid responses drop the top-level state; status_explanation.state (string) still carries it.
        $stateRaw = $data['state'] ?? null;

        if ($stateRaw === null) {
            $explanation = $data['status_explanation'] ?? null;

            if (is_array($explanation) && is_scalar($explanation['state'] ?? null)) {
                $stateRaw = $explanation['state'];
            }
        }

        return new self(
            orderId: (int) ($data['order_id'] ?? 0),
            amount: (float) ($data['amount'] ?? 0),
            status: (string) ($data['status'] ?? ''),
            state: is_numeric($stateRaw) ? (int) $stateRaw : null,
            isPaid: (bool) ($data['is_paid'] ?? false),
            isPending: (bool) ($data['is_pending'] ?? false),
            isCancelled: (bool) ($data['is_cancelled'] ?? false),
            isCompleted: (bool) ($data['is_completed'] ?? false),
            statusExplanation: (array) ($data['status_explanation'] ?? []),
            extraData: (array) ($data['extra_data'] ?? []),
            paymentId: isset($data['payment_id']) ? (int) $data['payment_id'] : null,
            gateway: isset($data['gateway']) ? (string) $data['gateway'] : null,
            transactionId: isset($data['transaction_id']) ? (string) $data['transaction_id'] : null,
            performedAt: isset($data['performed_at']) ? (string) $data['performed_at'] : null,
            raw: $data,
        );
    }

    public function stateEnum(): ?PaymentState
    {
        return $this->state === null ? null : PaymentState::tryFrom($this->state);
    }

    // Server sends SUM as a JSON float; pin to two decimals as a string, then digit math.
    public function amountTiyin(): int
    {
        return Tiyin::fromSumString(number_format($this->amount, 2, '.', ''));
    }
}
