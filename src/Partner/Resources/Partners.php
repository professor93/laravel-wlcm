<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Resources;

use Uzbek\Wlcm\Partner\Data\PartnerInfo;
use Uzbek\Wlcm\Partner\PartnerClient;

final readonly class Partners
{
    // Sandbox-verified: docs code examples sign /integrations/me, which 404s.
    public const string ME_PATH = '/partners/me';

    public function __construct(private PartnerClient $client) {}

    public function me(): PartnerInfo
    {
        $result = $this->client->get(self::ME_PATH);
        /** @var PartnerInfoShape $result */

        return PartnerInfo::fromArray($result);
    }
}
