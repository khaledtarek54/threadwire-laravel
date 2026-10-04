<?php

namespace Threadwire\Events;

/**
 * An event Threadwire posted to your webhook, already verified.
 *
 * $id is the same on every try and on a "Send again": keep the ids you have
 * handled and skip a repeat. Events can arrive out of order; use
 * $createdAt and the message's own times.
 */
abstract class ThreadwireEvent
{
    /**
     * @param  array<string, mixed>  $data  the event's data: for message events, the message as GET /v1/messages/{id} shows it
     * @param  array<string, mixed>  $payload  the whole event as posted
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?string $createdAt,
        public readonly array $data,
        public readonly array $payload,
    ) {}
}
