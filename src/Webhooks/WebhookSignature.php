<?php

namespace Threadwire\Webhooks;

/**
 * Checks that a webhook came from Threadwire, per the Standard Webhooks spec
 * (standardwebhooks.com), exactly as Threadwire signs them:
 *
 * - webhook-signature holds one or more space-separated "v1,<signature>";
 * - each signature is a base64 HMAC-SHA256 of "{webhook-id}.{webhook-timestamp}.{body}",
 *   keyed with the base64-decoded part of the secret after "whsec_";
 * - webhook-timestamp is in seconds, and must be within the tolerance of now,
 *   so a captured request cannot be replayed later.
 *
 * Plain PHP with no dependencies, so it can be used (and tested) anywhere.
 */
final class WebhookSignature
{
    /** @var list<string> */
    private array $secrets;

    /**
     * @param  string|list<string>  $secrets  one secret, several, or a comma-separated list
     *                                        (an instance with its own webhook URL has its own secret)
     */
    public function __construct(string|array $secrets, private int $tolerance = 300)
    {
        $list = is_array($secrets) ? $secrets : explode(',', $secrets);

        $this->secrets = array_values(array_filter(array_map('trim', $list), fn (string $secret): bool => $secret !== ''));
    }

    /**
     * Whether the request is genuine: any one of its signatures matches any
     * one of the secrets, and its timestamp is recent. $body is the raw body,
     * exactly as it arrived, before any JSON parsing.
     */
    public function verify(string $id, string $timestamp, string $signatures, string $body, ?int $now = null): bool
    {
        if ($id === '' || ! ctype_digit($timestamp) || abs(($now ?? time()) - (int) $timestamp) > $this->tolerance) {
            return false;
        }

        $given = preg_split('/\s+/', trim($signatures), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($this->secrets as $secret) {
            // A secret that is not valid base64 would sign with an empty key,
            // which anyone could reproduce: it verifies nothing.
            if (self::key($secret) === '') {
                continue;
            }

            $expected = self::sign($secret, $id, $timestamp, $body);

            foreach ($given as $signature) {
                if (hash_equals($expected, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** One "v1,<signature>" for the secret, as Threadwire computes it. */
    public static function sign(string $secret, string $id, string|int $timestamp, string $body): string
    {
        return 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", self::key($secret), true));
    }

    private static function key(string $secret): string
    {
        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, strlen('whsec_')) : $secret, true);

        return $key === false ? '' : $key;
    }
}
