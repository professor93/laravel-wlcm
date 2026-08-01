<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

use Uzbek\Wlcm\Enums\PaymentProvider;
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
        // Paid responses drop top-level state; status_explanation.state (string) still carries it.
        $stateRaw = $data['state'] ?? null;

        if ($stateRaw === null) {
            $explanation = $data['status_explanation'] ?? null;

            if (is_array($explanation) && is_scalar($explanation['state'] ?? null)) {
                $stateRaw = $explanation['state'];
            }
        }

        $paymentId = $data['payment_id'] ?? null;

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
            paymentId: is_numeric($paymentId) ? (int) $paymentId : null,
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

    // `gateway` is the provider that took the money. Every observed value is lower case
    // (mock_payme, mock_click), unlike the display-cased catalogue; resolved case-
    // insensitively so it does not matter which the server sends.
    public function gatewayEnum(): ?PaymentProvider
    {
        return $this->gateway === null ? null : PaymentProvider::fromName($this->gateway);
    }

    // Server sends SUM as JSON float; pin to 2 decimals as string, then digit math.
    public function amountTiyin(): int
    {
        return Tiyin::fromSumString(number_format($this->amount, 2, '.', ''));
    }

    /**
     * Has this order reached an answer — paid, cancelled, or cancelled during init?
     *
     * `isPaid` is trusted over the state number when it is set, because a PAID response
     * drops the top-level `state` entirely and only carries it as a STRING inside
     * status_explanation. An order that says it is paid is settled whatever else is
     * missing from the body.
     */
    public function isSettled(): bool
    {
        if ($this->isPaid || $this->isCancelled) {
            return true;
        }

        $settled = config('wlcm.reconcile.settled_states', [2, -1, -2]);

        return is_array($settled) && $this->state !== null && in_array($this->state, $this->ints($settled), true);
    }

    /**
     * Is it worth asking again later?
     *
     * Only a state the config still calls in-motion qualifies. A state the package does
     * not recognise answers FALSE — polling forever on a value nobody modelled is a loop
     * with no exit, and `isSettled()` is the honest question to ask about it instead.
     */
    public function needsPolling(): bool
    {
        // Disabled means "nothing to poll" rather than an error, so calling code keeps
        // working unchanged when the feature is switched off.
        if (config('wlcm.reconcile.enabled', true) !== true) {
            return false;
        }

        if ($this->isSettled()) {
            return false;
        }

        $polling = config('wlcm.reconcile.poll_states', [0, 1]);

        return is_array($polling) && $this->state !== null && in_array($this->state, $this->ints($polling), true);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<int>
     */
    private function ints(array $values): array
    {
        $out = [];

        foreach ($values as $value) {
            if (is_numeric($value)) {
                $out[] = (int) $value;
            }
        }

        return $out;
    }
}
