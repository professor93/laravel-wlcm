<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Partner;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Enums\WlcmErrorCode;
use Uzbek\Wlcm\Exceptions\InvalidAmountException;
use Uzbek\Wlcm\Exceptions\NotFoundException;
use Uzbek\Wlcm\Exceptions\ValidationException;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Partner\Data\SubscriptionChargeResult;
use Uzbek\Wlcm\Tests\TestCase;

final class SubscriptionsTest extends TestCase
{
    /** Live sandbox POST /subscriptions 201 response, verbatim. */
    private function subscriptionFixture(): array
    {
        return [
            'id' => 5, 'partner_id' => 31, 'card' => null, 'card_number_masked' => null,
            'card_vendor' => null, 'last_payment_id' => null,
            'external_order_id' => 'probe-sub-163442', 'external_user_id' => 'probe-user-1',
            'amount' => '5000.00', 'status' => 'pending', 'payment_id' => null,
            'last_payment_status' => null, 'last_payment_amount' => null, 'last_payment_at' => null,
            'last_payment_transaction_id' => null, 'last_fiscal_number' => null,
            'return_url' => 'https://example.com/return', 'partner_logo_url' => null,
            'created_at' => '2026-07-24T16:34:43.393925Z',
        ];
    }

    public function test_create_converts_tiyin_to_sum(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response($this->subscriptionFixture(), 201)]);

        $sub = Wlcm::partner()->subscriptions()->create(
            externalOrderId: 'probe-sub-163442',
            externalUserId: 'probe-user-1',
            amountTiyin: 500000,
            returnUrl: 'https://example.com/return',
        );

        $this->assertSame(5, $sub->id);
        $this->assertSame(31, $sub->partnerId);
        $this->assertSame('probe-sub-163442', $sub->externalOrderId);
        $this->assertSame('probe-user-1', $sub->externalUserId);
        $this->assertSame('5000.00', $sub->amount);
        $this->assertSame(500000, $sub->amountTiyin());
        $this->assertSame('pending', $sub->status);
        $this->assertNull($sub->paymentId);
        $this->assertNull($sub->cardNumberMasked);
        $this->assertSame('https://example.com/return', $sub->returnUrl);
        $this->assertSame('2026-07-24T16:34:43.393925Z', $sub->createdAt);
        $this->assertNull($sub->raw['card']);

        Http::assertSent(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/subscriptions')
            && $r->body() === '{"amount":5000,"external_order_id":"probe-sub-163442","external_user_id":"probe-user-1","return_url":"https://example.com/return"}');
    }

    public function test_create_rejects_fractional_tiyin(): void
    {
        Http::fake();

        $this->expectException(InvalidAmountException::class);
        Wlcm::partner()->subscriptions()->create('o-1', 'u-1', 150);
    }

    public function test_get_by_id(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response($this->subscriptionFixture(), 200)]);

        $sub = Wlcm::partner()->subscriptions()->get(5);

        $this->assertSame(5, $sub->id);
        Http::assertSent(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/subscriptions/5'));
    }

    public function test_find_by_external_order_id_uses_sub_zero(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response($this->subscriptionFixture(), 200)]);

        $sub = Wlcm::partner()->subscriptions()->findByExternalOrderId('probe-sub-163442');

        $this->assertSame(5, $sub->id);
        Http::assertSent(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/subscriptions/0')
            && str_contains($r->url(), 'external_order_id=probe-sub-163442'));
    }

    public function test_charge_success(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'status' => 'success', 'payment_id' => 98,
        ], 200)]);

        $result = Wlcm::partner()->subscriptions()->charge(5, 10000, 7);

        $this->assertTrue($result->succeeded());
        $this->assertFalse($result->isFailed());
        $this->assertSame(98, $result->paymentId);

        Http::assertSent(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/subscriptions/5/charge')
            && $r->body() === '{"amount":100,"card_id":7}');
    }

    public function test_charge_failed_is_data_not_exception(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'status' => 'failed', 'message' => 'insufficient_funds',
        ], 200)]);

        $result = Wlcm::partner()->subscriptions()->charge(5, 10000, 7);

        $this->assertFalse($result->succeeded());
        $this->assertTrue($result->isFailed());
        $this->assertSame('insufficient_funds', $result->message);
    }

    public function test_charge_failed_exposes_error_code_enum(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'status' => 'failed', 'message' => 'insufficient_funds',
        ], 200)]);

        $result = Wlcm::partner()->subscriptions()->charge(5, 10000, 7);

        $this->assertSame(WlcmErrorCode::InsufficientFunds, $result->errorCodeEnum());
    }

    public function test_charge_failed_human_message_has_no_error_code_enum(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'status' => 'failed', 'message' => 'Not enough money on the card',
        ], 200)]);

        $result = Wlcm::partner()->subscriptions()->charge(5, 10000, 7);

        $this->assertTrue($result->isFailed());
        $this->assertNull($result->errorCodeEnum());
    }

    public function test_charge_success_with_error_shaped_message_has_no_error_code_enum(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'status' => 'success', 'payment_id' => 98, 'message' => 'insufficient_funds',
        ], 200)]);

        $result = Wlcm::partner()->subscriptions()->charge(5, 10000, 7);

        $this->assertTrue($result->succeeded());
        $this->assertNull($result->errorCodeEnum());
    }

    public function test_unmodelled_failure_status_still_exposes_error_code_enum(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'status' => 'declined', 'message' => 'insufficient_funds',
        ], 200)]);

        $result = Wlcm::partner()->subscriptions()->charge(5, 10000, 7);

        $this->assertFalse($result->succeeded());
        $this->assertFalse($result->isFailed());
        $this->assertSame(WlcmErrorCode::InsufficientFunds, $result->errorCodeEnum());
    }

    public function test_charge_success_has_no_error_code_enum(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'status' => 'success', 'payment_id' => 98,
        ], 200)]);

        $result = Wlcm::partner()->subscriptions()->charge(5, 10000, 7);

        $this->assertNull($result->message);
        $this->assertNull($result->errorCodeEnum());
    }

    public function test_charge_result_tolerates_array_typed_status_and_message(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'status' => ['failed'], 'message' => ['insufficient_funds'],
        ], 200)]);

        $result = Wlcm::partner()->subscriptions()->charge(5, 10000, 7);

        $this->assertSame('', $result->status);
        $this->assertNull($result->message);
        $this->assertFalse($result->succeeded());
        $this->assertNull($result->errorCodeEnum());
    }

    // A plausible-looking id from a bad cast is worse than none: merchants refund against it.
    public function test_charge_result_rejects_array_typed_payment_id(): void
    {
        $result = SubscriptionChargeResult::fromArray(['status' => 'success', 'payment_id' => ['9']]);

        $this->assertNull($result->paymentId);
        $this->assertSame(['9'], $result->raw['payment_id']);
    }

    public function test_charge_result_accepts_numeric_string_payment_id(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'status' => 'success', 'payment_id' => '9',
        ], 200)]);

        $result = Wlcm::partner()->subscriptions()->charge(5, 10000, 7);

        $this->assertSame(9, $result->paymentId);
    }

    public function test_charge_result_has_no_payment_id_when_absent(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'status' => 'failed', 'message' => 'insufficient_funds',
        ], 200)]);

        $result = Wlcm::partner()->subscriptions()->charge(5, 10000, 7);

        $this->assertNull($result->paymentId);
    }

    public function test_cancel_returns_full_subscription(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            ['status' => 'cancelled'] + $this->subscriptionFixture(), 200,
        )]);

        $sub = Wlcm::partner()->subscriptions()->cancel(5);

        $this->assertSame(5, $sub->id);
        $this->assertSame('cancelled', $sub->status);
        $this->assertSame('probe-sub-163442', $sub->externalOrderId);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/subscriptions/5/cancel'));
    }

    public function test_double_cancel_maps_error_code(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            ['detail' => 'subscription_already_cancelled'], 400,
        )]);

        try {
            Wlcm::partner()->subscriptions()->cancel(5);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('subscription_already_cancelled', $e->errorCode());
        }
    }

    public function test_attach_card_is_unsigned(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'card_id' => 3, 'otp_sent_phone' => '**********02', 'reused' => false,
        ], 200)]);

        $attached = Wlcm::partner()->subscriptions()->attachCard(5, '9860990000000000', '3003', '+998900000002');

        $this->assertSame(3, $attached->cardId);
        $this->assertSame('**********02', $attached->otpSentPhone);
        $this->assertFalse($attached->reused);

        Http::assertSent(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/subscriptions/5/cards')
            && ! $r->hasHeader('X-API-Key')
            && ! $r->hasHeader('X-Signature')
            && $r->data() === ['card_number' => '9860990000000000', 'expire_date' => '3003', 'phone_number' => '+998900000002']);
    }

    public function test_confirm_card_unsigned_with_optional_card_id(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['status' => 'confirmed'], 200)]);

        $result = Wlcm::partner()->subscriptions()->confirmCard(5, '888888', 3);

        $this->assertSame(['status' => 'confirmed'], $result);
        Http::assertSent(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/subscriptions/5/cards/confirm')
            && ! $r->hasHeader('X-API-Key')
            && $r->data() === ['card_id' => 3, 'otp' => '888888']);
    }

    public function test_activate_unsigned(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['status' => 'active'], 200)]);

        $result = Wlcm::partner()->subscriptions()->activate(5);

        $this->assertSame(['status' => 'active'], $result);
        Http::assertSent(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/subscriptions/5/activate')
            && ! $r->hasHeader('X-API-Key'));
    }

    public function test_user_op_errors_map_through_exception_tree(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            ['detail' => 'Card not confirmed', 'code' => 'card_not_confirmed'], 400,
        )]);

        try {
            Wlcm::partner()->subscriptions()->activate(5);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('Card not confirmed', $e->getMessage());
            $this->assertSame(400, $e->responseStatus);
        }
    }

    public function test_partner_op_404_maps_to_not_found(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            ['detail' => 'Subscription not found', 'code' => 'subscription_not_found'], 404,
        )]);

        $this->expectException(NotFoundException::class);
        Wlcm::partner()->subscriptions()->get(999);
    }
}
