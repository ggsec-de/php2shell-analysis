# A1 calibration: where the OOM must land, and what it leaves behind

Our first working A1 (progress field last, 860 nested file parts) does arm a stock 128M target:
`3/3 Fatal error: Allowed memory size … (tried to allocate 12288 bytes)`. It does **not** leave the
substrate the leak stage needs. This document records why, and the one-line change that fixes it.

## Where the OOM actually fires

the private gdb probe breaks on the longjmp itself and prints a backtrace:

```
[bs] bailout  PS(id)=0x7fff595d7000
#0 _zend_bailout
#1 php_error_cb
#3 zend_error_va_list
#5 zend_mm_safe_error
#6 zend_mm_alloc_pages
#7 _emalloc_large
#8 php_var_unserialize_init        <- 8216 B rounds up to 12288 B
#9 ps_srlzr_decode_php
#10 php_session_decode
#11 php_session_initialize
#12 php_session_rfc1867_callback
#13 rfc1867_post_handler
```

So the bailout happens **inside the session decode**, with `PS(id)` already set. But the decode
wraps itself:

```c
zend_try { decode } zend_catch { php_session_cancel_decode(); zend_bailout(); } zend_end_try();
```

and the second pass is visible in the same trace:

```
[bs] bailout  PS(id)=NULL
#1 php_session_decode     <- the catch re-bails after cancelling
```

`php_session_cancel_decode()` clears the id, so after the request `ps_globals+0x10` reads `0x0` —
nothing left to leak. That is the whole reason the reference shape is called *"END-cleanup
send_cookie OOM"*: the OOM must arrive **after** the decode, so no catch cleans up.

A pressure sweep confirms there is no window in between with the shipped ordering:

| knob | result | `PS(id)` after |
|---|---|---|
| `--big 40…74` | no OOM at all (clean request) | NULL (normal RINIT teardown) |
| `--big 78` | OOM in the decode | **NULL** (cancel path) |
| `--burners 0…12` | no OOM | NULL |
| `--burners 20` | OOM in the decode | **NULL** |

## The fix: arm the session before the pressure

`--progress-first` puts `PHP_SESSION_UPLOAD_PROGRESS` immediately after `PHPSESSID`, so the session
is initialised while the heap still has room. The decode succeeds, the id is adopted, and the OOM
comes later from the bulk fields — outside `php_session_decode`'s catch.

```
=== A1 progress-first
  response: HTTP/1.1 200 OK  915 B  oom=True   (no Set-Cookie: id adopted)
=== PS(id) immediately after, raw
  ps_globals+0x10 (PS(id)) = 0x7fff672540c0
  reads OK; zend_string len=16 data=b'7.Q_\xb0\xc2\x7fh2\xc4,Id\x0bJl'
=== after one plain GET
  ps_globals+0x10 (PS(id)) = NULL
```

Two things to read from that:

1. **The substrate exists.** After A1 the worker holds a non-NULL `PS(id)` pointing into the dead
   request's memory, and the memory is still readable. (The bytes look like a hex id's tail that has
   been partially overwritten — consistent with a freed string, which is what a leak wants.)
2. **It does not survive a normal request.** One plain `GET` clears it, because module RINIT calls
   `php_rinit_session_globals()`. So the window for B1 is the **pre-RINIT part** of the next
   request — the multipart parse, exactly like the `BG(unserialize)` window measured earlier.

That last point is the whole B1 design: the consumer request must be a **multipart** request, so
that the RFC1867 callback touches the stale id before RINIT wipes it.

## Where this leaves G1

| gate | status |
|---|---|
| A1 arms a stock 128M target | **done** — 3/3, worker survives, no session file |
| A1 leaves a stale `PS(id)` | **done with `--progress-first`** — non-NULL and readable after the request |
| the stale id is reachable by a later request | **open** — must be a pre-RINIT (multipart) consumer; a plain GET clears it |
| leak decoded into addresses | **open** — next gate |

## Reproduce

The measurement is a three-step harness: send the arming shape, attach to the worker while it still
holds the request, and read `PS(id)` at each RFC1867 event. The harness itself is part of the private
lab; the numbers it produced are in the tables above, and the same numbers can be re-derived with any
debugger that can break on `php_session_initialize`.
