<?php

namespace Threadwire\Exceptions;

/** 409: not possible right now, such as a number that is not connected or a message that can no longer be cancelled. retryAfter says when to try again, when it is worth it. */
class ConflictException extends ThreadwireException {}
