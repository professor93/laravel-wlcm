<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Partner;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Enums\SplitType;
use Uzbek\Wlcm\Exceptions\InvalidAmountException;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Partner\Data\SplitRecipient;
use Uzbek\Wlcm\Partner\Data\SplitResponse;
use Uzbek\Wlcm\Tests\TestCase;

final class SplitTest extends TestCase
{
    private const array RESPONSE = [
        'payment_id' => 7788, 'split_type' => 'percent', 'total_recipients' => 2,
        'total_split_amount' => 120000,
        'splits' => [
            ['id' => 1, 'recipient_type' => 'brand', 'recipient_id' => 10, 'split_type' => 'percent',
                'amount' => 70, 'percent_value' => 70, 'state' => 1, 'state_text' => 'created'],
            ['id' => 2, 'recipient_type' => 'partner', 'recipient_id' => 22, 'split_type' => 'percent',
                'amount' => 30, 'percent_value' => 30, 'state' => 1, 'state_text' => 'created'],
        ],
    ];

    public function test_create_split(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(self::RESPONSE, 200)]);

        $r = Wlcm::partner()->payments()->split(7788, [
            ['recipient_id' => 10, 'recipient_type' => 'brand', 'split_type' => 'percent', 'amount' => 70],
            ['recipient_id' => 22, 'recipient_type' => 'partner', 'split_type' => 'percent', 'amount' => 30],
        ]);

        $this->assertSame(7788, $r->paymentId);
        $this->assertCount(2, $r->splits);
        $this->assertSame('created', $r->splits[0]->stateText);

        Http::assertSent(fn (Request $req): bool => $req->method() === 'POST'
            && str_ends_with(parse_url($req->url(), PHP_URL_PATH), '/payments/7788/split'));
    }

    public function test_get_split(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(self::RESPONSE, 200)]);

        $r = Wlcm::partner()->payments()->getSplit(7788);

        $this->assertSame('percent', $r->splitType);
        Http::assertSent(fn (Request $req): bool => $req->method() === 'GET');
    }

    /** Live: server treats fixed split amounts as SUM (payment 170 / order 410). */
    private const array FIXED_RESPONSE = [
        'payment_id' => 170, 'split_type' => 'fixed', 'total_recipients' => 1,
        'total_split_amount' => '1000.00',
        'splits' => [
            ['id' => 9, 'recipient_type' => 'partner', 'recipient_id' => 31, 'split_type' => 'fixed',
                'amount' => '1000.00', 'percent_value' => null, 'state' => 2, 'state_text' => 'paid'],
        ],
    ];

    public function test_fixed_amounts_convert_tiyin_to_sum_by_default(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(self::FIXED_RESPONSE, 201)]);

        $r = Wlcm::partner()->payments()->split(170, [
            ['recipient_id' => 31, 'recipient_type' => 'partner', 'split_type' => SplitType::Fixed, 'amount' => 100000],
            ['recipient_id' => 10, 'recipient_type' => 'brand', 'split_type' => 'percent', 'amount' => 30],
        ]);

        $this->assertSame('1000.00', $r->splits[0]->amount);
        $this->assertSame('paid', $r->splits[0]->stateText);

        Http::assertSent(fn (Request $req): bool => $req->body() === '{"recipients":[{"recipient_id":31,"recipient_type":"partner","split_type":"fixed","amount":1000},{"recipient_id":10,"recipient_type":"brand","split_type":"percent","amount":30}]}');
    }

    public function test_fixed_amounts_pass_raw_when_unit_is_tiyin(): void
    {
        config()->set('wlcm.connections.main.partner.split_amount_unit', 'tiyin');
        Http::fake(['api.wlcm.test/*' => Http::response(self::FIXED_RESPONSE, 201)]);

        Wlcm::partner()->payments()->split(170, [
            ['recipient_id' => 31, 'recipient_type' => 'partner', 'split_type' => 'fixed', 'amount' => 100000],
        ]);

        Http::assertSent(fn (Request $req): bool => $req->body() === '{"recipients":[{"recipient_id":31,"recipient_type":"partner","split_type":"fixed","amount":100000}]}');
    }

    public function test_fixed_amount_rejects_fractional_tiyin(): void
    {
        Http::fake();

        $this->expectException(InvalidAmountException::class);
        Wlcm::partner()->payments()->split(170, [
            ['recipient_id' => 31, 'recipient_type' => 'partner', 'split_type' => 'fixed', 'amount' => 150],
        ]);
    }

    public function test_fixed_amount_as_string_throws(): void
    {
        Http::fake();

        $this->expectException(InvalidAmountException::class);
        Wlcm::partner()->payments()->split(7788, [
            ['recipient_id' => 22, 'recipient_type' => 'partner', 'split_type' => 'fixed', 'amount' => '100000'],
        ]);
    }

    public function test_fixed_amount_as_float_throws(): void
    {
        Http::fake();

        $this->expectException(InvalidAmountException::class);
        Wlcm::partner()->payments()->split(7788, [
            ['recipient_id' => 22, 'recipient_type' => 'partner', 'split_type' => 'fixed', 'amount' => 100000.0],
        ]);
    }

    public function test_fixed_amount_string_throws_even_in_tiyin_mode(): void
    {
        config()->set('wlcm.connections.main.partner.split_amount_unit', 'tiyin');
        Http::fake();

        $this->expectException(InvalidAmountException::class);
        Wlcm::partner()->payments()->split(7788, [
            ['recipient_id' => 22, 'recipient_type' => 'partner', 'split_type' => 'fixed', 'amount' => '100000'],
        ]);
    }

    public function test_uppercase_fixed_split_type_still_guards_amount(): void
    {
        Http::fake();

        $this->expectException(InvalidAmountException::class);
        Wlcm::partner()->payments()->split(7788, [
            ['recipient_id' => 22, 'recipient_type' => 'partner', 'split_type' => 'FIXED', 'amount' => '100000'],
        ]);
    }

    public function test_negative_fixed_amount_throws_in_tiyin_mode(): void
    {
        config()->set('wlcm.connections.main.partner.split_amount_unit', 'tiyin');
        Http::fake();

        $this->expectException(InvalidAmountException::class);
        Wlcm::partner()->payments()->split(7788, [
            ['recipient_id' => 22, 'recipient_type' => 'partner', 'split_type' => 'fixed', 'amount' => -100],
        ]);
    }

    public function test_uppercase_split_type_reaches_the_wire_lowercased(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(self::FIXED_RESPONSE, 201)]);

        Wlcm::partner()->payments()->split(170, [
            ['recipient_id' => 31, 'recipient_type' => 'partner', 'split_type' => 'FIXED', 'amount' => 100000],
            ['recipient_id' => 10, 'recipient_type' => 'brand', 'split_type' => 'PERCENT', 'amount' => 30],
        ]);

        Http::assertSent(fn (Request $req): bool => $req->body() === '{"recipients":[{"recipient_id":31,"recipient_type":"partner","split_type":"fixed","amount":1000},{"recipient_id":10,"recipient_type":"brand","split_type":"percent","amount":30}]}');
    }

    /** GREEN-lock: normalizing split_type must never add the key when the caller omitted it. */
    public function test_split_type_key_stays_absent_when_not_sent(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(self::RESPONSE, 201)]);

        Wlcm::partner()->payments()->split(7788, [
            ['recipient_id' => 10, 'recipient_type' => 'brand', 'amount' => 70],
        ]);

        Http::assertSent(fn (Request $req): bool => $req->body() === '{"recipients":[{"recipient_id":10,"recipient_type":"brand","amount":70}]}');
    }

    public function test_split_dtos_tolerate_empty_server_shape(): void
    {
        $recipient = SplitRecipient::fromArray([]);

        $this->assertSame(0, $recipient->id);
        $this->assertSame('', $recipient->recipientType);
        $this->assertSame(0, $recipient->recipientId);
        $this->assertSame('', $recipient->splitType);
        $this->assertSame(0, $recipient->amount);
        $this->assertNull($recipient->percentValue);
        $this->assertSame(0, $recipient->state);
        $this->assertSame('', $recipient->stateText);

        $response = SplitResponse::fromArray([]);

        $this->assertSame(0, $response->paymentId);
        $this->assertSame('', $response->splitType);
        $this->assertSame(0, $response->totalRecipients);
        $this->assertSame(0, $response->totalSplitAmount);
        $this->assertSame([], $response->splits);
    }

    public function test_split_dtos_tolerate_wrong_types(): void
    {
        $recipient = SplitRecipient::fromArray([
            'id' => ['x'], 'recipient_type' => ['x'], 'recipient_id' => ['x'], 'split_type' => ['x'],
            'amount' => ['x'], 'percent_value' => ['x'], 'state' => ['x'], 'state_text' => ['x'],
        ]);

        $this->assertSame(0, $recipient->id);
        $this->assertSame('', $recipient->splitType);
        $this->assertSame(0, $recipient->amount);
        $this->assertNull($recipient->percentValue);

        $response = SplitResponse::fromArray(['payment_id' => 7788, 'splits' => 'not-a-list']);

        $this->assertSame(7788, $response->paymentId);
        $this->assertSame([], $response->splits);
    }

    public function test_split_response_keeps_the_raw_payload(): void
    {
        $payload = ['payment_id' => 7788, 'split_type' => 'fixed', 'total_recipients' => 1];

        $response = SplitResponse::fromArray($payload);

        $this->assertSame(0, $response->totalSplitAmount);
        $this->assertSame($payload, $response->raw);
    }

    public function test_split_response_drops_non_array_rows(): void
    {
        $response = SplitResponse::fromArray(['payment_id' => 7788, 'splits' => ['oops', ['id' => 3]]]);

        $this->assertCount(1, $response->splits);
        $this->assertSame(3, $response->splits[0]->id);
    }
}
