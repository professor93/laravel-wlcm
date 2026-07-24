<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Resources;

use Uzbek\Wlcm\Partner\Data\PaymentProviderInfo;
use Uzbek\Wlcm\Partner\PartnerClient;

final readonly class Providers
{
    public function __construct(private PartnerClient $client) {}

    /** @return list<PaymentProviderInfo> */
    public function all(): array
    {
        $result = $this->client->get('/payments/providers');
        /** @var array<int, PaymentProviderInfoShape> $result */

        return array_map(PaymentProviderInfo::fromArray(...), array_values($result));
    }
}
