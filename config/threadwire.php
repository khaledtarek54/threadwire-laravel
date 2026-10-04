<?php

return [

    // An API key from your Threadwire panel (Developers → API keys).
    'api_key' => env('THREADWIRE_API_KEY'),

    // The API's address, up to and including /v1.
    'url' => env('THREADWIRE_URL', 'https://threadwire.tri-tech.net/api/v1'),

    // Seconds to wait for an answer. Sends are answered at once (202) and
    // go out later, paced, so this never needs to cover a send itself.
    'timeout' => (int) env('THREADWIRE_TIMEOUT', 30),

    'webhook' => [
        // Your webhook signing secret (whsec_…), from the Webhooks page. An
        // instance with its own webhook URL has its own secret: list several,
        // comma-separated, when they all post to this app.
        'secret' => env('THREADWIRE_WEBHOOK_SECRET'),

        // Where Threadwire posts events in your app: set your webhook URL to
        // https://your-app/<path>. Null registers no route (put the
        // threadwire.webhook middleware on a route of your own instead).
        'path' => env('THREADWIRE_WEBHOOK_PATH', 'threadwire/webhook'),

        // How old (or how far ahead) an event's webhook-timestamp may be, in
        // seconds, so a captured request cannot be replayed later.
        'tolerance' => 300,
    ],

];
