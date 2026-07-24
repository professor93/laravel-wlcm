<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Resources;

use Uzbek\Wlcm\Enums\SplitType;
use Uzbek\Wlcm\Partner\Data;
use Uzbek\Wlcm\Partner\Data\OtpConfirmResponse;
use Uzbek\Wlcm\Partner\PartnerClient;
use Uzbek\Wlcm\Support\Tiyin;

final readonly class Payments
{
    public function __construct(private PartnerClient $client) {}

    public function confirmOtp(string $transactionId, string $cid, string $otp): OtpConfirmResponse
    {
        $result = $this->client->post('/integrations/payment/card/confirm', [
            'cid' => $cid,
            'otp' => $otp,
            'transaction_id' => $transactionId,
        ]);
        /** @var OtpConfirmResponseShape $result */

        return OtpConfirmResponse::fromArray($result);
    }

    /**
     * Reason is an integer code; the server 422s on strings.
     *
     * @return array<string, mixed>
     */
    public function cancel(int $paymentId, ?int $reason = null): array
    {
        $payload = array_filter([
            'payment_id' => $paymentId,
            'reason' => $reason,
        ], fn (mixed $v): bool => $v !== null);

        return $this->client->post('/integrations/payment/cancel', $payload);
    }

    /**
     * Paylov WO confirm. UNVERIFIED: the camelCase body is sandbox-console-derived,
     * never confirmed against a real payment.
     *
     * @return array<string, mixed>
     */
    public function confirmPaylov(string $transactionId, string $otp): array
    {
        return $this->client->post('/integrations/payment/confirm', [
            'otp' => $otp,
            'transactionId' => $transactionId,
        ]);
    }

    /**
     * Fixed-split amounts are int tiyin like everything else in this package.
     * The wire unit is governed by partner.split_amount_unit: 'sum' (default)
     * converts via Tiyin::toSum; 'tiyin' passes raw. Percent values are never
     * touched. Unit is sandbox-proven SUM (3 live signals: server error
     * messages compare fixed totals to the payment SUM value); docs claim
     * tiyin. SDK sends SUM by default, tiyin retained; pending final WLCM
     * confirmation 2026-07-25.
     *
     * Server-enforced rules (not validated client-side): fixed totals must
     * EQUAL the payment amount exactly; duplicate recipient 422; unknown
     * recipient 404; validation order duplicate -> total -> existence.
     *
     * @param  list<array<string, mixed>>  $recipients
     */
    public function split(int $paymentId, array $recipients): Data\SplitResponse
    {
        $result = $this->client->post("/payments/{$paymentId}/split", [
            'recipients' => $this->convertFixedAmounts($recipients),
        ]);
        /** @var SplitResponseShape $result */

        return Data\SplitResponse::fromArray($result);
    }

    /**
     * @param  list<array<string, mixed>>  $recipients
     * @return list<array<string, mixed>>
     */
    private function convertFixedAmounts(array $recipients): array
    {
        if ($this->client->splitAmountUnit() !== 'sum') {
            return $recipients;
        }

        return array_map(static function (array $recipient): array {
            $type = $recipient['split_type'] ?? null;
            $type = $type instanceof SplitType ? $type->value : $type;

            if ($type === SplitType::Fixed->value && is_int($recipient['amount'] ?? null)) {
                // UNIT: sandbox-proven SUM (server compares fixed totals to the payment SUM
                // value, e.g. "Fixed split total 5000 must equal payment amount 10000.00").
                // Pending WLCM confirmation 2026-07-25. If they say tiyin, swap to the
                // commented line below or set partner.split_amount_unit to 'tiyin'.
                $wire = Tiyin::toSum($recipient['amount']); // active: SUM
                // $wire = $recipient['amount'];            // alt: tiyin passthrough (docs claim tiyin) — uncomment if WLCM confirms tiyin
                $recipient['amount'] = $wire;
            }

            return $recipient;
        }, $recipients);
    }

    public function getSplit(int $paymentId): Data\SplitResponse
    {
        $result = $this->client->get("/payments/{$paymentId}/split");
        /** @var SplitResponseShape $result */

        return Data\SplitResponse::fromArray($result);
    }
}
