<?php

namespace Threadwire\Exceptions;

/** 422: the request was refused as it stands; errors says what was wrong with each field. Change it before sending it again. */
class ValidationException extends ThreadwireException
{
    /** For a wrong verification code: how many tries are left before it fails. */
    public function attemptsLeft(): ?int
    {
        $left = $this->response?->json('attempts_left') ?? $this->response?->json('data.attempts_left');

        return is_numeric($left) ? (int) $left : null;
    }
}
