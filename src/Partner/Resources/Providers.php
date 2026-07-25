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

        $rows = is_array($result['results'] ?? null) ? $result['results'] : $result;
        $items = array_values(array_filter($rows, is_array(...)));
        /** @var list<PaymentProviderInfoShape> $items */

        return array_map(PaymentProviderInfo::fromArray(...), $items);
    }
}
