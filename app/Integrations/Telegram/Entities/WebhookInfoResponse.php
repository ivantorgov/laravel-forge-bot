<?php

namespace App\Integrations\Telegram\Entities;

use Illuminate\Support\Fluent;

/**
 * Class WebhookInfoResponse.
 *
 * @property bool|null $ok
 * @property WebhookInfo|null $result
 */
class WebhookInfoResponse extends Fluent
{
    // It behaves as Fluent class.

    /**
     * WebhookInfoResponse constructor.
     *
     * @param array $attributes
     *
     * @return void
     */
    public function __construct($attributes = [])
    {
        $attributes['result'] = new WebhookInfo($attributes['result'] ?? []);

        parent::__construct($attributes);
    }
}