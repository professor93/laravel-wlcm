<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Webhooks;

use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\Webhooks\IpAllowlist;

final class IpAllowlistTest extends TestCase
{
    public function test_null_and_empty_mean_the_allowlist_is_off(): void
    {
        // Off must be unmistakable: a half-configured allowlist that silently allows
        // everything is worse than no allowlist at all.
        $this->assertFalse(IpAllowlist::configured(null));
        $this->assertFalse(IpAllowlist::configured(''));
        $this->assertFalse(IpAllowlist::configured('   '));
        $this->assertFalse(IpAllowlist::configured([]));
        $this->assertTrue(IpAllowlist::configured('1.2.3.4'));
    }

    public function test_it_matches_an_exact_ipv4(): void
    {
        $this->assertTrue(IpAllowlist::allows('1.2.3.4', '1.2.3.4'));
        $this->assertFalse(IpAllowlist::allows('1.2.3.5', '1.2.3.4'));
    }

    public function test_it_reads_a_csv_because_env_values_are_strings(): void
    {
        $list = ' 1.2.3.4 , 10.0.0.0/8 ,, 9.9.9.9 ';

        $this->assertTrue(IpAllowlist::allows('1.2.3.4', $list));
        $this->assertTrue(IpAllowlist::allows('10.1.2.3', $list));
        $this->assertTrue(IpAllowlist::allows('9.9.9.9', $list));
        $this->assertFalse(IpAllowlist::allows('8.8.8.8', $list));
    }

    public function test_it_accepts_an_array_from_a_config_file(): void
    {
        $this->assertTrue(IpAllowlist::allows('1.2.3.4', ['1.2.3.4', '10.0.0.0/8']));
    }

    public function test_it_matches_cidr_v4_and_v6(): void
    {
        $this->assertTrue(IpAllowlist::allows('10.1.2.3', '10.0.0.0/8'));
        $this->assertFalse(IpAllowlist::allows('11.1.2.3', '10.0.0.0/8'));
        $this->assertTrue(IpAllowlist::allows('2001:db8::1', '2001:db8::/32'));
        $this->assertFalse(IpAllowlist::allows('2001:dba::1', '2001:db8::/32'));
    }

    public function test_it_matches_an_inclusive_dash_range(): void
    {
        $this->assertTrue(IpAllowlist::allows('1.2.3.4', '1.2.3.4-1.2.3.9'));
        $this->assertTrue(IpAllowlist::allows('1.2.3.9', '1.2.3.4-1.2.3.9'));
        $this->assertTrue(IpAllowlist::allows('1.2.3.7', '1.2.3.4-1.2.3.9'));
        $this->assertFalse(IpAllowlist::allows('1.2.3.3', '1.2.3.4-1.2.3.9'));
        $this->assertFalse(IpAllowlist::allows('1.2.3.10', '1.2.3.4-1.2.3.9'));
    }

    public function test_a_range_spanning_octets_still_works(): void
    {
        $this->assertTrue(IpAllowlist::allows('1.2.4.0', '1.2.3.250-1.2.5.5'));
    }

    public function test_a_malformed_entry_throws_rather_than_silently_never_matching(): void
    {
        // The dangerous failure is an entry that matches nothing: the operator believes
        // the address is allowed and every delivery is refused, or worse, they widen the
        // list until something works.
        $this->expectException(MissingConfigurationException::class);

        IpAllowlist::allows('1.2.3.4', '1.2.3.4, not-an-ip');
    }

    public function test_a_malformed_range_throws(): void
    {
        $this->expectException(MissingConfigurationException::class);

        IpAllowlist::allows('1.2.3.4', '1.2.3.4-nonsense');
    }

    public function test_an_unparseable_client_ip_never_matches(): void
    {
        // Laravel can hand back null for a request with no resolvable address.
        $this->assertFalse(IpAllowlist::allows(null, '1.2.3.4'));
        $this->assertFalse(IpAllowlist::allows('', '0.0.0.0/0'));
    }
}
