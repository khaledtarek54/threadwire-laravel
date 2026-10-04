<?php

namespace Threadwire\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Threadwire\Events;

/**
 * Receives a verified event (VerifyWebhookSignature runs first) and
 * dispatches it as Laravel events: WebhookReceived for every event, plus
 * the one for its type. Answer quickly: Threadwire waits 15 seconds, so
 * make slow listeners queued (ShouldQueue).
 */
class WebhookController
{
    /** @var array<string, class-string<Events\ThreadwireEvent>> */
    public const EVENTS = [
        'message.received' => Events\MessageReceived::class,
        'message.sent_from_phone' => Events\MessageSentFromPhone::class,
        'message.status' => Events\MessageStatusUpdated::class,
        'message.edited' => Events\MessageEdited::class,
        'message.deleted' => Events\MessageDeleted::class,
        'message.poll_vote' => Events\PollVoted::class,
        'message.reaction' => Events\ReactionReceived::class,
        'group.message' => Events\GroupMessageReceived::class,
        'group.update' => Events\GroupUpdated::class,
        'call.received' => Events\CallReceived::class,
        'call.rejected' => Events\CallRejected::class,
        'instance.status' => Events\InstanceStatusUpdated::class,
        'verification.completed' => Events\VerificationCompleted::class,
        'verification.failed' => Events\VerificationFailed::class,
        'broadcast.paused' => Events\BroadcastPaused::class,
        'broadcast.completed' => Events\BroadcastCompleted::class,
        'webhook.test' => Events\WebhookTested::class,
    ];

    public function __invoke(Request $request, Dispatcher $events): Response
    {
        $event = json_decode($request->getContent(), true);

        if (! is_array($event) || ! is_string($event['type'] ?? null)) {
            return response()->json(['message' => 'Not a Threadwire event.'], 422);
        }

        $arguments = [
            // The id that was signed, the same as the body's.
            (string) $request->header('webhook-id'),
            $event['type'],
            is_string($event['created_at'] ?? null) ? $event['created_at'] : null,
            is_array($event['data'] ?? null) ? $event['data'] : [],
            $event,
        ];

        $events->dispatch(new Events\WebhookReceived(...$arguments));

        if (isset(self::EVENTS[$event['type']])) {
            $class = self::EVENTS[$event['type']];
            $events->dispatch(new $class(...$arguments));
        }

        return response()->noContent();
    }
}
