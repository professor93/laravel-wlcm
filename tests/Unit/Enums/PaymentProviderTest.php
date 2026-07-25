<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Uzbek\Wlcm\Enums\PaymentProvider;
use Uzbek\Wlcm\Partner\Data\OrderStatus;
use Uzbek\Wlcm\Partner\Data\PaymentProviderInfo;
use Uzbek\Wlcm\Webhooks\WebhookPayload;

final class PaymentProviderTest extends TestCase
{
    public function test_any_casing_of_a_known_name_resolves(): void
    {
        foreach (['Payme', 'PAYME', 'payme', 'PaYmE'] as $name) {
            $this->assertSame(PaymentProvider::Payme, PaymentProvider::fromName($name));
        }

        $this->assertSame(PaymentProvider::Click, PaymentProvider::fromName('Click'));
        $this->assertSame(PaymentProvider::Card, PaymentProvider::fromName('Card'));
    }

    public function test_unknown_names_resolve_to_null(): void
    {
        $this->assertNull(PaymentProvider::fromName('Foo'));
        $this->assertNull(PaymentProvider::fromName(''));
        $this->assertNull(PaymentProvider::fromName(' payme'));
        // Sandbox-only provider, deliberately absent from the enum.
        $this->assertNull(PaymentProvider::fromName('mock_payme'));
    }

    public function test_try_from_keeps_its_exact_match_semantics(): void
    {
        // Display casing is exactly what the live catalogue sends, and tryFrom still misses it.
        $this->assertSame(
            [null, PaymentProvider::Payme],
            [PaymentProvider::tryFrom('Payme'), PaymentProvider::tryFrom('payme')],
        );
    }

    public function test_the_values_we_send_stay_lower_case(): void
    {
        $this->assertSame(
            ['payme', 'click', 'paylov', 'uzum', 'card'],
            array_column(PaymentProvider::cases(), 'value'),
        );
    }

    // Verbatim apidev GET /payments/providers catalogue: display-cased names.
    public function test_the_live_provider_catalogue_resolves(): void
    {
        $catalogue = [
            ['name' => 'Payme', 'label' => 'Payme', 'sort_order' => 1, 'is_active' => true],
            ['name' => 'Click', 'label' => 'Click', 'sort_order' => 2, 'is_active' => true],
            ['name' => 'mock_payme', 'label' => 'Mock Payme (Sandbox)', 'sort_order' => 10, 'is_active' => true],
            ['name' => 'Uzum', 'label' => 'Uzum', 'sort_order' => 3, 'is_active' => false],
            ['name' => 'Paylov', 'label' => 'Paylov', 'sort_order' => 4, 'is_active' => false],
            ['name' => 'Card', 'label' => 'Card', 'sort_order' => 5, 'is_active' => false],
        ];

        $resolved = array_map(
            static fn (array $row): ?PaymentProvider => PaymentProviderInfo::fromArray($row)->providerEnum(),
            $catalogue,
        );

        $this->assertSame([
            PaymentProvider::Payme,
            PaymentProvider::Click,
            null,
            PaymentProvider::Uzum,
            PaymentProvider::Paylov,
            PaymentProvider::Card,
        ], $resolved);
    }

    public function test_webhook_and_order_status_resolve_their_provider_the_same_way(): void
    {
        $payload = WebhookPayload::fromArray([
            'order_id' => '1', 'payment_id' => '2', 'amount' => '10', 'state' => '2',
            'provider' => 'Payme', 'timestamp' => '1750000000',
        ]);
        $this->assertSame(PaymentProvider::Payme, $payload->providerEnum());
        $this->assertSame('Payme', $payload->provider);

        $paid = OrderStatus::fromArray(['order_id' => 410, 'gateway' => 'Click']);
        $this->assertSame(PaymentProvider::Click, $paid->gatewayEnum());
        $this->assertNull(OrderStatus::fromArray(['order_id' => 410])->gatewayEnum());
        $this->assertNull(OrderStatus::fromArray(['order_id' => 410, 'gateway' => 'mock_payme'])->gatewayEnum());
    }
}
