<?php

namespace Threadwire\Exceptions;

/** 429: a limit was reached (requests a minute, a plan's usage, a number's protection). Wait retryAfter seconds; the message says which limit. */
class RateLimitedException extends ThreadwireException {}
