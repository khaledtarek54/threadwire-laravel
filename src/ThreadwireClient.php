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
     * Starts a verification (a one-time code by WhatsApp). The verifications
     * endpoints are new: these four follow their announced shape.
     *
     * @param  array<string, mixed>  $data  as POST /v1/verifications takes it (instance_id, to…)
     * @param  array{idempotency_key?: string}  $options
     * @return array<string, mixed> id, status, expires_at, and in the link flow the code and the link the person opens to send it
     */
    public function createVerification(array $data, array $options = []): array
    {
        return $this->data('post', 'verifications', $data, $options['idempotency_key'] ?? null);
    }

    /** @return array<string, mixed> */
    public function verification(string $id): array
    {
        return $this->data('get', 'verifications/'.rawurlencode($id));
    }

    /**
     * Checks the code the person typed.
     *
     * @return array<string, mixed> the verification, with its status
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
