<?php

namespace Threadwire\Exceptions;

/** 403: the key may not do this (its access level, its instance or its IP addresses), or the account is suspended. */
class ForbiddenException extends ThreadwireException {}
