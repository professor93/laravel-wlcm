<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Uzbek\Wlcm\Webhooks\VerifyWebhookSignature;
use Uzbek\Wlcm\Webhooks\WebhookController;

Route::post(
    rtrim((string) config('wlcm.webhook.path', 'wlcm/webhook'), '/').'/{connection?}',
    WebhookController::class,
)->middleware(VerifyWebhookSignature::class)->name('wlcm.webhook');
