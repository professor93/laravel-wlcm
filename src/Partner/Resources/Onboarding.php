<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Resources;

use Uzbek\Wlcm\Partner\Data\OnboardingCredentials;
use Uzbek\Wlcm\Partner\OnboardingClient;

/**
 * Bootstraps a partner from an onboarding token, before any api key or secret exists.
 *
 * Token auth, no HMAC. The token goes in the QUERY — sending it in the body answers
 * 400 token_required — and the trailing slash on the path is mandatory: without it the
 * request resolves to a different, signed route and answers 401.
 *
 * @phpstan-import-type OnboardingCredentialsShape from OnboardingCredentials
 */
final readonly class Onboarding
{
    private const string PATH = '/partners/onboarding/';

    public function __construct(private OnboardingClient $client) {}

    /**
     * True only on an explicit truthy `valid`. A rejected token does NOT come back
     * false — it is a 400 that throws, because "expired" and "the server said no" are
     * different answers to a merchant mid-bootstrap.
     */
    public function validateToken(string $token): bool
    {
        $result = $this->client->get(self::PATH, ['token' => $token]);

        return ($result['valid'] ?? false) === true;
    }

    /**
     * Consumes the token and mints a partner. The returned api_secret is shown ONCE by
     * the server and never again — surface it to the operator immediately and do not
     * persist it anywhere the merchant will not look.
     */
    public function complete(string $token, string $name): OnboardingCredentials
    {
        $result = $this->client->post(self::PATH, ['name' => $name], ['token' => $token]);
        /** @var OnboardingCredentialsShape $result */

        return OnboardingCredentials::fromArray($result);
    }
}
