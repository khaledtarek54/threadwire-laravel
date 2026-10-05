<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Threadwire\Exceptions\AuthenticationException;
use Threadwire\Exceptions\ConflictException;
use Threadwire\Exceptions\NotFoundException;
use Threadwire\Exceptions\RateLimitedException;
use Threadwire\Exceptions\ThreadwireException;
use Threadwire\Exceptions\ValidationException;
use Threadwire\Facades\Threadwire;
use Threadwire\ThreadwireClient;

/*
 * The client: each method is one request to the Threadwire API, with the
 * key, the idempotency key when given, and Threadwire's own reason when it
 * refuses.
 */

const API = 'https://threadwire.test/api/v1';

beforeEach(function () {
    // One fake for the whole file, steered by this: what the API answers.
    $this->answer = fn (Request $request) => Http::response(['data' => ['id' => 'msg_1', 'status' => 'queued']], 202);
    Http::fake(fn (Request $request) => ($this->answer)($request));
});

function lastRequest(): Request
{
    return Http::recorded()->last()[0];
}

it('sends a text to a phone with the API key, and returns the queued message', function () {
    $message = Threadwire::sendText('inst_1', '201012345678', 'Hi Sara, your order is on its way.');

    expect($message)->toBe(['id' => 'msg_1', 'status' => 'queued'])
        ->and(lastRequest())
        ->url()->toBe(API.'/messages')
        ->method()->toBe('POST')
        ->data()->toBe(['instance_id' => 'inst_1', 'to' => '201012345678', 'text' => 'Hi Sara, your order is on its way.'])
        ->and(lastRequest()->header('Authorization'))->toBe(['Bearer test-key'])
        ->and(lastRequest()->header('Accept'))->toBe(['application/json'])
        ->and(lastRequest()->hasHeader('Idempotency-Key'))->toBeFalse();
});

it('sends to a group or channel by its chat id, with an idempotency key and other fields', function () {
    Threadwire::sendText('inst_1', '120363000000000000@g.us', 'Meeting at 5', ['idempotency_key' => 'meeting-5', 'link_preview' => false]);

    expect(lastRequest()->data())->toBe(['instance_id' => 'inst_1', 'chat_id' => '120363000000000000@g.us', 'text' => 'Meeting at 5', 'link_preview' => false])
        ->and(lastRequest()->header('Idempotency-Key'))->toBe(['meeting-5']);
});

it('sends files, places, contact cards and polls in the API\'s shape', function () {
    Threadwire::sendMedia('inst_1', '201012345678', ['url' => 'https://shop.example/invoice.pdf', 'mimetype' => 'application/pdf', 'filename' => 'invoice.pdf'], 'Your invoice');
    expect(lastRequest()->data())->toBe(['instance_id' => 'inst_1', 'to' => '201012345678', 'media' => ['url' => 'https://shop.example/invoice.pdf', 'mimetype' => 'application/pdf', 'filename' => 'invoice.pdf'], 'text' => 'Your invoice']);

    Threadwire::sendLocation('inst_1', '201012345678', 30.0444, 31.2357, 'Our shop');
    expect(lastRequest()->data()['location'])->toBe(['latitude' => 30.0444, 'longitude' => 31.2357, 'title' => 'Our shop']);

    Threadwire::sendContact('inst_1', '201012345678', 'Support', '201000000009');
    expect(lastRequest()->data()['contact'])->toBe(['name' => 'Support', 'phone' => '201000000009']);

    Threadwire::sendPoll('inst_1', '201012345678', 'Pick a time', ['10:00', '14:00'], multipleAnswers: true);
    expect(lastRequest()->data()['poll'])->toBe(['name' => 'Pick a time', 'options' => ['10:00', '14:00'], 'multiple_answers' => true]);
});

it('reads messages, numbers with their protection, and chats', function () {
    $this->answer = fn (Request $request) => match (true) {
        str_ends_with($request->url(), '/instances/inst_1') => Http::response(['data' => ['id' => 'inst_1', 'status' => 'working', 'protection' => ['new_contacts_left_today' => 7]]]),
        default => Http::response(['data' => [['id' => 'x']], 'links' => ['next' => null], 'meta' => []]),
    };

    expect(Threadwire::instance('inst_1')['protection'])->toBe(['new_contacts_left_today' => 7])
        ->and(Threadwire::instances())->toHaveKeys(['data', 'links', 'meta'])
        ->and(lastRequest()->url())->toBe(API.'/instances');

    Threadwire::chats(['instance_id' => 'inst_1', 'waiting' => 1]);
    expect(lastRequest()->url())->toBe(API.'/chats?instance_id=inst_1&waiting=1');

    Threadwire::messages(['phone' => '201012345678']);
    expect(lastRequest()->url())->toBe(API.'/messages?phone=201012345678');

    Threadwire::message('msg_1');
    expect(lastRequest()->url())->toBe(API.'/messages/msg_1');
});

it('polls for events after a cursor, until there are no more', function () {
    $this->answer = fn (Request $request) => str_contains($request->url(), 'after=evt_2')
        ? Http::response(['data' => [], 'next_after' => 'evt_2', 'has_more' => false])
        : Http::response(['data' => [['id' => 'evt_1', 'type' => 'message.received', 'created_at' => '2026-10-04T10:00:00+00:00', 'data' => []], ['id' => 'evt_2', 'type' => 'message.status', 'created_at' => '2026-10-04T10:00:01+00:00', 'data' => []]], 'next_after' => 'evt_2', 'has_more' => false]);

    $first = Threadwire::events(['after' => null, 'types' => ['message.received', 'message.status']]);
    expect(array_column($first['data'], 'id'))->toBe(['evt_1', 'evt_2'])
        ->and(urldecode(lastRequest()->url()))->toBe(API.'/events?types[0]=message.received&types[1]=message.status');

    expect(Threadwire::events(['after' => $first['next_after']]))->toBe(['data' => [], 'next_after' => 'evt_2', 'has_more' => false])
        ->and(lastRequest()->url())->toBe(API.'/events?after=evt_2');

    Threadwire::chatHistory('inst_1', '201012345678', ['limit' => 20]);
    expect(lastRequest()->url())->toBe(API.'/instances/inst_1/chats/201012345678/history?limit=20');
});

it('cancels a message: removed when scheduled, kept as failed when queued', function () {
    $this->answer = fn (Request $request) => $request->method() === 'GET' ? Http::response(['data' => ['id' => 'msg_1', 'status' => 'scheduled']]) : Http::response(null, 204);
    expect(Threadwire::cancelMessage('msg_1'))->toBeNull()
        ->and(lastRequest()->method())->toBe('DELETE');

    $this->answer = fn (Request $request) => $request->method() === 'GET' ? Http::response(['data' => ['id' => 'msg_2', 'status' => 'queued']]) : Http::response(['data' => ['id' => 'msg_2', 'status' => 'failed', 'error' => 'Cancelled before it was sent.']]);
    expect(Threadwire::cancelMessage('msg_2'))->toMatchArray(['status' => 'failed']);
});

it('never deletes a sent message for everyone when asked only to cancel it', function () {
    $this->answer = fn () => Http::response(['data' => ['id' => 'msg_3', 'status' => 'delivered']]);

    expect(fn () => Threadwire::cancelMessage('msg_3'))->toThrow(ConflictException::class, 'This message is no longer waiting (it is delivered), so it can no longer be cancelled.');
    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');

    $this->answer = fn () => Http::response(['data' => ['id' => 'msg_3', 'status' => 'delivered']], 202);
    Threadwire::deleteMessage('msg_3');
    expect(lastRequest())->method()->toBe('DELETE')->url()->toBe(API.'/messages/msg_3');
});

it('takes the HTTP method in any case, a GET always with its query', function () {
    $this->answer = fn () => Http::response(['data' => []]);

    Threadwire::json('GET', 'messages', ['phone' => '201012345678']);

    expect(lastRequest())->method()->toBe('GET')->url()->toBe(API.'/messages?phone=201012345678')->body()->toBe('');
});

it('verifies by link, by code, and reads, checks, resends and cancels an OTP', function () {
    $this->answer = fn () => Http::response(['data' => ['id' => 'ver_1', 'status' => 'pending', 'code' => '48291376', 'link' => 'https://wa.me/201000000001?text=48291376', 'qr' => 'data:image/png;base64,x', 'expires_at' => '2026-10-04T10:10:00+00:00']], 201);

    expect(Threadwire::verifyByLink('inst_1', ['brand' => 'Acme', 'phone' => '201012345678', 'idempotency_key' => 'signup-42']))->toHaveKeys(['id', 'status', 'code', 'link', 'qr', 'expires_at'])
        ->and(lastRequest())->url()->toBe(API.'/otps')->data()->toBe(['instance_id' => 'inst_1', 'channel' => 'link', 'brand' => 'Acme', 'phone' => '201012345678'])
        ->and(lastRequest()->header('Idempotency-Key'))->toBe(['signup-42']);

    Threadwire::sendOtpCode('inst_1', '201012345678', ['brand' => 'Acme', 'code_length' => 6]);
    expect(lastRequest()->data())->toBe(['instance_id' => 'inst_1', 'channel' => 'code', 'phone' => '201012345678', 'brand' => 'Acme', 'code_length' => 6]);

    Threadwire::otp('ver_1');
    expect(lastRequest())->url()->toBe(API.'/otps/ver_1')->method()->toBe('GET');

    Threadwire::checkOtp('ver_1', '123456');
    expect(lastRequest())->url()->toBe(API.'/otps/ver_1/check')->data()->toBe(['code' => '123456']);

    Threadwire::resendOtp('ver_1');
    expect(lastRequest())->url()->toBe(API.'/otps/ver_1/resend')->method()->toBe('POST');

    $this->answer = fn () => Http::response(null, 204);
    Threadwire::cancelOtp('ver_1');
    expect(lastRequest())->url()->toBe(API.'/otps/ver_1')->method()->toBe('DELETE');
});

it('tells how many tries are left after a wrong code', function () {
    $this->answer = fn () => Http::response(['message' => 'That code is not right.', 'attempts_left' => 3], 422);

    try {
        Threadwire::checkOtp('ver_1', '000000');
        $this->fail('A wrong code should throw.');
    } catch (ValidationException $e) {
        expect($e->attemptsLeft())->toBe(3)->and($e->getMessage())->toBe('That code is not right.');
    }
});

it('throws Threadwire\'s own reason, typed by status, with the fields and when to retry', function () {
    $this->answer = fn () => Http::response(['message' => 'The number must be in international format with its country code, like 201012345678.', 'errors' => ['to' => ['The number must be in international format with its country code, like 201012345678.']]], 422);

    try {
        Threadwire::sendText('inst_1', '0101234', 'Hi');
        $this->fail('No exception');
    } catch (ValidationException $e) {
        expect($e->getMessage())->toBe('The number must be in international format with its country code, like 201012345678.')
            ->and($e->status)->toBe(422)
            ->and($e->errors)->toHaveKey('to');
    }

    $this->answer = fn () => Http::response(['message' => 'Too many requests.'], 429, ['Retry-After' => '30']);
    expect(fn () => Threadwire::chats())->toThrow(fn (RateLimitedException $e) => expect($e->retryAfter)->toBe(30)->and($e->getMessage())->toBe('Too many requests.'));

    $this->answer = fn () => Http::response(['message' => 'This number is not connected.'], 409);
    expect(fn () => Threadwire::sendText('inst_1', '201012345678', 'Hi'))->toThrow(ConflictException::class, 'This number is not connected.');

    $this->answer = fn () => Http::response(['message' => 'Unauthenticated.'], 401);
    expect(fn () => Threadwire::instances())->toThrow(AuthenticationException::class);

    $this->answer = fn () => Http::response(['message' => 'Not found.'], 404);
    expect(fn () => Threadwire::message('nope'))->toThrow(NotFoundException::class);

    $this->answer = fn () => Http::response('Bad gateway', 502);
    expect(fn () => Threadwire::instances())->toThrow(ThreadwireException::class, 'Threadwire answered 502.');
});

it('starts a broadcast to recent chats, a label or a list of phones and people, with an idempotency key', function () {
    $this->answer = fn () => Http::response(['data' => ['id' => 'brd_1', 'status' => 'running']], 201);

    expect(Threadwire::createBroadcast('inst_1', 'Hi {name}', 'recent', ['days' => 14, 'idempotency_key' => 'autumn']))->toBe(['id' => 'brd_1', 'status' => 'running'])
        ->and(lastRequest())
        ->url()->toBe(API.'/broadcasts')
        ->method()->toBe('POST')
        ->data()->toBe(['instance_id' => 'inst_1', 'text' => 'Hi {name}', 'audience' => 'recent', 'days' => 14])
        ->and(lastRequest()->header('Idempotency-Key'))->toBe(['autumn']);

    Threadwire::createBroadcast('inst_1', 'Hi {name}, {city}', ['201012345678', ['phone' => '201098765432', 'name' => 'Mona', 'fields' => ['city' => 'Giza']]]);
    expect(lastRequest()->data())->toBe(['instance_id' => 'inst_1', 'text' => 'Hi {name}, {city}', 'audience' => 'phones', 'recipients' => [
        ['phone' => '201012345678'],
        ['phone' => '201098765432', 'name' => 'Mona', 'fields' => ['city' => 'Giza']],
    ]]);

    Threadwire::createBroadcast('inst_1', 'Hi {name}', 'label', ['label_id' => 3]);
    expect(lastRequest()->data())->toMatchArray(['audience' => 'label', 'label_id' => 3]);
});

it('reads, lists, pauses, resumes and cancels broadcasts, and says why one cannot resume', function () {
    $this->answer = fn () => Http::response(['data' => ['id' => 'brd_1', 'status' => 'paused']]);

    Threadwire::broadcast('brd_1');
    expect(lastRequest())->url()->toBe(API.'/broadcasts/brd_1')->method()->toBe('GET');

    Threadwire::broadcasts(['status' => 'running']);
    expect(lastRequest()->url())->toBe(API.'/broadcasts?status=running');

    Threadwire::pauseBroadcast('brd_1');
    expect(lastRequest())->url()->toBe(API.'/broadcasts/brd_1/pause')->method()->toBe('POST');

    Threadwire::cancelBroadcast('brd_1');
    expect(lastRequest())->url()->toBe(API.'/broadcasts/brd_1')->method()->toBe('DELETE');

    $this->answer = fn () => Http::response(['message' => 'WhatsApp warned this number or is limiting it.'], 409);
    expect(fn () => Threadwire::resumeBroadcast('brd_1'))->toThrow(ConflictException::class, 'WhatsApp warned this number');
    expect(lastRequest()->url())->toBe(API.'/broadcasts/brd_1/resume');
});

it('never sends a request without an API key', function () {
    $client = new ThreadwireClient(app(Factory::class), null, API);

    expect(fn () => $client->instances())->toThrow(AuthenticationException::class, 'No Threadwire API key: set THREADWIRE_API_KEY.');
    Http::assertNothingSent();
});
