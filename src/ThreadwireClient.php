<?php

namespace Threadwire;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Threadwire\Exceptions\AuthenticationException;
use Threadwire\Exceptions\ConflictException;
use Threadwire\Exceptions\ThreadwireException;

/**
 * The Threadwire API (/api/v1) over Laravel's HTTP client. Each method is
 * one request; nothing is retried by itself, since retrying a send could
 * send it twice (pass an idempotency_key to make a retry safe).
 *
 * Single items (a message, a number, a verification) come back as the
 * array under "data"; lists come back whole ("data" plus "links" and
 * "meta" to page through). A refusal throws a ThreadwireException with
 * Threadwire's reason in plain words.
 */
class ThreadwireClient
{
    public function __construct(
        private readonly Factory $http,
        private readonly ?string $apiKey,
        private readonly string $url = 'https://threadwire.tri-tech.net/api/v1',
        private readonly int $timeout = 30,
    ) {}

    /**
     * Sends a text. $to is a phone in international format (201012345678),
     * or a group's or channel's chat id (…@g.us, …@newsletter).
     *
     * @param  array<string, mixed>  $options  idempotency_key, and any other field of POST /v1/messages
     *                                         (reply_to, send_at, link_preview…)
     * @return array<string, mixed> the message, status "queued" (or "scheduled")
     */
    public function sendText(string $instanceId, string $to, string $text, array $options = []): array
    {
        return $this->send($instanceId, $to, ['text' => $text], $options);
    }

    /**
     * Sends a file: an image, video, voice note or document.
     *
     * @param  array{url?: string, data?: string, mimetype: string, filename?: string}  $media  by public url, or base64 data
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function sendMedia(string $instanceId, string $to, array $media, ?string $caption = null, array $options = []): array
    {
        return $this->send($instanceId, $to, array_filter(['media' => $media, 'text' => $caption], fn ($value) => $value !== null), $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function sendLocation(string $instanceId, string $to, float $latitude, float $longitude, ?string $title = null, array $options = []): array
    {
        return $this->send($instanceId, $to, ['location' => array_filter(['latitude' => $latitude, 'longitude' => $longitude, 'title' => $title], fn ($value) => $value !== null)], $options);
    }

    /**
     * Sends a contact card.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function sendContact(string $instanceId, string $to, string $name, string $phone, ?string $organization = null, array $options = []): array
    {
        return $this->send($instanceId, $to, ['contact' => array_filter(['name' => $name, 'phone' => $phone, 'organization' => $organization], fn ($value) => $value !== null)], $options);
    }

    /**
     * @param  list<string>  $answers  2 to 12
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function sendPoll(string $instanceId, string $to, string $name, array $answers, bool $multipleAnswers = false, array $options = []): array
    {
        return $this->send($instanceId, $to, ['poll' => ['name' => $name, 'options' => array_values($answers), 'multiple_answers' => $multipleAnswers]], $options);
    }

    /**
     * Any message: $content is the body's text, media, location, contact,
     * sticker or poll, as POST /v1/messages takes it.
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function send(string $instanceId, string $to, array $content, array $options = []): array
    {
        $key = $options['idempotency_key'] ?? null;
        unset($options['idempotency_key']);

        // A group or channel goes by its chat id; a person by their phone.
        $recipient = str_contains($to, '@') ? ['chat_id' => $to] : ['to' => $to];

        return $this->data('post', 'messages', ['instance_id' => $instanceId, ...$recipient, ...$content, ...$options], $key);
    }

    /** @return array<string, mixed> the message, with its status and, if it failed, the reason in error */
    public function message(string $id): array
    {
        return $this->data('get', 'messages/'.rawurlencode($id));
    }

    /**
     * Messages, newest first: instance_id, phone, direction, limit; follow
     * links.next (a cursor) for older ones.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function messages(array $query = []): array
    {
        return $this->json('get', 'messages', $query);
    }

    /**
     * Cancels a message that has not gone yet. A scheduled one is removed
     * (null); a queued one is kept as failed, "Cancelled before it was sent."
     *
     * The same endpoint deletes a sent message for everyone, so this first
     * checks that the message is still waiting, and throws a
     * ConflictException if it is not; use deleteMessage() for that.
     *
     * @return array<string, mixed>|null
     */
    public function cancelMessage(string $id): ?array
    {
        $status = $this->message($id)['status'] ?? null;

        if (! in_array($status, ['scheduled', 'queued'], true)) {
            throw new ConflictException("This message is no longer waiting (it is {$status}), so it can no longer be cancelled.", 409);
        }

        $response = $this->call('delete', 'messages/'.rawurlencode($id));

        return $response->status() === 204 ? null : $response->json('data');
    }

    /**
     * Deletes a message your number sent, for everyone in the chat (within
     * about two days of sending). A message still waiting is cancelled
     * instead, as cancelMessage() does.
     *
     * @return array<string, mixed>|null
     */
    public function deleteMessage(string $id): ?array
    {
        $response = $this->call('delete', 'messages/'.rawurlencode($id));

        return $response->status() === 204 ? null : $response->json('data');
    }

    /**
     * Your numbers (instances), a page at a time.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function instances(array $query = []): array
    {
        return $this->json('get', 'instances', $query);
    }

    /**
     * One number, with its status and its protection today ("protection":
     * new people left today, warm-up, the safety score and its reasons).
     *
     * @return array<string, mixed>
     */
    public function instance(string $id): array
    {
        return $this->data('get', 'instances/'.rawurlencode($id));
    }

    /**
     * The people your numbers talk with, newest first: instance_id, waiting, page.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function chats(array $query = []): array
    {
        return $this->json('get', 'chats', $query);
    }

    /**
     * A chat's history from the phone, newest first (also from before
     * Threadwire, about a day before linking): limit, offset. Only a chat the number has.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function chatHistory(string $instanceId, string $phone, array $query = []): array
    {
        return $this->json('get', 'instances/'.rawurlencode($instanceId).'/chats/'.rawurlencode($phone).'/history', $query);
    }

    /**
     * The webhook's events, for polling instead of (or as well as) a webhook:
     * oldest first, each exactly as a webhook carries it (id, type,
     * created_at, data). Pass 'after' => the last answer's next_after to get
     * only newer ones; ask again at once while has_more is true. Also types
     * (a list) and instance_id, limit up to 100.
     *
     * @param  array{after?: string|null, limit?: int, types?: list<string>, instance_id?: string}  $query
     * @return array{data: list<array<string, mixed>>, next_after: string|null, has_more: bool}
     */
    public function events(array $query = []): array
    {
        return $this->json('get', 'events', array_filter($query, fn ($value) => $value !== null));
    }

    /**
     * Verify with WhatsApp, the recommended way: the person sends you the
     * code from the number they claim, so your number only ever replies.
     * Show `link` as a button on a phone (or `qr` on a computer), with `code`
     * as a fallback; a `verification.completed` webhook brings the phone.
     *
     * @param  array{phone?: string, brand?: string, reference?: string, locale?: string, expires_in?: int, idempotency_key?: string}  $options
     * @return array<string, mixed> id, status, code, link, qr, expires_at
     */
    public function verifyByLink(string $instanceId, array $options = []): array
    {
        return $this->createVerification(['instance_id' => $instanceId, 'channel' => 'link', ...array_diff_key($options, ['idempotency_key' => true])], array_intersect_key($options, ['idempotency_key' => true]));
    }

    /**
     * Verify with WhatsApp by a code your number sends; check what the person
     * types with checkVerification(). It never waits: if it cannot go at once
     * (night hours, the day's limit), the verification fails with the reason.
     *
     * @param  array{brand?: string, code_length?: int, reference?: string, locale?: string, expires_in?: int, idempotency_key?: string}  $options
     * @return array<string, mixed> id, status, expires_at (never the code)
     */
    public function sendVerificationCode(string $instanceId, string $phone, array $options = []): array
    {
        return $this->createVerification(['instance_id' => $instanceId, 'channel' => 'code', 'phone' => $phone, ...array_diff_key($options, ['idempotency_key' => true])], array_intersect_key($options, ['idempotency_key' => true]));
    }

    /**
     * Starts a verification with the fields as POST /v1/verifications takes
     * them (instance_id, channel "link" or "code", phone…).
     *
     * @param  array<string, mixed>  $data
     * @param  array{idempotency_key?: string}  $options
     * @return array<string, mixed>
     */
    public function createVerification(array $data, array $options = []): array
    {
        return $this->data('post', 'verifications', $data, $options['idempotency_key'] ?? null);
    }

    /** Cancels a pending verification; a code not sent yet is not sent. */
    public function cancelVerification(string $id): void
    {
        $this->call('delete', 'verifications/'.rawurlencode($id));
    }

    /** @return array<string, mixed> */
    public function verification(string $id): array
    {
        return $this->data('get', 'verifications/'.rawurlencode($id));
    }

    /**
     * Checks the code the person typed: the verification, `verified`. A wrong
     * code throws a ValidationException ($e->attemptsLeft()); one that is
     * expired, failed or used throws a ConflictException.
     *
     * @return array<string, mixed>
     */
    public function checkVerification(string $id, string $code): array
    {
        return $this->data('post', 'verifications/'.rawurlencode($id).'/check', ['code' => $code]);
    }

    /** @return array<string, mixed> */
    public function resendVerification(string $id): array
    {
        return $this->data('post', 'verifications/'.rawurlencode($id).'/resend');
    }

    /**
     * Any other endpoint, by its path under /v1: the answer, decoded.
     *
     * @param  array<string, mixed>  $data  the query for a GET, the JSON body otherwise
     * @return array<string, mixed>
     */
    public function json(string $method, string $path, array $data = [], ?string $idempotencyKey = null): array
    {
        return $this->call($method, $path, $data, $idempotencyKey)->json() ?? [];
    }

    /**
     * The request itself, for when the status or a header matters.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ThreadwireException when Threadwire refuses it
     */
    public function call(string $method, string $path, array $data = [], ?string $idempotencyKey = null): Response
    {
        $method = strtolower($method);
        $request = $this->request();

        if ($idempotencyKey !== null) {
            $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        $response = $method === 'get' ? $request->get($path, $data) : $request->send(strtoupper($method), $path, $data === [] ? [] : ['json' => $data]);

        if ($response->failed()) {
            throw ThreadwireException::fromResponse($response);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function data(string $method, string $path, array $data = [], ?string $idempotencyKey = null): array
    {
        return $this->call($method, $path, $data, $idempotencyKey)->json('data') ?? [];
    }

    private function request(): PendingRequest
    {
        if (blank($this->apiKey)) {
            throw new AuthenticationException('No Threadwire API key: set THREADWIRE_API_KEY.', 401);
        }

        return $this->http
            ->baseUrl(rtrim($this->url, '/'))
            ->withToken($this->apiKey)
            ->acceptJson()
            ->withUserAgent('threadwire-laravel')
            ->timeout($this->timeout);
    }
}
