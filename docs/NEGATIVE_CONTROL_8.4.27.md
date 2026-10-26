# Negative control: the chain must not work on PHP 8.4.27

A positive result is worth nothing without a control that can fail. This document defines what
"no evidence" means for this PoC, records the paired measurement, and states what the measurement does
*and does not* prove.

## Why the definition matters

"No `uid=`" is a bad criterion on its own. A chain can fail to execute for reasons that have nothing to
do with the bug being fixed:

- the arming request stops exhausting memory because the build's memory use shifted slightly;
- the disclosure window shrinks because the allocator geometry changed;
- the layout constants (`S`, `hsv`) moved, so the fake structures land next to the target rather than
  on it;
- the lab itself is broken (wrong ini, no docroot, wrong worker count) and nothing runs anywhere.

So the control requires four signals to agree, on a target that is demonstrably healthy, and it is
paired with a positive run on the same host with the same tools and the same arguments.

## Definition of "no evidence"

| # | signal | positive (8.4.26) | required for "no evidence" (8.4.27) |
|---|---|---|---|
| 1 | target health | 200 `OK`, PHP reports 8.4.26, expected ini | 200 `OK`, PHP reports 8.4.27, **identical** ini values |
| 2 | arming | `tried to allocate 4096` at the disclosure-encode site, `PS(id)` left non-NULL | no allocation failure at any `--big` or `memory_limit` tested; `PS(id)` NULL afterwards |
| 3 | disclosure | `Set-Cookie` ≈ 1.68 M chars | `Set-Cookie` = 32 chars (an ordinary session id), and `leak_addrs` exits non-zero |
| 4 | execution | proof file with `uid=33(www-data)`, worker segfaults | no proof file, request answered normally, **no** segfault — with the addresses measured on 8.4.27 itself |

Signal 4 is the important one: the address set for the negative arm is taken from the running process
(`&zif_system`, `alloc_globals->heap`), so a failure cannot be explained away as "you used the wrong
numbers".

## Environment

| | |
|---|---|
| positive target | `php@sha256:60111400ff77bdc44fa79b4f072afe49a42abba71e0846ad496be4761dc9c11a` (`php:8.4.26-apache`) |
| negative target | `php:8.4.27-apache`, digest `sha256:a0b75cee7023ba2c127138dcad2ecc6ec7e09435534645e70450f29f24ea2a5a` |
| arch / kernel | aarch64, `6.12.34+rpt-rpi-2712` |
| ini | `php.ini-production` active: `memory_limit=128M`, `display_errors=Off`, `post_max_size=8M`, `session.upload_progress.cleanup=On`, `session.use_only_cookies=On` |
| Apache | prefork, one worker (`lab/start_target.sh --single`) for both arms |

Ini values verified identical on both images for `max_input_vars` (1000), `max_input_nesting_level`
(64), `max_multipart_body_parts` (-1), `session.upload_progress.cleanup` (1) and `memory_limit` (128M).

## Result

```
POSITIVE ARM (8.4.26)                        NEGATIVE ARM (8.4.27)
  php            : 8.4.26                      php            : 8.4.27
  OOM in log     : 20480                       OOM in log     : (none)
  B1 Set-Cookie  : 1 679 400 chars             B1 Set-Cookie  : 32 chars
  leak_addrs     : derived addresses           leak_addrs     : refused (fail-closed)
  B2 addresses   : from the leak               B2 addresses   : zif=0x7fff5cc51510 (from gdb)
  proof file     : uid=33(www-data)            proof file     : <none>
  worker crash   : 1 segfault                  worker crash   : 0
```

Both arms ran the same scripts with the same arguments; only the image differs.

### The arming sweep: not a weakened geometry

An early 200 OK could mean "the same bug, but this shape no longer exhausts memory". Two sweeps rule
that out.

1. **Body size.** `--big` from 70 to 90, everything else fixed, at 128M: 8.4.27 answers `200 OK` with a
   normal cookie at every value, and no allocation failure appears. On 8.4.26 the same sweep gives
   `12288`-byte failures at 76–77 and `4096`-byte failures at 78 and above.

2. **Memory limit.** The same body, `memory_limit` forced down and up, fresh container per point:

   | limit | 8.4.26 | 8.4.27 |
   |---|---|---|
   | 384M | 200 OK (no OOM) | 200 OK |
   | 256M | 200 OK (no OOM) | 200 OK |
   | 192M | 200 OK (no OOM) | 200 OK |
   | 128M | **4096 (encode site)** | 200 OK |
   | 96M | 20480 | 200 OK |
   | 64M | 20480 | 200 OK |
   | 32M | 20480 | 2097152 |
   | 16M | 20480 | 2097152 |
   | 8M | 20480 | 2097152 |

   Two things matter here. The positive arm is **sensitive** to the limit — it arms at 128M and stops
   arming once there is room (192M and above), which proves the sweep measures what it claims to. And
   the `4096` signature — the one-page allocation the chain needs — never appears on 8.4.27 at any
   limit or any body size tested. Below 64M the 8.4.27 failure is a 2 MiB chunk allocation, a
   different site entirely.

3. **State after arming.** On 8.4.26 the same A1 leaves `PS(id) = 0x7fff…337000` with
   `main − idchunk = 0x400000` and the chunk `free_pages = 0`. On 8.4.27, `PS(id) = NULL`.

The cheap-OOM arming behaves the same way: A2 (same request structure, with the three counts recalibrated for this build)
returns `211 / HTTP 500` with `tried to allocate 20480` on 8.4.26, and `195 / HTTP 200` with no
allocation failure on 8.4.27.

## Searching for an alternative arming shape on 8.4.27

A negative control only says "the shape we know does not work". So the shape space was searched before
the conclusion was written down.

### What the build actually does with the body

`lab/www/probe.php` prints what a request produced. The same 200-part nested body on both builds:

| | 8.4.26 | 8.4.27 |
|---|---|---|
| top-level `$_POST` keys | 3 | 3 |
| nodes built | 12 604 | 12 604 |
| nesting depth | 64 | 64 |
| peak memory | 38 MiB (scale check: 24–44 MiB over 200–860 parts) | 22 MiB (12–22 MiB over the same range) |

So the input surface is **unchanged** — the same arrays, the same depth. 8.4.27 simply spends
substantially less memory building them (roughly half to a third). That is why the arming needs a
bigger body there.

### A new limit appeared in 8.4.27

Pushing the part count past ~1020 produces, on 8.4.27 only:

```
PHP Warning: PHP Request Startup: Multipart body parts limit exceeded 1020.
To increase the limit change max_multipart_body_parts in php.ini.
```

and the request is answered `200` with **no session cookie at all** — input parsing is abandoned at
startup. The same 950-part body on 8.4.26 produces no such warning. The value 1020 is derived from
`max_input_vars = 1000` (both ini entries are commented out in `php.ini-production`, so the compiled
defaults apply), which effectively caps the amplification an attacker can send by default.

### The shape space that was searched

The same points were run on both builds, one fresh container per point (a dirty heap invalidates the
arming), same tools and same arguments — the private sweep script regenerates the table. `OOM` is the
allocation size the failing request asked for — the number is what identifies the *site*, and only
`4096` is the one-page failure at the disclosure encoder that the chain needs. A normal session means
the response carried a 32-hex `Set-Cookie`; `no cookie` means input parsing was abandoned before a
session existed.

| knob | value | 8.4.26 | 8.4.27 |
|---|---|---|---|
| `--big` (body parts) | 70 | 200, no OOM, cookie | 200, no OOM, cookie |
| | 76 | 500, **OOM 12288** (session decode), cookie | 200, no OOM, cookie |
| | **78** | 500, **OOM 4096 — arms**, no cookie | 200, no OOM, cookie |
| | 90 | 500, OOM 8192 (different site), no cookie | 200, no OOM, cookie |
| | 125 | 500, OOM 8192, no cookie | 200, no OOM, cookie |
| | 500 | 500, OOM 8192, no cookie | 200, no OOM, **no cookie** (parts limit) |
| `--depth` (nesting) | 16 | 200, no OOM, cookie | 200, no OOM, cookie |
| | 48 | 200, no OOM, cookie | 200, no OOM, cookie |
| | 62 | 200, no OOM, cookie | 200, no OOM, cookie |
| | **64** | 500, **OOM 4096 — arms**, no cookie | 200, no OOM, cookie |
| `--parts` (nested fields) | 200 | 200, no OOM, cookie | 200, no OOM, cookie |
| | 600 | 200, no OOM, cookie | 200, no OOM, cookie |
| | **860** | 500, **OOM 4096 — arms**, no cookie | 200, no OOM, cookie |
| | 950 | 500, OOM 20480 (different site), no cookie | 200, no OOM, **no cookie** (parts limit) |
| | 2000 | 500, OOM 20480, no cookie | 200, no OOM, **no cookie** (parts limit) |
| `--big-len` (value size) | 16 000 | 500, OOM 16384 (the value itself), no cookie | 200, no OOM, cookie |
| | 64 000 | 500, OOM 65536 (the value itself), no cookie | 200, no OOM, cookie |
| `memory_limit` | 8M, 16M, 32M | 500, OOM 20480 | 500, OOM 2097152 |
| | 64M | 500, OOM 20480 | 200, no OOM, cookie |
| | **128M** | 500, **OOM 4096 — arms**, no cookie | 200, no OOM, cookie |
| | 192M, 256M, 384M | 200, no OOM, cookie | 200, no OOM, cookie |
| `max_input_vars` / `max_multipart_body_parts` | raised to 20 000 (non-default) | 500, OOM 8192 at 4000 parts | 500, OOM **2 MiB** at 4000 parts |

Three things to read out of this:

- **On 8.4.26 the arming is a narrow point, not a range.** The 4096 signature appears only at
  `--big 78`, `--parts 860`, `--depth 64` and `memory_limit` 128M. Neighbouring shapes also fail, but
  at *different* allocation sizes (12288 in the session decode, 8192 or 20480 in the parser, or the
  value allocation itself), and those failures do not leave the state the chain needs. This is why the
  recipe looks over-specified: every number in it is a coordinate.
- **On 8.4.27 no point in the same space produces any allocation failure** while the body is accepted
  at all. The only OOMs it can be pushed into are the 2 MiB chunk allocation (at low memory limits, or
  with the parts limit lifted and 4000 parts), which happens during input parsing.
- **The parts limit shows up as `no cookie`.** Bodies above ~1020 parts are answered `200` with no
  session at all, because request startup abandons input parsing — visible in the table as the only
  cells where 8.4.27 loses the cookie while returning 200.

`PS(id)` stays NULL, so there is nothing for a later request to read through. The chain's entry
condition — a dangling session id left behind by an OOM — is not produced at all.

### How far can a body push 8.4.27? (measured)

A binary "did it OOM" answer is not enough to call the route closed, so the cost of the *real* arming
body was measured. a private PoC stage imports the builder from `a1_arm.py` (so the shapes cannot drift
apart) and sends that exact body to `probe.php`, which reports the bytes PHP allocated when the script
started.

With `max_input_vars` / `max_multipart_body_parts` raised to 80 000 — a **non-default** configuration,
needed only so the body can be large enough to matter:

| build | parts | usage at script entry | headroom to the 128M limit |
|---|---|---|---|
| 8.4.26 | 600 | 89.1 MiB | 38.9 MiB |
| 8.4.26 | 860 | **request dies — this is the arming point** | — |
| 8.4.27 | 860 | 41.7 MiB | 86.3 MiB |
| 8.4.27 | 1500 | 71.5 MiB | 56.5 MiB |
| 8.4.27 | 2500 | 118.1 MiB | 9.9 MiB |
| 8.4.27 | 2650 | 125.0 MiB | 3.0 MiB |
| 8.4.27 | 2700 | **request dies** | — |

Two facts follow from the curve. A nested part costs about **148 KiB on 8.4.26** and about
**48 KiB on 8.4.27**, which is the same ~3× difference the probe showed for plain field bodies: the same
input is three times cheaper to build on the patched build. And the ceiling *is* reachable on 8.4.27 —
at 2650 parts the request is 3 MiB from the limit — but only with input limits no default ships.

### Why the failure still is not the one the chain needs

The chain needs a **one-page (4096) failure at the session encoder**, because that is the allocation
whose failure leaves `PS(id)` dangling: the request has to end with every page of the current chunk
used and the limit reached, so that the next small allocation cannot get its page. Every failure
observed on 8.4.27 is instead `2097152` — a whole-chunk request — which means the request dies *before*
the heap reaches that state.

Trying to close the last 3 MiB with small allocations (1-byte fields, which consume pages gradually
rather than asking for a chunk) does not change it:

```
parts=2650 fillers=8000   HTTP 500  oom=[2097152]
parts=2650 fillers=14000  HTTP 500  oom=[2097152]
parts=2650 fillers=20000  HTTP 500  oom=[2097152]
```

Summary of the search: 4 structural knobs swept over ~17 points each, 18 single-knob variations around
the working shape, a `parts × big-len` grid with raised limits, and a filler-based approach to the
ceiling. **No shape produced a 4096 failure on 8.4.27, and after every failure `PS(id)` was NULL and the
next request got an ordinary 32-character session cookie.**

### What is left to try, and how

- **A build with symbols.** The official `-fpm`/`-apache` 8.4.27 images are stripped, so `PS(id)` can only
  be inferred from behaviour. A debug or ASAN 8.4.27 would show whether the session encoder is reached
  at all before the request dies — the one question this measurement cannot answer.
- **A different amplifier with small allocation sizes.** The current one is capped by depth 64 and by the
  new parts limit; a shape whose per-node cost is small enough to fill the last chunk exactly would be
  the way in, if one exists.
- **Other entry points into the session code** (the file-part path that replaces `PS(id)` and resets the
  session status) — untested on 8.4.27.

### Where the encoder sits relative to the failure (measured with breakpoints)

The question above was narrowed with breakpoints instead of a build, because the relevant functions are
exported. the private gdb probe arms `php_url_encode` and `php_session_initialize` on the worker and logs every
hit.

**8.4.26, the working arming body — control:**

```
[reach] php_session_initialize hit
[reach] php_session_reset_id hit
[reach] php_url_encode hit
```

**8.4.27, the same body (200 OK, nothing to leak):**

```
[reach] php_session_initialize hit
[reach] php_session_reset_id hit
[reach] php_url_encode hit
```

So the fix does **not** remove the path: the encoder runs on the patched build exactly as it does on the
vulnerable one. That kills the "the code is gone" explanation and leaves only the timing.

**8.4.27, the body that reaches the ceiling (parts=2700, dies with `2097152`):**

```
encoder hits: (none)
PS(id) before: 0x0     PS(id) after: 0x0
```

The parser dies **before** the session code runs, on a whole-chunk allocation, so nothing is left behind.
That is the precise gap the chain cannot cross on this build: not "the encoder is unreachable", but "the
request never gets there with the heap in the right state".

The file-part entry point was checked the same way — a body with a real file part plus the progress field
reaches `php_session_initialize → php_session_reset_id → php_url_encode` on 8.4.27 as well, so that route
is still open in principle and is the remaining candidate.

Two smaller results from the same round:

- **Framing does not matter.** Sending the body with `Content-Length` instead of chunked encoding
  (`a1_arm.py --framing content-length`), in case the de-chunk buffer was the 2 MiB allocation, changes
  nothing: still `2097152` at parts=2700.
- **Filling the last 3 MiB with small allocations does not help either** — the failure stays at chunk
  granularity, so the request keeps dying before the encoder.

### Verdict on "is 8.4.27 still exploitable?"- **On the default configuration: no evidence of reachability, and the known route is closed.** Both
  arming steps fail, no disclosure window exists, and the execution stage is inert even with the
  build's true addresses. The amplification that the route depends on is capped by the new parts limit
  while the memory cost per node dropped, so the heap cannot be driven to the state the chain needs.
- **In principle, not proven impossible.** The parser surface is unchanged and the session code paths
  are still there; what changed is the memory profile and the input limits. A different amplifier could
  in theory replace the one we used — but the entry condition (a dangling `PS(id)`) has to be produced
  first, and every OOM shape reachable on this build leaves the state clean. Re-deriving that geometry
  is a research project of the same order as days 4–5 of this work, with a real chance of ending in
  "no".
- **What would change the picture:** an OOM in 8.4.27 that happens *inside* the session/rfc1867 code
  rather than during input parsing. The 2 MiB failures happen in `php_request_startup`, before the
  session has anything worth leaving behind.

## What this proves, and what it does not

**Proved:** on this 8.4.27 build, with the exact bodies that work on 8.4.26, the chain cannot get past
either arming step; no stale session state is left; the disclosure window does not exist; and the
execution stage is harmless even when handed the target's true addresses.

**Why** the two builds differ was established afterwards, from a from-source build with symbols:
the cause is not in `ext/session` at all, it is in how this build charges allocations against
`memory_limit`. See the next section.

**Not proved either:** anything about other patch levels, other SAPIs, or x86_64. This is one negative
control on one build.

## Why the arming shapes do not transfer (measured)

The first question was whether the vulnerable code is even reachable. It is: with breakpoints on
`php_url_encode` and `php_session_initialize` (the private gdb probe), both builds reach

```
php_request_startup -> php_hash_environment -> php_auto_globals_create_session
  -> php_session_initialize -> php_session_reset_id -> php_url_encode
```

for the arming body and for a body that carries one file part. The difference is *when* memory runs out
relative to that call. On 8.4.26 the request dies **inside** the encoder; on 8.4.27 it dies before the
encoder is ever entered, so `PS(id)` is still `0x0` afterwards.

To find out what fails, the 8.4.27 FPM build was rebuilt from source with `-O0 -g` (the private debug build in the
lab) and the OOM traced with `oom_trace.gdb`. Every failure at the ceiling shape has the same stack:

```
_zend_new_array_0
  _emalloc_56                      <- a 56-byte allocation
    zend_mm_alloc_small_slow       <- small bin 6 needs a fresh page
      zend_mm_alloc_pages          <- pages_count=1
        zend_mm_check_limit        <- add_size=2097152
          zend_mm_delayed_error  "Allowed memory size ... exhausted"
```

So a **56-byte** request is refused, and the number in the message is `2097152` because this build
charges the *whole 2 MiB chunk* against the limit when the current chunk cannot satisfy the page. On
8.4.26 the same check is charged `4096`: a page is still available in the chunk, so only the page is
counted, and the message reads `4096`. Same code path, different accounting granularity &#8212; and the
arming step depends on which allocation is the one that fails.

Three ways of reaching the encoder's allocation were measured, all with the 8.4.27 debug build:

| Shape | Body | Limit | Result |
|---|---|---|---|
| nested parts, tuned limit | 86 levels, 2650 parts | 134 217 728 | `200 OK`, peak 125.0 MiB |
| nested parts, tuned limit | same | 133 000 000 &#8230; 131 080 000 | `502`, always `2097152` |
| huge-value fill | 42 &#215; 3 000 000 | 134 217 728 | `200 OK`, peak 127.6 MiB |
| huge-value fill, tight | same | 133 900 000 | `200 OK` |
| huge-value fill, tighter | same | 133 850 000 | `200 OK` |
| huge-value fill, tighter | same | 133 820 000 and below | `502`, `3014656` (the value's own allocation) |
| huge-value fill, last value tuned | 41 &#215; 3 000 000 + 3 410 000 &#8230; 3 394 000 | 134 217 728 | `502`, ~`3407872` (the tuned value itself) |

The failure moves when the limit moves, so the limit is the right lever &#8212; but it always lands on a
*value* allocation or on a *chunk* charge, never on the small allocation the encoder makes. With huge
values the current chunk stays empty, so the encoder's page request is granted; with nested parts the
chunk fills up, so the encoder is never reached. Transport framing makes no difference:
`--framing chunked` and `--framing content-length` produce identical results.

### The fine scan

The last idea worth testing was to drive the failure precisely onto the encoder by tuning the limit at
byte granularity around the exact usage. The instrument for this is a private PoC stage, which sends the
same body at `probe.php` and reports `memory_get_usage()` / `memory_get_peak_usage()` at script entry:

```
42 multipart values x 3 000 000 B  ->  peak = 130 024 328 B, headroom 6.9 MiB
41 multipart values x 3 000 000 B  ->  peak = 127 009 616 B, headroom 9.7 MiB
```

(the difference between the two is 3 014 712 B, one value plus its overhead &#8212; the shape is linear and
the measurement is exact)

Limits were then set around that peak on the debug build and the failing allocation size recorded:

| limit | failing allocation |
|---|---|
| 130 032 520 (peak + 8 KiB) | `3014656` |
| 130 024 328 (peak) | `3014656` |
| 130 020 232 (peak &#8722; 4 KiB) | `3014656` |
| 130 500 000 | `3014656` |
| 131 000 000 | `2113536` (a 2 MiB chunk **plus** one 4 KiB page) |
| 132 000 000 | `2113536` |
| 133 000 000 | `3014656` |
| 133 850 000 | succeeds |
| 133 820 000 | `3014656` |

Two things follow. First, a 3 MB value needs *more* than 8 KiB of apparent headroom, because the parser
holds the part buffer and its copy at the same time &#8212; the peak measured at script entry is not the
momentary peak during parsing. Second, and more important, the charging granularity is still 2 MiB:
the one new number here, `2113536`, is a chunk allocation plus a page, not a page. **A page-sized
(`4096`) failure was not produced in any of the roughly fifty runs of this experiment.**

That is the state of this line of work: not a proof that 8.4.27 is unarmable, but a measurement of what
it would take to arm it &#8212; and of why the obvious levers do not get there. On 8.4.26 the arming step
needs a `4096` refusal while a page is still free in the chunk; on 8.4.27 every refusal observed is
charged in 2 MiB units, so the request dies during input parsing and the session state is left clean.

## The same tuning on the stock build

The measurement above was made on a from-source FPM build, so it was repeated on the image the chain
actually has to be stopped on, `php:8.4.27-apache` with the ini the image ships.

The first result is a correction. On stock `php.ini-production`, `post_max_size` is **8M**, and a
126 MB multipart body is therefore not parsed at all:

```
42 values x 3 000 000 B, stock ini   ->  post_nodes = 5,    memory_usage = 16.4 MiB
42 values x 3 000 000 B, post_max_size = 256M  ->  post_nodes = 45,  memory_peak = 256 613 176 B
```

So this fill shape is *inert* unless `post_max_size` is raised, and it costs **256.6 MB** on stock
apache against 130.0 MB on the debug FPM build for the identical body. The difference is the parser
holding the part buffer and the value copy at the same time, which is also why "peak + 8 KiB" was never
enough room: the peak visible at script entry is not the momentary peak during parsing.

With `post_max_size = 256M` and `memory_limit` swept from 256M to 268 435 456:

| limit | result | failing allocation |
|---|---|---|
| 256 000 000 | `500` | `3014656` |
| 258 000 000 | `500` | `3014656` |
| 260 000 000 | `500` | `3014656` |
| 262 000 000 | `200 OK` | &#8212; |
| 264 000 000 | `200 OK` | &#8212; |
| 266 000 000 | `200 OK` | &#8212; |
| 268 435 456 | `200 OK` | &#8212; |

The cliff was then bisected to a **3906-byte** window (fails at 260 437 500, succeeds at 260 441 406) and
probed in 512-byte steps from the cliff upwards:

| limit above the cliff | result |
|---|---|
| +0, +512, +1024, +1536, +2048, +2560, +3072, +3584, +4096 | `200 OK`, no OOM in the container log |

If a page-sized refusal were reachable, it would appear exactly there: immediately above the point where
the last value stops fitting, the very next page request is the one that has to be refused. It does not
appear. On the stock build a 126 MB body either dies on the value that is being copied in, or it
succeeds completely.

One methodological note on that table: a container log keeps earlier OOM lines, so the *request status*
is the authority here, not the last `tried to allocate` grep. The bisection runs are the ones that
produced the `3014656` lines; the fine-scan rows all answered `200 OK`.

**Conclusion for the stock build:** the byte-level limit tuning that would be needed to reproduce the
8.4.26 arming step does not reach a page-granular failure here either. The route stays closed, and the
reason is visible in the numbers: the failure granularity is a value copy (3 MB) on one side and a
2 MiB chunk on the other.

## Reproduce

The paired experiment is driven by the private lab tooling: one script runs both arms (positive
8.4.26, negative 8.4.27) with identical bodies and arguments, and a second regenerates the shape
comparison table. Neither is published. What *is* published is enough to rebuild the experiment: the
protocol above, the body shapes, the sweep points, and every number the two runs produced.

The script refuses to start if the host port it needs is already published by another container, since
a collision would silently point the "negative" arm at the positive target. Ports default to 18120 and
18121 and can be overridden with `POS_PORT` / `NEG_PORT`; the sweep points come from `SWEEP_LIMITS`
(default `96M 128M 192M 256M 384M`).
