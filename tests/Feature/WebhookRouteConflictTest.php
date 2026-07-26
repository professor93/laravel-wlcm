<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Exceptions;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\Attributes\DefineRoute;
use Uzbek\Wlcm\Exceptions\WebhookRouteConflictException;
use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\Webhooks\VerifyWebhookSignature;

/**
 * A host route at the same method and URI evicts the package route from the
 * collection and takes VerifyWebhookSignature with it.
 *
 * Testbench registers these routes from an app booted() callback queued before
 * BootProviders, so they land after the package provider has registered its own
 * — the order a real app produces too, since RouteServiceProvider::register()
 * queues the app's route loading into booted() as well.
 */
final class WebhookRouteConflictTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The detector runs while the app boots, so the handler has to be faked
        // before booting finishes: Exceptions::fake() inside a test is too late.
        Exceptions::fake();
    }

    protected function disableTheWebhookRoute(Application $app): void
    {
        $app['config']->set('wlcm.webhook.enabled', false);
    }

    protected function hostTakesTheUriUnverified(Router $router): void
    {
        $router->post('wlcm/webhook/{connection?}', fn (): string => 'host handler ran');
    }

    protected function hostTakesTheUriAndVerifies(Router $router): void
    {
        $router->post('wlcm/webhook/{connection?}', fn (): string => 'host handler ran')
            ->middleware(VerifyWebhookSignature::class);
    }

    protected function hostTakesANeighbouringUri(Router $router): void
    {
        $router->post('wlcm/webhook', fn (): string => 'host handler ran');
    }

    /** @return array<string, string> */
    private function webhookData(): array
    {
        $data = [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '1200.00', 'state' => '2', 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
        $data['signature'] = hash_hmac('sha256', "1254:7788:2:{$data['timestamp']}", 'whsec_test');

        return $data;
    }

    #[DefineRoute('hostTakesTheUriUnverified')]
    public function test_a_host_route_that_drops_the_signature_middleware_is_reported(): void
    {
        Exceptions::assertReported(WebhookRouteConflictException::class);
    }

    /** The consequence being reported: an unsigned body reaches the host's handler. */
    #[DefineRoute('hostTakesTheUriUnverified')]
    public function test_the_reported_uri_really_does_serve_an_unverified_body(): void
    {
        $this->postJson('/wlcm/webhook', ['not' => 'signed'])
            ->assertOk()
            ->assertSee('host handler ran');
    }

    /**
     * The URI is served with signature verification intact, so there is nothing
     * to report. A detector that asks whether the package's own route survived
     * reports here, because eviction took our route either way.
     */
    #[DefineRoute('hostTakesTheUriAndVerifies')]
    public function test_a_host_route_that_keeps_the_signature_middleware_is_not_reported(): void
    {
        Exceptions::assertNotReported(WebhookRouteConflictException::class);
    }

    /**
     * 'wlcm/webhook' is a different URI string, so it neither evicts nor shadows
     * the package route: ours is registered first and {connection?} is optional.
     */
    #[DefineRoute('hostTakesANeighbouringUri')]
    public function test_a_host_route_at_a_neighbouring_uri_is_not_reported(): void
    {
        Exceptions::assertNotReported(WebhookRouteConflictException::class);
        $this->postJson('/wlcm/webhook', $this->webhookData())->assertOk();
    }

    public function test_an_uncontested_route_is_not_reported(): void
    {
        Exceptions::assertNotReported(WebhookRouteConflictException::class);
        $this->postJson('/wlcm/webhook', $this->webhookData())->assertOk();
    }

    /** The merchant turned the package route off and owns the URI on purpose. */
    #[DefineEnvironment('disableTheWebhookRoute')]
    #[DefineRoute('hostTakesTheUriUnverified')]
    public function test_nothing_is_reported_when_the_package_route_is_disabled(): void
    {
        Exceptions::assertNotReported(WebhookRouteConflictException::class);
    }
}
