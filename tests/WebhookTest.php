<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Threadwire\Events\InstanceStatusUpdated;
use Threadwire\Events\MessageReceived;
use Threadwire\Events\MessageStatusUpdated;
use Threadwire\Events\WebhookReceived;
use Threadwire\Tests\TestCase;
use Threadwire\Webhooks\WebhookSignature;

/*
 * Threadwire's webhooks, received: only genuine, recent ones get through,
 * each as a Laravel event for its type plus WebhookReceived.
 */

/**
 * Signs exactly as Threadwire does (a copy of its DeliverWebhook::headers()),
 * so these tests prove the package accepts what Threadwire really sends.
 */
function threadwireHeaders(string $secret, string $id, int $timestamp, string $body, ?string $previous = null): array
{
    $sign = fn (string $secret): string => 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", base64_decode(str_replace('whsec_', '', $secret)), true));

    return [
        'webhook-id' => $id,
        'webhook-timestamp' => (string) $timestamp,
        'webhook-signature' => implode(' ', array_map($sign, array_filter([$secret, $previous]))),
    ];
}

function threadwireEvent(string $type = 'message.received', array $data = ['id' => 'msg_1', 'phone' => '201012345678', 'body' => 'Hi'], ?array $headers = null, ?string $body = null): TestResponse
{
    // As Threadwire encodes it: JSON with unescaped slashes.
    $body ??= json_encode(['id' => 'evt_01K', 'type' => $type, 'created_at' => '2026-10-04T10:00:00+00:00', 'data' => $data], JSON_UNESCAPED_SLASHES);
    $headers ??= threadwireHeaders(TestCase::SECRET, 'evt_01K', time(), $body);
    $server = ['CONTENT_TYPE' => 'application/json'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('POST', '/threadwire/webhook', server: $server, content: $body);
}

beforeEach(fn () => Event::fake([WebhookReceived::class, MessageReceived::class, MessageStatusUpdated::class, InstanceStatusUpdated::class]));

it('accepts a genuine event and dispatches it as its own Laravel event and as WebhookReceived', function () {
    threadwireEvent()->assertNoContent();

    Event::assertDispatched(MessageReceived::class, fn (MessageReceived $event) => $event->id === 'evt_01K'
        && $event->type === 'message.received'
        && $event->createdAt === '2026-10-04T10:00:00+00:00'
        && $event->data['body'] === 'Hi'
        && $event->payload['data']['phone'] === '201012345678');
    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event) => $event->type === 'message.received');
    Event::assertNotDispatched(MessageStatusUpdated::class);
});

it('dispatches only WebhookReceived for a type it does not know yet', function () {
    threadwireEvent('verification.verified', ['id' => 'ver_1'])->assertNoContent();

    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event) => $event->type === 'verification.verified' && $event->data === ['id' => 'ver_1']);
    Event::assertNotDispatched(MessageReceived::class);
});

it('refuses a wrong signature, a changed body, another secret and missing headers', function () {
    $body = json_encode(['id' => 'evt_01K', 'type' => 'message.received', 'data' => []]);
    $genuine = threadwireHeaders(TestCase::SECRET, 'evt_01K', time(), $body);

    threadwireEvent(headers: [...$genuine, 'webhook-signature' => 'v1,'.base64_encode('nonsense')], body: $body)->assertUnauthorized();
    threadwireEvent(headers: $genuine, body: str_replace('evt_01K', 'evt_other', $body))->assertUnauthorized();
    threadwireEvent(headers: [...$genuine, 'webhook-id' => 'evt_other'], body: $body)->assertUnauthorized();
    threadwireEvent(headers: threadwireHeaders('whsec_'.base64_encode(random_bytes(32)), 'evt_01K', time(), $body), body: $body)->assertUnauthorized();
    threadwireEvent(headers: [], body: $body)->assertUnauthorized();

    Event::assertNothingDispatched();
});

it('refuses an event signed more than five minutes ago, or ahead, so a captured one cannot be replayed', function () {
    $body = json_encode(['id' => 'evt_01K', 'type' => 'message.received', 'data' => []]);

    threadwireEvent(headers: threadwireHeaders(TestCase::SECRET, 'evt_01K', time() - 301, $body), body: $body)->assertUnauthorized();
    threadwireEvent(headers: threadwireHeaders(TestCase::SECRET, 'evt_01K', time() + 301, $body), body: $body)->assertUnauthorized();
    threadwireEvent(headers: threadwireHeaders(TestCase::SECRET, 'evt_01K', time() - 290, $body), body: $body)->assertNoContent();
});

it('accepts an event signed with two secrets while Threadwire rotates, whichever one is configured', function () {
    $new = 'whsec_'.base64_encode(random_bytes(32));
    $body = json_encode(['id' => 'evt_01K', 'type' => 'instance.status', 'data' => ['status' => 'working']]);
    $rotating = threadwireHeaders($new, 'evt_01K', time(), $body, previous: TestCase::SECRET);

    // Still on the old secret.
    threadwireEvent(headers: $rotating, body: $body)->assertNoContent();

    // Switched to the new one.
    config(['threadwire.webhook.secret' => $new]);
    threadwireEvent(headers: $rotating, body: $body)->assertNoContent();

    Event::assertDispatchedTimes(InstanceStatusUpdated::class, 2);
});

it('accepts any of several configured secrets, for instances with their own webhook URL', function () {
    $instanceSecret = 'whsec_'.base64_encode(random_bytes(32));
    config(['threadwire.webhook.secret' => TestCase::SECRET.', '.$instanceSecret]);
    $body = json_encode(['id' => 'evt_01K', 'type' => 'message.received', 'data' => []]);

    threadwireEvent(headers: threadwireHeaders($instanceSecret, 'evt_01K', time(), $body), body: $body)->assertNoContent();
});

it('refuses everything while no secret is configured, and never verifies with an empty key', function () {
    $body = json_encode(['id' => 'evt_01K', 'type' => 'message.received', 'data' => []]);
    $emptyKey = 'v1,'.base64_encode(hash_hmac('sha256', 'evt_01K.'.time().".{$body}", '', true));

    config(['threadwire.webhook.secret' => null]);
    threadwireEvent()->assertUnauthorized();

    config(['threadwire.webhook.secret' => 'whsec_not base64!']);
    threadwireEvent(headers: ['webhook-id' => 'evt_01K', 'webhook-timestamp' => (string) time(), 'webhook-signature' => $emptyKey], body: $body)->assertUnauthorized();

    Event::assertNothingDispatched();
});

it('signs and verifies with the same algorithm on its own, outside a request', function () {
    $signature = WebhookSignature::sign(TestCase::SECRET, 'evt_1', 1759572000, '{"a":1}');
    $verifier = new WebhookSignature(TestCase::SECRET);

    expect($verifier->verify('evt_1', '1759572000', "v1,other {$signature}", '{"a":1}', now: 1759572100))->toBeTrue()
        ->and($verifier->verify('evt_1', '1759572000', $signature, '{"a":2}', now: 1759572100))->toBeFalse()
        ->and($verifier->verify('evt_1', '1759572000', $signature, '{"a":1}', now: 1759572301))->toBeFalse()
        ->and($verifier->verify('evt_1', '17595720.0', $signature, '{"a":1}', now: 1759572100))->toBeFalse();
});

it('can be put on a route of your own with the threadwire.webhook middleware', function () {
    app('router')->post('/my/hooks', fn () => response('ok'))->middleware('threadwire.webhook');
    $body = json_encode(['id' => 'evt_01K', 'type' => 'message.received', 'data' => []]);
    $server = ['CONTENT_TYPE' => 'application/json'];

    foreach (threadwireHeaders(TestCase::SECRET, 'evt_01K', time(), $body) as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $this->call('POST', '/my/hooks', server: $server, content: $body)->assertOk();
    $this->call('POST', '/my/hooks', server: ['CONTENT_TYPE' => 'application/json'], content: $body)->assertUnauthorized();
});
