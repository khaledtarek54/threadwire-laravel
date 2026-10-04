<?php

namespace Threadwire\Events;

/** message.status: a message you sent was sent, delivered, read or failed. A failed one says why in data.error; never send it again in a loop. */
class MessageStatusUpdated extends ThreadwireEvent {}
