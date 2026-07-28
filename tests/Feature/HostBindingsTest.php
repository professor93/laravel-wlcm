<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Uzbek\Wlcm\Partner\Data\CheckoutResponse;
use Uzbek\Wlcm\Storage\DatabaseLogStore;
use Uzbek\Wlcm\Storage\LogStore;
use Uzbek\Wlcm\Storage\TransactionRecorder;
use Uzbek\Wlcm\Storage\TransactionStore;
use Uzbek\Wlcm\Storage\WebhookOutcome;
use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\Webhooks\SubscriptionWebhookPayload;
use Uzbek\Wlcm\Webhooks\WebhookPayload;
use Uzbek\Wlcm\WlcmManager;
use Uzbek\Wlcm\WlcmServiceProvider;

/**
 * Binding the stores BEFORE the package's provider registers — which is exactly what
 * `Application::configure()->withSingletons([...])` does — was silently overwritten by
 * `singleton()`, so a merchant's audit sink simply never received anything and nothing
 * anywhere said so. Binding AFTER always worked and is what the README documents; this
 * covers the order the README does not mention.
 */
final class HostBindingsTest extends TestCase
{
    /**
     * Binds first, then registers — the production order, and the one Testbench's own
     * environment hooks cannot reproduce because they all run after the package provider.
     * An earlier version of this test used getEnvironmentSetUp() and passed against the
     * broken code, which is the only reason it was checked against a probe first.
     */
    private function bindThenRegister(): void
    {
        app()->singleton(LogStore::class, fn (): LogStore => new HostLogStore);
        app()->singleton(TransactionStore::class, fn (): TransactionStore => new HostTransactionStore);

        (new WlcmServiceProvider(app()))->register();
    }

    public function test_a_store_bound_before_the_provider_survives(): void
    {
        $this->bindThenRegister();

        $this->assertInstanceOf(HostLogStore::class, app(LogStore::class));
        $this->assertInstanceOf(HostTransactionStore::class, app(TransactionStore::class));
    }

    // The manager captures both at construction, so an overwritten binding would leak
    // into every outbound call as well as the webhook path.
    public function test_the_manager_receives_the_host_stores(): void
    {
        $this->bindThenRegister();

        $this->assertInstanceOf(HostTransactionStore::class, app(WlcmManager::class)->partner()->transactions());
    }

    // The other half: with nothing pre-bound, the package must still bind its own.
    public function test_the_package_still_binds_its_own_stores_when_the_host_binds_none(): void
    {
        $this->assertInstanceOf(DatabaseLogStore::class, app(LogStore::class));
        $this->assertInstanceOf(TransactionRecorder::class, app(TransactionStore::class));
    }
}

final class HostLogStore implements LogStore
{
    /** @param array<string, mixed>|null $requestBody */
    public function out(string $connection, string $api, string $method, string $url, ?array $requestBody, mixed $responseBody, ?int $status, ?int $durationMs, array $ids = [], string $result = 'ok'): void {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string|null>  $ids
     */
    public function webhook(string $connection, array $payload, string $result = 'ok', array $ids = []): void {}
}

final class HostTransactionStore implements TransactionStore
{
    /** @param array<string, mixed> $requestPayload */
    public function recordCheckout(string $connection, array $requestPayload, CheckoutResponse $response): void {}

    public function applyWebhook(WebhookPayload $payload, string $token): WebhookOutcome
    {
        return WebhookOutcome::Fresh;
    }

    public function markProcessed(WebhookPayload $payload, string $token): void {}

    public function releaseClaim(WebhookPayload $payload, string $token): void {}

    public function claimSubscription(SubscriptionWebhookPayload $payload, string $token): WebhookOutcome
    {
        return WebhookOutcome::Fresh;
    }

    public function markSubscriptionProcessed(SubscriptionWebhookPayload $payload, string $token): void {}

    public function releaseSubscriptionClaim(SubscriptionWebhookPayload $payload, string $token): void {}
}
