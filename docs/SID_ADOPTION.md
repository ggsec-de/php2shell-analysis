# Spike: can the chain choose its own session id? (stock ini, 8.4.26-arm64)

The whole arming shape depends on one assumption: that the session id can be dictated by the
client. Stock configuration says `session.use_only_cookies=1`, which disables the *GET/POST* id
channel — so this was measured before anything was built on top of it. It is also the review's
point (a), and it changed the A1 shape.

Target: `min-lab/` — the stock `php:8.4.26-apache` image pinned by digest
(`sha256:60111400…`), no `php.ini`, one prefork worker, docroot `<?php echo "OK\n"; ?>`.
Tool: a private PoC stage plus raw multipart sends.

## Effective settings

```
no php.ini            (official image, stock defaults)
conf.d: docker-php-ext-opcache.ini, docker-php-ext-sodium.ini
sid_length=32  sid_bits_per_character=4  use_strict_mode=0  use_only_cookies=1
```

## Measured

| # | id | delivered as | response | session file |
|---|---|---|---|---|
| 1 | `\0` (single NUL, the shape 9bie's A1 uses) | multipart field | `Set-Cookie: PHPSESSID=fee30d75…` (generated) | `sess_fee30d75…` — **our id not used** |
| 2 | `ggsecdemo01` | multipart field | `Set-Cookie: PHPSESSID=c966ab1f…` (generated) | generated — **our id not used** |
| 3 | *(none)* | — | `Set-Cookie: PHPSESSID=…` | generated (control) |
| 4 | `ggseckookie02` | Cookie | `Set-Cookie: PHPSESSID=f376cb91…` (generated) | generated — **our id not used** |
| 5 | `0123456789abcdef0123456789` (26 hex) | Cookie | no `Set-Cookie` | `sess_0123456789abcdef0123456789` — **adopted** |
| 6 | 32-hex, fresh, run twice | multipart field | no `Set-Cookie` | `sess_<our id>` created both times — **adopted** |

## What the rule actually is

Adoption is decided by the **character set**, not by the transport and not by length:

- `\0`, `g`, `s`, `d`, `m`, `o`, `k` are not session-id characters (`sid_bits_per_character=4` →
  `[0-9a-f]`), so PHP treats the value as invalid and generates a fresh id — with a `Set-Cookie`
  announcing it;
- a hex string is accepted as-is, and then **no `Set-Cookie` is emitted at all** (the client already
  has the id). That absence is the positive signal.

The multipart field works on a stock `use_only_cookies=1` because the RFC1867 upload-progress
callback reads the field named after `PS(session_name)` directly
(`php_session_rfc1867_callback`, `memcmp(data->name, PS(session_name))`), which is a different
code path from `php_default_treat_data`'s GET/POST channel that the ini setting governs.

`sid_length` on this build is **32**, not the historical 26. Length is not enforced in
non-strict mode, but using 32 hex characters keeps us inside the configured shape.

## Consequence for the spike (superseded by the A1 result below)

In the *spike* shape the conclusion is: a 32-hex id is accepted, `\0` is not. That finding stands
for any stage that must dictate the id itself. For A1 it turned out not to matter — see
"Correction" below.

## Correction: the full A1 shape generates the id itself — and that is fine

Running the real A1 shape (a private PoC stage, 860 nested file parts, chunked) against the same
target changed the conclusion above, and the change matters:

```
attempt 1 sid=9b54…b42c body=1060226 -> HTTP/1.1 200 OK  577 B  oom=True  cookie=Set-Cookie: PHPSESSID=894ccf4d…
     <b>Fatal error</b>:  Allowed memory size of 134217728 bytes exhausted (tried to allocate 12288 bytes) in <b>Unknown</b> on line <b>0</b>
attempt 2 … oom=True  cookie=Set-Cookie: PHPSESSID=e4fbada4…
attempt 3 … oom=True  cookie=Set-Cookie: PHPSESSID=e6d80364…
worker before 60622 / after 60622        (survived)
no /tmp/sess_<any new id>                (the decode died before the write)
```

A binary search over the shape (same builder, one knob at a time) shows adoption is
context-dependent inside RFC1867 and not a property of the id string:

| shape | id used |
|---|---|
| `PHPSESSID` + progress, no file part | generated |
| + 1 file part | **adopted** |
| + 19 file parts | generated |
| + 25 file parts (over `max_file_uploads`) | generated |
| + 1000 / 1100 regular fields | **adopted** |

Whatever the exact branch, the operational conclusion for the chain is the one that matters:

**A1 does not need to dictate the session id.** PHP generates one, sends it in
`Set-Cookie` *before* the OOM kills the request, and the attacker reads it from the response. That
generated id string is what later becomes the stale `PS(id)` the leak stage reads — which is
consistent with the reference shape sending a deliberately invalid `\0`: presence of the field is
what matters, not its value.

So the earlier sentence in this document ("A1 must carry a 32-hex id") is withdrawn. The 32-hex
finding still stands for the *spike* shape and for any stage that must control the id itself
(e.g. attaching to a session the target already knows), but A1 has a simpler contract: send the
field, read the cookie back.

## A1 result on stock 128M (the G1 arming gate)

| criterion | measured |
|---|---|
| OOM at request startup | **3/3** `Allowed memory size of 134217728 bytes exhausted (tried to allocate 12288 bytes)` in `Unknown on line 0` |
| worker survives | yes — prefork child pid 60622 before and after |
| id returned to the client | yes — `Set-Cookie: PHPSESSID=<generated>` on every attempt |
| session file written | no — the decode dies before the flush, which is the point of the shape |
| body size | 1 060 226 B wire, chunked (no `Content-Length`, so no `post_max_size` pre-check) |

That is the arming half of G1. The next gate is B1: does a follow-up request see the stale `PS(id)`
and leak through it.

## Reproduce

```bash
cd ~/labs/local/php2shell-modphp-lab/min-lab
LAB_PORT=8130 LAB_NAME=p2s-min26 docker compose -p p2s-min26 -f docker-compose.yml up -d

cd ..
# 32 hex chars or fewer: rejected
# 26 hex chars: adopted
docker exec p2s-min26 sh -c 'ls -l /tmp/sess_*'
```

Reading the result: a `Set-Cookie` in the response means the id was **rejected**; its absence plus a
`sess_<id>` file means the id was **adopted**. That inversion is worth remembering when reading
logs — an operator scanning for "cookie was set" is looking at the failure case.
