<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use Uzbek\Wlcm\Storage\Masker;

final class MaskerTest extends TestCase
{
    public function test_pan_masked_first6_last4(): void
    {
        $out = Masker::mask(['card_number' => '9860991234569999']);
        $this->assertSame('986099******9999', $out['card_number']);
    }

    public function test_short_pan_fully_masked(): void
    {
        $out = Masker::mask(['card_number' => '12345']);
        $this->assertSame('***', $out['card_number']);
    }

    public function test_sensitive_values_starred(): void
    {
        $out = Masker::mask(['otp' => '111111', 'expire_date' => '3003', 'password' => 'x']);
        $this->assertSame(['otp' => '***', 'expire_date' => '***', 'password' => '***'], $out);
    }

    public function test_secrets_removed(): void
    {
        $out = Masker::mask(['api_secret' => 's', 'access_token' => 't', 'amount' => 100]);
        $this->assertSame(['amount' => 100], $out);
    }

    public function test_recursive(): void
    {
        $out = Masker::mask(['nested' => [['cardNumber' => '9860991234569999']]]);
        $this->assertIsArray($out['nested']);
        $this->assertIsArray($out['nested'][0]);
        $this->assertSame('986099******9999', $out['nested'][0]['cardNumber']);
    }

    public function test_integer_pan_masked(): void
    {
        $out = Masker::mask(['card_number' => 9860991234569999]);
        $this->assertSame('986099******9999', $out['card_number']);
    }

    public function test_webhook_and_api_secrets_removed(): void
    {
        $out = Masker::mask(['secret_key' => 'whsec_live', 'api_key' => 'pk_live', 'client_secret' => 'cs', 'card_token' => 'tok', 'url' => 'https://x.test']);
        $this->assertSame(['url' => 'https://x.test'], $out);
    }

    public function test_pan_shorter_than_13_digits_fully_masked(): void
    {
        $out = Masker::mask(['card_number' => '1234567890', 'pan' => '123456789012']);
        $this->assertSame('***', $out['card_number']);
        $this->assertSame('***', $out['pan']);
    }

    public function test_pan_13_digits_keeps_first6_last4(): void
    {
        $out = Masker::mask(['card_number' => '1234567890123']);
        $this->assertSame('123456***0123', $out['card_number']);
    }

    public function test_pan_wrapped_in_array_fully_masked(): void
    {
        $out = Masker::mask(['card_number' => ['9860991234569999'], 'otp' => ['111111']]);
        $this->assertSame('***', $out['card_number']);
        $this->assertSame('***', $out['otp']);
    }

    public function test_pan_nested_map_fully_masked(): void
    {
        $out = Masker::mask(['card_number' => ['full' => '9860991234569999', 'masked' => 'x']]);
        $this->assertSame('***', $out['card_number']);
    }

    public function test_camel_case_secret_keys_removed(): void
    {
        $out = Masker::mask(['secretKey' => 'w', 'apiKey' => 'p', 'clientSecret' => 'c', 'cardToken' => 't', 'accessToken' => 'a', 'refreshToken' => 'r', 'ok' => 1]);
        $this->assertSame(['ok' => 1], $out);
    }

    public function test_whitespace_padded_secret_key_removed(): void
    {
        $out = Masker::mask([' secret_key' => 'w', 'amount' => 5]);
        $this->assertSame(['amount' => 5], $out);
    }

    public function test_luhn_valid_pan_in_prose_scrubbed(): void
    {
        $this->assertSame(
            'Card 860012***9012 is blocked',
            Masker::scrubPans('Card 8600123456789012 is blocked'),
        );
    }

    public function test_luhn_invalid_run_untouched(): void
    {
        $this->assertSame(
            'Card 8600123412341234 is blocked',
            Masker::scrubPans('Card 8600123412341234 is blocked'),
        );
    }

    public function test_separated_pan_scrubbed(): void
    {
        $this->assertSame('to 860012***9012.', Masker::scrubPans('to 8600 1234 5678 9012.'));
        $this->assertSame('to 860012***9012.', Masker::scrubPans('to 8600-1234-5678-9012.'));
    }

    public function test_runs_shorter_than_13_or_longer_than_19_untouched(): void
    {
        $this->assertSame('ref 123456789015 ok', Masker::scrubPans('ref 123456789015 ok'));
        $this->assertSame('ref 86001234567890123459 ok', Masker::scrubPans('ref 86001234567890123459 ok'));
    }

    public function test_boundary_lengths_13_and_19_scrubbed(): void
    {
        $this->assertSame('a 123456***0128 b', Masker::scrubPans('a 1234567890128 b'));
        $this->assertSame('a 860012***2346 b', Masker::scrubPans('a 8600123456789012346 b'));
    }

    public function test_luhn_invalid_run_containing_valid_substring_untouched(): void
    {
        $this->assertSame('id 8600123456789012000', Masker::scrubPans('id 8600123456789012000'));
    }

    public function test_percent_escaped_prose_does_not_hide_a_pan(): void
    {
        $this->assertSame(
            'ref=order%20for%20card%20411111***1111',
            Masker::scrubPans('ref=order%20for%20card%204111111111111111'),
        );
        $this->assertSame('order%21411111***1111', Masker::scrubPans('order%214111111111111111'));
        $this->assertSame('order%2C411111***1111', Masker::scrubPans('order%2C4111111111111111'));
    }

    public function test_percent_escape_before_a_separated_pan_scrubbed(): void
    {
        $this->assertSame('a%20411111***1111', Masker::scrubPans('a%204111 1111 1111 1111'));
    }

    public function test_literal_percent_before_a_pan_still_scrubbed(): void
    {
        $this->assertSame('off 50%411111***1111', Masker::scrubPans('off 50%4111111111111111'));
    }

    public function test_long_run_after_an_escape_is_left_whole(): void
    {
        $this->assertSame('x%2012345678901234567890 y', Masker::scrubPans('x%2012345678901234567890 y'));
    }

    public function test_luhn_failing_run_after_an_escape_is_left_whole(): void
    {
        $this->assertSame('id%208600123456789012000', Masker::scrubPans('id%208600123456789012000'));
    }

    public function test_percent_escaped_pan_scrub_is_idempotent(): void
    {
        $once = Masker::scrubPans('ref=order%20for%20card%204111111111111111');
        $this->assertSame($once, Masker::scrubPans($once));
    }

    public function test_multiple_pans_both_scrubbed(): void
    {
        $this->assertSame(
            '860012***9012 and 986099***9994 differ',
            Masker::scrubPans('8600123456789012 and 9860991234569994 differ'),
        );
    }

    public function test_text_without_digits_is_identity(): void
    {
        $this->assertSame('Field required', Masker::scrubPans('Field required'));
    }

    public function test_empty_string_is_identity(): void
    {
        $this->assertSame('', Masker::scrubPans(''));
    }

    public function test_scrub_prose_walks_nested_string_leaves(): void
    {
        $out = Masker::scrubProse(['a' => ['b' => 'card 8600123456789012 blocked']]);
        $this->assertSame(['a' => ['b' => 'card 860012***9012 blocked']], $out);
    }

    public function test_scrub_prose_scrubs_integer_leaves(): void
    {
        $out = Masker::scrubProse(['ref' => 8600123456789012]);
        $this->assertSame('860012***9012', $out['ref']);
    }

    public function test_scrub_prose_keeps_untouched_integers_as_integers(): void
    {
        $out = Masker::scrubProse(['amount' => 120000, 'order_id' => 1254, 'run' => 1234567890123456]);
        $this->assertSame(['amount' => 120000, 'order_id' => 1254, 'run' => 1234567890123456], $out);
    }

    public function test_scrub_prose_leaves_masked_form_untouched(): void
    {
        $out = Masker::scrubProse(['card_number' => '860012******9012', 'msg' => 'card 986099******9999 blocked']);
        $this->assertSame(['card_number' => '860012******9012', 'msg' => 'card 986099******9999 blocked'], $out);
    }

    public function test_scrub_prose_is_idempotent_after_mask(): void
    {
        $once = Masker::scrubProse(Masker::mask([
            'card_number' => '8600123456789012',
            'note' => 'card 8600123456789012 blocked',
            'ref' => 8600123456789012,
        ]));

        $this->assertSame('860012******9012', $once['card_number']);
        $this->assertSame('card 860012***9012 blocked', $once['note']);
        $this->assertSame('860012***9012', $once['ref']);
        $this->assertSame($once, Masker::scrubProse($once));
    }

    public function test_scrub_prose_passes_through_other_leaf_types(): void
    {
        $out = Masker::scrubProse(['f' => 1.5, 'b' => true, 'n' => null]);
        $this->assertSame(['f' => 1.5, 'b' => true, 'n' => null], $out);
    }
}
