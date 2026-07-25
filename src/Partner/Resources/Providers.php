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

        $items = is_array($result['results'] ?? null) ? $result['results'] : $result;
        /** @var array<int, PaymentProviderInfoShape> $items */

        return array_map(PaymentProviderInfo::fromArray(...), array_values($items));
    }
}
