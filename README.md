# Threadwire for Laravel

[![Tests](https://github.com/khaledtarek54/threadwire-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/khaledtarek54/threadwire-laravel/actions/workflows/tests.yml)
[![Latest version](https://img.shields.io/packagist/v/tritech/threadwire-laravel)](https://packagist.org/packages/tritech/threadwire-laravel)
[![Downloads](https://img.shields.io/packagist/dt/tritech/threadwire-laravel)](https://packagist.org/packages/tritech/threadwire-laravel)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

The official Laravel package for [Threadwire](https://threadwire.tri-tech.net), the WhatsApp API that keeps your numbers safe.

- **Send** texts, files, locations, contact cards and polls from your own linked WhatsApp numbers, to a person or a group, in one line.
- **Verify phone numbers with WhatsApp**: a one-tap link where the person sends you the code (the safest message there is), or a classic code.
- **Receive webhooks as Laravel events**: replies, delivery and read receipts, reactions and more, with the signature checked for you.
- **Protected by default**: every message goes through Threadwire's pacing, warm-up and daily limits, so a number is far less likely to be banned. A message that would put the number at risk is held or refused, with the reason.

Requires PHP 8.2+ and Laravel 12 or 13, and a [Threadwire](https://threadwire.tri-tech.net) account (7 days free).

## Install

```sh
composer require tritech/threadwire-laravel
```

Laravel finds the service provider and the `Threadwire` facade on its own.

## Configure

In `.env`:

```dotenv
THREADWIRE_API_KEY=your-api-key            # Developers → API keys in your panel
THREADWIRE_WEBHOOK_SECRET=whsec_...        # Webhooks → Signing secret
# THREADWIRE_URL=https://threadwire.tri-tech.net/api/v1   (the default)
# THREADWIRE_WEBHOOK_PATH=threadwire/webhook              (the default)
```

To change more, publish the config: `php artisan vendor:publish --tag=threadwire-config`.

## Send

Each number has an id (`instance_id`), shown on its page in your panel. Send to a phone in international format, or to a group's or channel's `chat_id`:

```php
use Threadwire\Facades\Threadwire;

$message = Threadwire::sendText($numberId, '201012345678', 'Hi Sara, your order 1042 is on its way.', [
    'idempotency_key' => "order-1042-shipped",   // a retry returns this message instead of sending twice
]);

$message['status'];   // "queued": what happens next arrives by webhook (message.status)

Threadwire::sendMedia($numberId, '201012345678', ['url' => 'https://shop.example/invoice-1042.pdf', 'mimetype' => 'application/pdf', 'filename' => 'invoice-1042.pdf'], 'Your invoice');
Threadwire::sendLocation($numberId, '201012345678', 30.0444, 31.2357, 'Our shop');
Threadwire::sendContact($numberId, '201012345678', 'Support', '201000000009');
Threadwire::sendPoll($numberId, '201012345678', 'Which time suits you?', ['10:00', '14:00']);
Threadwire::sendText($numberId, '120363000000000000@g.us', 'Meeting at 5');    // a group

// Any other field of POST /v1/messages goes in the options too:
Threadwire::sendText($numberId, '201012345678', 'See you tomorrow', ['reply_to' => $messageId, 'send_at' => '2026-10-05T09:00:00+03:00']);
```

Read and cancel:

```php
Threadwire::message($id);                           // its status, and the reason in "error" if it failed
Threadwire::messages(['phone' => '201012345678']);  // newest first; "links.next" for older ones
Threadwire::cancelMessage($id);                     // only while it waits: null when it was scheduled; a ConflictException once it went
Threadwire::deleteMessage($id);                     // a sent message, deleted for everyone in the chat (within about two days)
Threadwire::instances();                            // your numbers
Threadwire::instance($numberId)['protection'];      // where the number stands today: new people left, warm-up, safety score
Threadwire::chats(['waiting' => 1]);                // chats waiting for your reply
```

### Verify with WhatsApp

Prove someone holds a WhatsApp number, for a sign-up or a password-free sign-in. Use the link unless you must send the code yourself: the person sends you the code, so your number only replies (the safest message there is, with no night hours or daily limit).

```php
// 1. The link (recommended)
$verification = Threadwire::verifyByLink($numberId, ['brand' => 'Acme', 'reference' => 'signup-7', 'phone' => $phone]);
// show $verification['link'] as a button (or $verification['qr'] on a computer), $verification['code'] as a fallback

// then, in a listener:
Event::listen(\Threadwire\Events\VerificationCompleted::class, function ($event) {
    $phone = $event->payload['data']['phone'];   // sign in by this; never by a null phone
});

// 2. A code your number sends
$verification = Threadwire::sendVerificationCode($numberId, '201012345678', ['brand' => 'Acme']);

try {
    Threadwire::checkVerification($verification['id'], $request->input('code'));   // status: verified
} catch (\Threadwire\Exceptions\ValidationException $e) {
    $e->attemptsLeft();   // wrong code; after the fifth it fails
}

Threadwire::verification($verification['id']);       // status, phone, attempts left; never the code
Threadwire::resendVerification($verification['id']);
Threadwire::cancelVerification($verification['id']);
```

Anything else in the API: `Threadwire::json('get', 'instances/'.$numberId.'/groups')`, or `Threadwire::call(...)` for the raw response. Single items come back as the array under `data`; lists come back whole (`data`, `links`, `meta`). The full reference is at <https://threadwire.tri-tech.net/docs/api>.

## When Threadwire says no

A refusal throws a `Threadwire\Exceptions\ThreadwireException`. Its message is Threadwire's own reason, in plain words, fit to show or log. A subclass names the common cases:

| Exception | Status | What to do |
|---|---|---|
| `ValidationException` | 422 | Fix the request; `$e->errors` says what was wrong with each field |
| `ConflictException` | 409 | Not possible right now (the number is not connected, a message can no longer be cancelled); `$e->retryAfter` when it is worth trying again |
| `RateLimitedException` | 429 | A limit was reached; wait `$e->retryAfter` seconds. The message says which limit |
| `AuthenticationException` | 401 | The key is missing, wrong or revoked |
| `ForbiddenException` | 403 | The key may not do this, or the account is suspended |
| `NotFoundException` | 404 | No such number or message, or not one this key reaches |

A network failure is Laravel's own `Illuminate\Http\Client\ConnectionException`. Nothing is retried for you: retry a send only with the same `idempotency_key`, so it can never go twice.

## Protecting your numbers

WhatsApp bans numbers that behave like bots, so Threadwire sends the way a person would, and some messages wait or are refused:

- A send is answered at once (`queued`) and goes out paced. A message may wait its turn, or be **refused with a reason**: it then ends `failed` with the reason in `error`, in a `message.status` webhook and in `Threadwire::message($id)`.
- **Never send a refused message again in a loop.** The reason says what to do instead ("Try again tomorrow", "let them write first"); a loop only makes WhatsApp trust the number less.
- Replies to people who wrote in the last day go within seconds. Messages to people who never wrote are limited each day, so let people write first: each number's `chat_link` (in `Threadwire::instance()`) opens a chat with it.
- `Threadwire::instance($id)['protection']` shows where a number stands today.

## Receive webhooks

Set your webhook URL in the Threadwire panel (Webhooks → Set webhook URL) to `https://your-app.example/threadwire/webhook`. The package registers that route, outside the `web` group (no session, no CSRF token), and lets through only events signed with your secret within the last 5 minutes; anything else gets 401.

Each event is dispatched as a Laravel event: `Threadwire\Events\WebhookReceived` for every event, and one for its type:

| Webhook | Laravel event |
|---|---|
| `message.received` | `MessageReceived` |
| `message.sent_from_phone` | `MessageSentFromPhone` |
| `message.status` | `MessageStatusUpdated` |
| `message.edited`, `message.deleted` | `MessageEdited`, `MessageDeleted` |
| `message.poll_vote`, `message.reaction` | `PollVoted`, `ReactionReceived` |
| `group.message`, `group.update` | `GroupMessageReceived`, `GroupUpdated` |
| `call.received`, `call.rejected` | `CallReceived`, `CallRejected` |
| `instance.status` | `InstanceStatusUpdated` |
| `webhook.test` | `WebhookTested` |

Every one has `id`, `type`, `createdAt`, `data` (for message events, the message as the API shows it) and `payload` (the whole event). A type added later reaches `WebhookReceived`.

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Threadwire\Events\MessageReceived;

class AnswerCustomer implements ShouldQueue
{
    public function handle(MessageReceived $event): void
    {
        // The same event can come twice (a retry, or "Send again" in the panel): same id.
        if (! Cache::add("threadwire-event:{$event->id}", true, now()->addDay())) {
            return;
        }

        $phone = $event->data['phone'];
        $text = $event->data['body'];
        // ...
    }
}
```

- **Answer within 15 seconds:** make slow listeners queued (`ShouldQueue`), as above. Anything but a 2xx is retried, for about a day.
- **Events can arrive out of order:** use `createdAt` and the message's own times.
- **Several secrets:** an instance with its own webhook URL has its own secret. List them comma-separated in `THREADWIRE_WEBHOOK_SECRET` when they post to the same app. After you make a new secret in the panel, events carry both signatures for 24 hours, so either secret verifies meanwhile.
- **Your own route:** set `THREADWIRE_WEBHOOK_PATH=` (empty) and put the `threadwire.webhook` middleware on a route of yours instead. If you publish the config, keep its `webhook.path` key: without it no route is registered.
- `Threadwire\Webhooks\WebhookSignature` checks a signature anywhere else: `(new WebhookSignature($secret))->verify($id, $timestamp, $signatures, $rawBody)`.

## Testing your app

Fake Threadwire with Laravel's own `Http::fake()`:

```php
Http::fake(['threadwire.tri-tech.net/*' => Http::response(['data' => ['id' => 'msg_1', 'status' => 'queued']], 202)]);
```

## Developing the package (maintainers)

```sh
composer install
composer test
```

The webhook tests sign requests with a copy of Threadwire's own signing code, and Threadwire's test suite verifies its real signatures with this package's `WebhookSignature`, so the two cannot drift apart.

## License

MIT
