<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Webhooks;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Uzbek\Wlcm\Exceptions\WebhookProxyMisconfiguredException;
use Uzbek\Wlcm\Storage\LogStore;

/**
 * Refuses a delivery from an address outside `wlcm.webhook.allowed_ips`.
 *
 * Runs BEFORE signature verification on purpose: an address we do not serve should never
 * reach the code that does constant-time secret work, and the cheaper check belongs first.
 *
 * The 403 carries no detail. Telling an unknown caller which addresses are allowed, or
 * even that an allowlist exists, is free reconnaissance.
 */
final class AllowWebhookIps
{
    public function __construct(
        private readonly Repository $config,
        private readonly LogStore $logStore,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = $this->config->get('wlcm.webhook.allowed_ips');

        if (! IpAllowlist::configured($allowed)) {
            return $next($request);
        }

        $ip = $request->ip();

        if (IpAllowlist::allows($ip, $allowed)) {
            return $next($request);
        }

        $this->warnAboutProxies($request, $ip);
        $this->recordRejection($request, $ip);

        abort(403);
    }

    /**
     * A forwarded header with no trusted proxies means `ip()` is the load balancer, so
     * the allowlist is matching the wrong address entirely. It fails closed — every
     * delivery is refused — but the operator sees a bare 403 and no reason, so say it.
     */
    private function warnAboutProxies(Request $request, ?string $ip): void
    {
        if (! $request->headers->has('X-Forwarded-For')) {
            return;
        }

        if ($request->getTrustedProxies() !== []) {
            return;
        }

        report(new WebhookProxyMisconfiguredException(
            'wlcm refused a webhook delivery by IP allowlist, and the request carried an X-Forwarded-For header '
            ."while the app trusts no proxies. The address matched against the allowlist was {$ip}, which is "
            .'your load balancer rather than WLCM. Configure TrustProxies, or the allowlist can never match. '
            .'See ADVANCED.md, "Webhooks: IP allowlist".'
        ));
    }

    /**
     * One audit line, so an operator who locked themselves out can see why. Deliberately
     * records the address and nothing else from the request: this path is by definition
     * untrusted, and its body has not been verified against any signature.
     */
    private function recordRejection(Request $request, ?string $ip): void
    {
        try {
            $route = $request->route('connection');
            $default = $this->config->get('wlcm.default', 'main');

            $connection = is_string($route) && $route !== ''
                ? $route
                : (is_scalar($default) ? (string) $default : 'main');

            $this->logStore->webhook($connection, ['ip' => $ip], 'rejected');
        } catch (Throwable $e) {
            // Same posture as every other audit write: a broken sink must not change what
            // the endpoint does. The delivery is refused either way.
            report($e);
        }
    }
}
