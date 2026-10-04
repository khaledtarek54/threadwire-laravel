<?php

namespace Threadwire\Exceptions;

/** 422: the request was refused as it stands; errors says what was wrong with each field. Change it before sending it again. */
class ValidationException extends ThreadwireException {}
