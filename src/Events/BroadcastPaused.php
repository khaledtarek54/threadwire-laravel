<?php

namespace Threadwire\Events;

/** broadcast.paused: a broadcast paused, by you or by itself to protect the number (id, name, status, reason.code and reason.text, progress). */
class BroadcastPaused extends ThreadwireEvent {}
