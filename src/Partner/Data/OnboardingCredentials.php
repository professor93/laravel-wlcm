<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

/**
 * The api_secret here is shown ONCE by the server and never again. It is dropped by
 * Masker before any log store sees it — do not add a path that writes this DTO whole.
 *
 * @phpstan-type OnboardingCredentialsShape array{id: WlcmJsonScalar, name?: WlcmJsonScalar, api_key?: WlcmJsonScalar, api_secret?: WlcmJsonScalar, warning?: WlcmJsonScalar}
 */
final readonly class OnboardingCredentials
{
    public function __construct(
        public int $id,
        public ?string $name,
        public ?string $apiKey,
        public ?string $apiSecret,
        public ?string $warning,
    ) {}

    /** @param OnboardingCredentialsShape $data */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $name = $data['name'] ?? null;
        $apiKey = $data['api_key'] ?? null;
        $apiSecret = $data['api_secret'] ?? null;
        $warning = $data['warning'] ?? null;

        // Guarded rather than bare-cast, unlike the version removed at 25a3e47: this
        // shape is NOT probe-verified against the current API, and a drift must degrade
        // to nulls rather than throw on a call the merchant cannot repeat.
        return new self(
            id: is_numeric($id) ? (int) $id : 0,
            name: is_scalar($name) ? (string) $name : null,
            apiKey: is_scalar($apiKey) ? (string) $apiKey : null,
            apiSecret: is_scalar($apiSecret) ? (string) $apiSecret : null,
            warning: is_scalar($warning) ? (string) $warning : null,
        );
    }
}
