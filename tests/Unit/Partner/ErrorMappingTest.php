<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Partner;

use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Enums\WlcmErrorCode;
use Uzbek\Wlcm\Exceptions\AuthenticationException;
use Uzbek\Wlcm\Exceptions\ConflictException;
use Uzbek\Wlcm\Exceptions\InsufficientFundsException;
use Uzbek\Wlcm\Exceptions\InvalidOtpException;
use Uzbek\Wlcm\Exceptions\NotFoundException;
use Uzbek\Wlcm\Exceptions\ServerException;
use Uzbek\Wlcm\Exceptions\ValidationException;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Tests\TestCase;

// Fixtures are verbatim sandbox (apidev.wlcm.uz) error bodies.
final class ErrorMappingTest extends TestCase
{
    public function test_401_invalid_api_key_carries_error_code(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            ['detail' => 'Invalid API key', 'code' => 'invalid_api_key'], 401,
        )]);

        try {
            Wlcm::partner()->get('/partners/me');
            $this->fail('expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame('Invalid API key', $e->getMessage());
            $this->assertSame('invalid_api_key', $e->errorCode());
            $this->assertSame(401, $e->responseStatus);
        }
    }

    public function test_401_invalid_signature_error_code(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            ['detail' => 'Invalid signature', 'code' => 'invalid_signature'], 401,
        )]);

        try {
            Wlcm::partner()->get('/partners/me');
            $this->fail('expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame('Invalid signature', $e->getMessage());
            $this->assertSame('invalid_signature', $e->errorCode());
        }
    }

    public function test_401_timestamp_expired_error_code(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            ['detail' => 'Request timestamp expired (diff=600s, max=300s)', 'code' => 'timestamp_expired'], 401,
        )]);

        try {
            Wlcm::partner()->get('/partners/me');
            $this->fail('expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame('Request timestamp expired (diff=600s, max=300s)', $e->getMessage());
            $this->assertSame('timestamp_expired', $e->errorCode());
        }
    }

    public function test_422_fastapi_detail_array_missing_field(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'detail' => [
                [
                    'type' => 'missing',
                    'loc' => ['body', 'return_url'],
                    'msg' => 'Field required',
                    'input' => ['amount' => 1000, 'payment_provider' => 'mock_payme'],
                ],
            ],
        ], 422)]);

        try {
            Wlcm::partner()->post('/integrations/checkout', ['amount' => 1000]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('body.return_url: Field required', $e->getMessage());
            $this->assertSame(['return_url' => ['Field required']], $e->errors());
        }
    }

    public function test_422_fastapi_detail_multiple_items(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'detail' => [
                [
                    'type' => 'missing',
                    'loc' => ['body', 'return_url'],
                    'msg' => 'Field required',
                    'input' => ['amount' => -5],
                ],
                [
                    'type' => 'greater_than',
                    'loc' => ['body', 'amount'],
                    'msg' => 'Input should be greater than 0',
                    'input' => -5,
                    'ctx' => ['gt' => 0],
                ],
            ],
        ], 422)]);

        try {
            Wlcm::partner()->post('/integrations/checkout', ['amount' => -5]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('body.return_url: Field required', $e->getMessage());
            $this->assertSame([
                'return_url' => ['Field required'],
                'amount' => ['Input should be greater than 0'],
            ], $e->errors());
        }
    }

    public function test_404_string_detail_shapes(): void
    {
        $status = 404;
        $detail = '';
        Http::fake(function () use (&$detail) {
            return Http::response(['detail' => $detail], 404);
        });

        foreach ([
            'Not Found',
            'Payment 999999 not found',
            'Fiscal receipt not found for this payment',
        ] as $fixture) {
            $detail = $fixture;

            try {
                Wlcm::partner()->get('/fiscalization', ['payment_id' => 999999]);
                $this->fail('expected NotFoundException');
            } catch (NotFoundException $e) {
                $this->assertSame($fixture, $e->getMessage());
                $this->assertSame($status, $e->responseStatus);
            }
        }
    }

    public function test_non_json_body_falls_back_to_reason(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response('gateway exploded', 502)]);

        try {
            Wlcm::partner()->get('/partners/me');
            $this->fail('expected exception');
        } catch (ServerException $e) {
            $this->assertSame('Bad Gateway', $e->getMessage());
        }
    }

    public function test_409_maps_to_conflict_exception(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            ['detail' => 'Payment is already split'], 409,
        )]);

        try {
            Wlcm::partner()->payments()->split(410, [
                ['recipient_id' => 10, 'recipient_type' => 'brand', 'split_type' => 'fixed', 'amount' => 100000],
            ]);
            $this->fail('expected ConflictException');
        } catch (ConflictException $e) {
            $this->assertSame('Payment is already split', $e->getMessage());
            $this->assertSame(409, $e->responseStatus);
            $this->assertNull($e->errorCode());
        }
    }

    public function test_400_snake_case_detail_becomes_error_code(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            ['detail' => 'subscription_already_exists'], 400,
        )]);

        try {
            Wlcm::partner()->subscriptions()->create('o-1', 'u-1', 100000);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('subscription_already_exists', $e->getMessage());
            $this->assertSame('subscription_already_exists', $e->errorCode());
            $this->assertSame([], $e->errors());
        }
    }

    public function test_400_code_field_wins_over_detail(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            ['detail' => 'card_not_found', 'code' => 'card_not_found', 'data' => null], 400,
        )]);

        try {
            Wlcm::partner()->subscriptions()->attachCard(5, '9860990000000000', '3003');
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('card_not_found', $e->getMessage());
            $this->assertSame('card_not_found', $e->errorCode());
        }
    }

    public function test_invalid_otp_code_maps_to_typed_exception(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'invalid_otp'], 400)]);

        try {
            Wlcm::partner()->payments()->confirmOtp('tx-1', 'cid-1', '000000');
            $this->fail('expected InvalidOtpException');
        } catch (InvalidOtpException $e) {
            $this->assertSame('invalid_otp', $e->errorCode());
            $this->assertSame(WlcmErrorCode::InvalidOtp, $e->errorCodeEnum());
        }
    }

    public function test_otp_expired_maps_to_invalid_otp_exception(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            ['code' => 'otp_expired', 'message' => 'OTP expired'], 400,
        )]);

        try {
            Wlcm::partner()->payments()->confirmOtp('tx-1', 'cid-1', '000000');
            $this->fail('expected InvalidOtpException');
        } catch (InvalidOtpException $e) {
            $this->assertSame('OTP expired', $e->getMessage());
            $this->assertSame(WlcmErrorCode::OtpExpired, $e->errorCodeEnum());
        }
    }

    public function test_insufficient_funds_maps_to_typed_exception(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'insufficient_funds'], 400)]);

        try {
            Wlcm::partner()->subscriptions()->charge(5, 10000, 7);
            $this->fail('expected InsufficientFundsException');
        } catch (InsufficientFundsException $e) {
            $this->assertSame('insufficient_funds', $e->errorCode());
            $this->assertSame(WlcmErrorCode::InsufficientFunds, $e->errorCodeEnum());
        }
    }

    public function test_unknown_code_still_maps_by_status(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'some_future_code'], 400)]);

        try {
            Wlcm::partner()->payments()->confirmOtp('tx-1', 'cid-1', '000000');
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('some_future_code', $e->errorCode());
            $this->assertNull($e->errorCodeEnum());
        }
    }

    public function test_5xx_business_code_stays_server_exception(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            ['code' => 'insufficient_funds', 'message' => 'Insufficient funds'], 500,
        )]);

        try {
            Wlcm::partner()->subscriptions()->charge(5, 10000, 7);
            $this->fail('expected ServerException');
        } catch (ServerException $e) {
            $this->assertSame(500, $e->responseStatus);
            $this->assertSame('insufficient_funds', $e->errorCode());
            $this->assertSame(WlcmErrorCode::InsufficientFunds, $e->errorCodeEnum());
        }
    }

    public function test_401_is_authentication_even_with_business_code(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            ['detail' => 'Invalid API key', 'code' => 'invalid_otp'], 401,
        )]);

        try {
            Wlcm::partner()->payments()->confirmOtp('tx-1', 'cid-1', '000000');
            $this->fail('expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->responseStatus);
            $this->assertSame('invalid_otp', $e->errorCode());
        }
    }

    public function test_404_with_business_code_stays_typed(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'invalid_otp'], 404)]);

        try {
            Wlcm::partner()->payments()->confirmOtp('tx-1', 'cid-1', '000000');
            $this->fail('expected InvalidOtpException');
        } catch (InvalidOtpException $e) {
            $this->assertSame(404, $e->responseStatus);
            $this->assertSame(WlcmErrorCode::InvalidOtp, $e->errorCodeEnum());
        }
    }

    public function test_validation_errors_map_beats_code_relabel(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'errors' => ['amount' => ['too small']],
            'code' => 'insufficient_funds',
        ], 400)]);

        try {
            Wlcm::partner()->subscriptions()->charge(5, 10000, 7);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['amount' => ['too small']], $e->errors());
            $this->assertSame('insufficient_funds', $e->errorCode());
        }
    }

    public function test_404_snake_case_detail_carries_error_code(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'not_found'], 404)]);

        try {
            Wlcm::partner()->subscriptions()->get(999);
            $this->fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame('not_found', $e->getMessage());
            $this->assertSame('not_found', $e->errorCode());
        }
    }

    public function test_400_plain_sentence_detail_readable_message_no_code(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            ['detail' => 'Cancellation is supported only for PAYME and CLICK payments'], 400,
        )]);

        try {
            Wlcm::partner()->payments()->cancel(172);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('Cancellation is supported only for PAYME and CLICK payments', $e->getMessage());
            $this->assertSame([], $e->errors());
            $this->assertNull($e->errorCode());
        }
    }

    public function test_legacy_errors_map_still_supported(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'detail' => 'Validation failed',
            'errors' => ['amount' => ['Must be positive']],
        ], 400)]);

        try {
            Wlcm::partner()->post('/integrations/checkout', ['amount' => -1]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('Validation failed', $e->getMessage());
            $this->assertSame(['amount' => ['Must be positive']], $e->errors());
        }
    }

    public function test_successful_response_with_html_body_throws_server_exception(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            '<html>502 Bad Gateway (but says 200)</html>', 200, ['Content-Type' => 'text/html'],
        )]);

        try {
            Wlcm::partner()->orders()->status(410);
            $this->fail('expected ServerException');
        } catch (ServerException $e) {
            $this->assertStringContainsString('non-JSON body', $e->getMessage());
            $this->assertSame(200, $e->responseStatus);
        }
    }

    public function test_successful_scalar_json_body_throws_server_exception(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response('"ok"', 200)]);

        $this->expectException(ServerException::class);
        Wlcm::partner()->orders()->status(410);
    }

    public function test_successful_empty_object_body_still_returns_array(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response('{}', 200)]);

        $status = Wlcm::partner()->orders()->status(410);

        $this->assertFalse($status->isPaid);
    }
}
