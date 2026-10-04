<?php

namespace Threadwire\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Threadwire\Webhooks\WebhookSignature;

/**
 * Lets through only requests signed by Threadwire with one of the
 * configured secrets (threadwire.webhook.secret), sent within the last few
 * minutes. Anything else gets 401 and never reaches your code.
 */
class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $verifier = new WebhookSignature((string) config('threadwire.webhook.secret'), (int) config('threadwire.webhook.tolerance', 300));

        $genuine = $verifier->verify(
            (string) $request->header('webhook-id'),
            (string) $request->header('webhook-timestamp'),
            (string) $request->header('webhook-signature'),
            // The raw body, exactly as signed: never the parsed JSON.
            $request->getContent(),
        );

        if (! $genuine) {
            return response()->json(['message' => 'The webhook signature is missing, wrong or too old.'], 401);
        }

        return $next($request);
    }
}
