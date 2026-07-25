<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Partner;

use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Enums\WlcmErrorCode;
use Uzbek\Wlcm\Exceptions\AuthenticationException;
use Uzbek\Wlcm\Exceptions\ConflictException;
use Uzbek\Wlcm\Exceptions\ConnectionException;
use Uzbek\Wlcm\Exceptions\InsufficientFundsException;
use Uzbek\Wlcm\Exceptions\InvalidOtpException;
use Uzbek\Wlcm\Exceptions\NotFoundException;
use Uzbek\Wlcm\Exceptions\ServerException;
use Uzbek\Wlcm\Exceptions\ValidationException;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Tests\TestCase;

// Fixtures verbatim sandbox (apidev.wlcm.uz) error bodies.
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

            // Masking bare `input` must not touch values of non-sensitive fields.
            $body = $e->responseBody;
            $this->assertIsArray($body);
            $detail = $body['detail'];
            $this->assertIsArray($detail);
            $this->assertIsArray($detail[0]);
            $this->assertIsArray($detail[1]);
            $this->assertSame(['amount' => -5], $detail[0]['input']);
            $this->assertSame(-5, $detail[1]['input']);
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

    // Verbatim apidev body for GET /orders/999999999/status.
    public function test_404_object_detail_keeps_its_message_and_code(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => [
            'message' => '999999999 ID raqamli buyurtma topilmadi. Iltimos, buyurtma raqamini tekshiring.',
            'code' => 'ORDER_NOT_FOUND',
            'suggestions' => ['Buyurtma raqamini qayta tekshiring', 'Buyurtma mavjudligini tekshiring'],
        ]], 404)]);

        try {
            Wlcm::partner()->get('/orders/999999999/status');
            $this->fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame(
                '999999999 ID raqamli buyurtma topilmadi. Iltimos, buyurtma raqamini tekshiring.',
                $e->getMessage(),
            );
            // Live codes are upper case and have no enum case; the raw string still rides.
            $this->assertSame('ORDER_NOT_FOUND', $e->errorCode());
            $this->assertNull($e->errorCodeEnum());
        }
    }

    // A dict detail is not a pydantic error list, so nothing may land in errors().
    public function test_422_object_detail_yields_no_field_errors(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => [
            'message' => 'amount too small', 'code' => 'AMOUNT_TOO_SMALL',
        ]], 422)]);

        try {
            Wlcm::partner()->post('/integrations/checkout', ['amount' => 1]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('amount too small', $e->getMessage());
            $this->assertSame('AMOUNT_TOO_SMALL', $e->errorCode());
            $this->assertSame([], $e->errors());
        }
    }

    public function test_object_detail_without_message_or_code_degrades_to_the_reason(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => ['suggestions' => ['retry']]], 404)]);

        try {
            Wlcm::partner()->get('/orders/1/status');
            $this->fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame('Not Found', $e->getMessage());
            $this->assertNull($e->errorCode());
        }
    }

    public function test_top_level_code_still_outranks_an_object_detail_code(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'detail' => ['message' => 'no funds', 'code' => 'DETAIL_CODE'],
            'code' => 'insufficient_funds',
        ], 402)]);

        try {
            Wlcm::partner()->get('/payments/1');
            $this->fail('expected InsufficientFundsException');
        } catch (InsufficientFundsException $e) {
            $this->assertSame('no funds', $e->getMessage());
            $this->assertSame('insufficient_funds', $e->errorCode());
        }
    }

    public function test_object_detail_message_is_pan_scrubbed(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => [
            'message' => 'no order for card 4111111111111111',
            'code' => 'ORDER_NOT_FOUND',
        ]], 404)]);

        try {
            Wlcm::partner()->get('/orders/1/status');
            $this->fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame('no order for card 411111***1111', $e->getMessage());
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

    // Constructed, not verbatim: pairs field-level loc with parent-dict `input` Pydantic
    // sends for `missing`, so one fixture covers keyed-recursion arm.
    public function test_thrown_exception_body_is_masked(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'detail' => [
                [
                    'type' => 'value_error',
                    'loc' => ['body', 'secret_key'],
                    'msg' => 'Value error, secret_key is reserved',
                    'input' => ['secret_key' => 'whsec_x', 'card_number' => '9860991234569999'],
                ],
            ],
        ], 400)]);

        try {
            Wlcm::partner()->webhooks()->create('https://app.test/hooks', 'whsec_x');
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('body.secret_key: Value error, secret_key is reserved', $e->getMessage());
            $this->assertSame(['secret_key' => ['Value error, secret_key is reserved']], $e->errors());

            $body = $e->responseBody;
            $this->assertIsArray($body);
            $detail = $body['detail'];
            $this->assertIsArray($detail);
            $this->assertIsArray($detail[0]);
            $input = $detail[0]['input'];
            $this->assertIsArray($input);
            $this->assertArrayNotHasKey('secret_key', $input);
            $this->assertSame('986099******9999', $input['card_number']);
        }
    }

    // Field-level rejection echoes bare failing value under `input`, where no key names it.
    public function test_bare_pan_echo_in_detail_input_is_masked(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'detail' => [
                [
                    'type' => 'string_pattern_mismatch',
                    'loc' => ['body', 'card_number'],
                    'msg' => 'String should match pattern \'^[0-9]{16}$\'',
                    'input' => '8600123412341234',
                ],
            ],
        ], 400)]);

        try {
            Wlcm::partner()->checkout()->create(
                amountTiyin: 120000, externalId: 'ORD-9', provider: 'card',
                cardNumber: '8600123412341234', expireDate: '3003',
            );
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $body = $e->responseBody;
            $this->assertIsArray($body);
            $detail = $body['detail'];
            $this->assertIsArray($detail);
            $this->assertIsArray($detail[0]);
            $this->assertSame('860012******1234', $detail[0]['input']);
        }
    }

    public function test_bare_secret_echo_in_detail_input_is_dropped(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'detail' => [
                [
                    'type' => 'string_too_short',
                    'loc' => ['body', 'secret_key'],
                    'msg' => 'String should have at least 32 characters',
                    'input' => 'whsec_live_X',
                ],
            ],
        ], 400)]);

        try {
            Wlcm::partner()->webhooks()->create('https://app.test/hooks', 'whsec_live_X');
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $body = $e->responseBody;
            $this->assertIsArray($body);
            $detail = $body['detail'];
            $this->assertIsArray($detail);
            $this->assertIsArray($detail[0]);
            $this->assertSame('***', $detail[0]['input']);
        }
    }

    public function test_thrown_exception_string_body_is_truncated(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(str_repeat('x', 2000), 502)]);

        try {
            Wlcm::partner()->get('/partners/me');
            $this->fail('expected ServerException');
        } catch (ServerException $e) {
            $this->assertSame(str_repeat('x', 500), $e->responseBody);
        }
    }

    // Byte 500 of this page lands inside two-byte codepoint; blind cut leaves body
    // unencodable, then json_encode of exception context fails wholesale.
    public function test_thrown_exception_string_body_stays_valid_utf8(): void
    {
        $page = '<html><h1>502 Bad Gateway</h1><p>'
            .str_repeat('Ошибка шлюза, повторите запрос позже. ', 60)
            .'</p></html>';
        Http::fake(['api.wlcm.test/*' => Http::response($page, 502)]);

        try {
            Wlcm::partner()->get('/partners/me');
            $this->fail('expected ServerException');
        } catch (ServerException $e) {
            $body = $e->responseBody;
            $this->assertIsString($body);
            $this->assertTrue(mb_check_encoding($body, 'UTF-8'));
            $this->assertNotFalse(json_encode($body));
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

    public function test_pan_in_malformed_200_body_is_scrubbed(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            '<html>upstream refused card 8600123456789012</html>', 200, ['Content-Type' => 'text/html'],
        )]);

        try {
            Wlcm::partner()->orders()->status(410);
            $this->fail('expected ServerException');
        } catch (ServerException $e) {
            $body = $e->responseBody;
            $this->assertIsString($body);
            $this->assertStringContainsString('860012***9012', $body);
            $this->assertStringNotContainsString('8600123456789012', $body);
        }
    }

    public function test_pan_in_detail_prose_is_scrubbed_from_message_errors_and_body(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'detail' => [
                [
                    'type' => 'value_error',
                    'loc' => ['body', 'card_number'],
                    'msg' => 'Value error, card 8600123456789012 is blocked',
                    'input' => '8600123456789012',
                ],
            ],
        ], 422)]);

        try {
            Wlcm::partner()->checkout()->create(
                amountTiyin: 120000, externalId: 'ORD-9', provider: 'card',
                cardNumber: '8600123456789012', expireDate: '3003',
            );
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('body.card_number: Value error, card 860012***9012 is blocked', $e->getMessage());
            $this->assertStringNotContainsString('8600123456789012', $e->getMessage());
            $this->assertSame(
                ['card_number' => ['Value error, card 860012***9012 is blocked']],
                $e->errors(),
            );

            $body = $e->responseBody;
            $this->assertIsArray($body);
            $detail = $body['detail'];
            $this->assertIsArray($detail);
            $this->assertIsArray($detail[0]);
            $this->assertSame('Value error, card 860012***9012 is blocked', $detail[0]['msg']);
            $this->assertSame('860012******9012', $detail[0]['input']);
        }
    }

    public function test_pan_in_scalar_detail_is_scrubbed_from_response_body(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            ['detail' => 'Card 8600123456789012 already settled'], 409,
        )]);

        try {
            Wlcm::partner()->payments()->cancel(172);
            $this->fail('expected ConflictException');
        } catch (ConflictException $e) {
            $body = $e->responseBody;
            $this->assertIsArray($body);
            $this->assertSame('Card 860012***9012 already settled', $body['detail']);
        }
    }

    public function test_pan_in_legacy_errors_map_is_scrubbed(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'detail' => 'Validation failed',
            'errors' => ['card_number' => ['Card 8600 1234 5678 9012 is expired']],
        ], 400)]);

        try {
            Wlcm::partner()->post('/integrations/checkout', ['amount' => 1000]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['card_number' => ['Card 860012***9012 is expired']], $e->errors());
        }
    }

    public function test_pan_in_string_body_is_scrubbed(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response('upstream refused card 8600123456789012', 502)]);

        try {
            Wlcm::partner()->get('/partners/me');
            $this->fail('expected ServerException');
        } catch (ServerException $e) {
            $this->assertSame('upstream refused card 860012***9012', $e->responseBody);
        }
    }

    public function test_non_luhn_digit_run_in_message_survives_verbatim(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            ['detail' => 'Order 1234567890123456 already settled'], 409,
        )]);

        try {
            Wlcm::partner()->payments()->cancel(172);
            $this->fail('expected ConflictException');
        } catch (ConflictException $e) {
            $this->assertSame('Order 1234567890123456 already settled', $e->getMessage());
        }
    }

    public function test_pan_straddling_the_500_byte_cut_leaves_no_raw_digit_run(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            str_repeat('x', 485).'8600123456789012'.str_repeat('y', 100), 502,
        )]);

        try {
            Wlcm::partner()->get('/partners/me');
            $this->fail('expected ServerException');
        } catch (ServerException $e) {
            $body = $e->responseBody;
            $this->assertIsString($body);
            $this->assertLessThanOrEqual(500, strlen($body));
            $this->assertSame(0, preg_match('/[0-9]{13,}/', $body));
            $this->assertStringContainsString('860012***9012', $body);
        }
    }

    public function test_pan_straddling_the_cut_in_a_malformed_200_body_leaves_no_raw_digit_run(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            str_repeat('x', 485).'8600123456789012'.str_repeat('y', 100), 200, ['Content-Type' => 'text/html'],
        )]);

        try {
            Wlcm::partner()->orders()->status(410);
            $this->fail('expected ServerException');
        } catch (ServerException $e) {
            $body = $e->responseBody;
            $this->assertIsString($body);
            $this->assertLessThanOrEqual(500, strlen($body));
            $this->assertSame(0, preg_match('/[0-9]{13,}/', $body));
            $this->assertStringContainsString('860012***9012', $body);
        }
    }

    public function test_multi_kilobyte_body_is_capped_without_leaking_a_digit_run(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            str_repeat('gateway timeout, ', 500).'8600123456789012'.str_repeat('!', 5000), 502,
        )]);

        try {
            Wlcm::partner()->get('/partners/me');
            $this->fail('expected ServerException');
        } catch (ServerException $e) {
            $body = $e->responseBody;
            $this->assertIsString($body);
            $this->assertLessThanOrEqual(500, strlen($body));
            $this->assertSame(0, preg_match('/[0-9]{13,}/', $body));
        }
    }

    // Scrubbing shortens body, so PAN past byte 500 in raw page still lands inside
    // the 500 kept bytes. Scan window must cover it whole.
    public function test_pan_pulled_under_the_cut_by_earlier_scrubs_leaves_no_raw_digit_run(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            str_repeat('8600 1234 5678 9012,', 24).str_repeat('x', 25).'8600123456789012'.str_repeat('y', 100),
            502,
        )]);

        try {
            Wlcm::partner()->get('/partners/me');
            $this->fail('expected ServerException');
        } catch (ServerException $e) {
            $body = $e->responseBody;
            $this->assertIsString($body);
            $this->assertLessThanOrEqual(500, strlen($body));
            $this->assertSame(0, preg_match('/[0-9]{13,}/', $body));
            $this->assertStringEndsWith(str_repeat('x', 25).'860012***9012'.str_repeat('y', 100), $body);
        }
    }

    public function test_bare_number_error_body_is_scrubbed(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response('8600123456789012', 502)]);

        try {
            Wlcm::partner()->get('/partners/me');
            $this->fail('expected ServerException');
        } catch (ServerException $e) {
            $this->assertSame('860012***9012', $e->responseBody);
        }
    }

    public function test_bare_non_luhn_number_error_body_stays_an_integer(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response('1234567890123456', 502)]);

        try {
            Wlcm::partner()->get('/partners/me');
            $this->fail('expected ServerException');
        } catch (ServerException $e) {
            $this->assertSame(1234567890123456, $e->responseBody);
        }
    }

    // Merchant free-text rides two GET query params, and exception context is what
    // Laravel's default handler writes to the merchant's own log.
    public function test_pan_in_a_query_string_is_scrubbed_from_the_exception_request_path(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'Not found'], 404)]);

        try {
            Wlcm::partner()->fiscalization()->get(externalId: 'order for card 4111111111111111');
            $this->fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertIsString($e->requestPath);
            $this->assertStringContainsString('411111***1111', $e->requestPath);
            $this->assertStringNotContainsString('4111111111111111', $e->requestPath);
        }
    }

    public function test_pan_in_a_query_string_is_scrubbed_from_the_transport_failure_request_path(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::sequence()
            ->pushFailedConnection()
            ->pushFailedConnection()
            ->pushFailedConnection()]);

        try {
            Wlcm::partner()->subscriptions()->findByExternalOrderId('order for card 4111111111111111');
            $this->fail('expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertIsString($e->requestPath);
            $this->assertStringContainsString('411111***1111', $e->requestPath);
            $this->assertStringNotContainsString('4111111111111111', $e->requestPath);
        }
    }

    // Guzzle appends " for {uri}" to every cURL error, so the transport message
    // carries the query string a second time.
    public function test_pan_in_a_transport_error_message_is_scrubbed(): void
    {
        $url = 'https://api.wlcm.test/api/v1/fiscalization?external_id=order+for+card+4111111111111111';
        Http::fake(fn () => throw new HttpConnectionException(
            "cURL error 6: Could not resolve host: api.wlcm.test (see https://curl.se/libcurl/c/libcurl-errors.html) for {$url}",
        ));

        try {
            Wlcm::partner()->fiscalization()->get(externalId: 'order for card 4111111111111111');
            $this->fail('expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('411111***1111', $e->getMessage());
            $this->assertStringNotContainsString('4111111111111111', $e->getMessage());
        }
    }

    public function test_ordinary_transport_error_messages_survive_the_scrub(): void
    {
        Http::fake(fn () => throw new HttpConnectionException('cURL error 28: Operation timed out after 15000 milliseconds'));

        try {
            Wlcm::partner()->get('/partners/me');
            $this->fail('expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertSame('cURL error 28: Operation timed out after 15000 milliseconds', $e->getMessage());
        }
    }

    public function test_ordinary_request_paths_survive_the_exception_scrub(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'Not found'], 404)]);

        foreach ([
            '/payments/7788/split' => 'https://api.wlcm.test/api/v1/payments/7788/split',
            '/partners/me' => 'https://api.wlcm.test/api/v1/partners/me',
        ] as $path => $expected) {
            try {
                Wlcm::partner()->get($path);
                $this->fail('expected NotFoundException');
            } catch (NotFoundException $e) {
                $this->assertSame($expected, $e->requestPath);
            }
        }

        try {
            Wlcm::partner()->fiscalization()->get(externalId: 'ORD-7');
            $this->fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame('https://api.wlcm.test/api/v1/fiscalization?external_id=ORD-7', $e->requestPath);
        }
    }

    public function test_successful_empty_object_body_still_returns_array(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response('{}', 200)]);

        $status = Wlcm::partner()->orders()->status(410);

        $this->assertFalse($status->isPaid);
    }
}
