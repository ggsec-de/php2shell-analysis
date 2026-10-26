# CVE-2026-109618 / CVE-2026-109619 — Reproduction History

A record of the research behind this repository: five days, 56 commits, and 23 documented iterations.

This document is written so that someone repeating the work knows which roads are closed and why, not only which one is open.

> **Note:** the CVE identifiers used in this repository are currently referenced as
> `CVE-2026-109618` and `CVE-2026-109619`. The final mapping between identifiers,
> failure classes, and vendor advisories should be verified before publication.

---

## Purpose

This repository documents the research process behind a proof-of-concept reproduction involving PHP `session.upload_progress` handling.

The work separates two distinct failure classes that are easy to conflate:

1. an **application-level unsafe include chain**, where attacker-controlled data written into a session file can later be included by an application;
2. an **engine-level stale-pointer / memory-disclosure condition**, where an allocation failure leaves a dangling session identifier in allocator memory that can later be read.

This history records how the final reproduction was reached, including failed approaches, measurement gates, instrumentation, and verification.

---

## Safety and Scope

This research was performed in an isolated laboratory environment using local, controlled test images.

The purpose of this repository is defensive and educational:

- to understand the failure mode;
- to document which assumptions were false;
- to verify patched behaviour;
- to support review and detection.

This material must not be used against systems without explicit authorization.

---

## CVE / Issue Mapping

| Working identifier | Failure class | Short description |
| --- | --- | --- |
| `CVE-2026-109618` | Application-level unsafe include chain | Session file contents reach an application-side `include()` that is not fully controlled |
| `CVE-2026-109619` | Engine-level stale-pointer / memory disclosure | An allocation failure leaves a dangling session identifier in allocator memory that a later request can read |

Both identifiers are working labels, used consistently across this document and the linked notes.

---

## Summary

| Item | Value |
| --- | --- |
| Research window | 5 days, 2026-10-21 → 2026-10-25 |
| Follow-up | 1 further day, 2026-10-26: mechanism, PHP-FPM, version matrix (Day 6 below) |
| Commits | 56 |
| Documented iterations | 23, some grouped in the private research log |
| Requests in final chain | 4: `A1`, `B1`, `A2`, `B2` |
| Initial reproducible leak window | 8,192 bytes |
| Final usable disclosure window | 819,200 bytes |
| Module pointers in final window | 876 copies of `zval_ptr_dtor` |
| Positive verification | 3/3 single-worker baseline, 3/3 150-worker MPM configuration |
| Negative control | 8.4.27 paired control: no arming, no leak, no execution |
| Second positive arm | `php:8.5.11-apache` reproduces the arming signature (`4096`) with the same body, stock ini |
| Builds that do not arm | 8.4.27, 8.5.12, 8.3.36, 8.2.35 &#8212; no page-sized refusal at any tested limit |
| SAPI ruled out | PHP-FPM: the arming fires identically, but the worker is replaced on bailout, so no state survives |

---

## Background

`session.upload_progress` failed in two different ways during this research. The two must not be conflated.

### Application-Level Failure

The upload-progress handler can write an unsanitised upload filename into the session file. In addition, the request body can supply the session identifier through the multipart path, even where a stock configuration such as `session.use_only_cookies=1` would suggest otherwise.

This behaviour is documented in:

- `docs/SID_ADOPTION.md`

An application that later includes a path it does not fully control may be driven to include a file whose content was shaped by the attacker. This is code execution without requiring memory corruption.

This failure class depends on the application-side `include()` behaviour.

### Engine-Level Failure

The same handler can also be abused so that a memory-allocation failure leaves a session identifier dangling in a chunk that the allocator later recycles. A later request can then read through that stale state.

This results in memory disclosure and can provide information useful for bypassing ASLR. This repository reproduces that engine-level condition.

The first day of research was spent on the application-level chain because it is externally visible and affects how the issue is described. The remaining four days focused on the engine-level failure.

---

## Test Environment

The reproduction was performed against controlled lab images and local HTTP endpoints.

| Component | Notes |
| --- | --- |
| PHP versions | 8.4.26 for positive reproduction; 8.4.27 for negative control; 8.5.11 as a second positive arm; 8.5.12, 8.3.36 and 8.2.35 as further negatives |
| SAPIs | `mod_php`; PHP-FPM (nginx + `php:8.4.26-fpm`) measured and ruled out; WordPress lab instance where noted |
| Apache MPM | Single-worker baseline and 150-worker configuration |
| Memory target | 128 MB baseline, with `memory_limit` sweeps down to 96M and up to 268 435 456 |
| Configuration | Stock PHP configuration unless stated otherwise; `post_max_size` raised only where a body would otherwise not be parsed, and marked in the relevant note |
| Error visibility | `display_errors=Off`, requiring log and debugger observation |
| Instrumentation | gdb, custom harnesses, container logs, allocator-state inspection, a from-source debug build |

“Stock ini” in this document means the default PHP configuration shipped with the lab image.

---

## Request Naming

The final chain uses four named request roles.

| Name | Role |
| --- | --- |
| `A1` | Arming request used to leave stale session state |
| `B1` | Disclosure request used to read the resulting memory window |
| `A2` | Cheaper allocation-failure variant used during execution testing |
| `B2` | Request carrying crafted structures used to verify execution |

---

## Research Log

### Day 1 — Application-Level Chain, Measured

The poisoned-session-file chain was reproduced end to end in our own lab.

A multipart field carrying a crafted upload filename landed in the session file. An application-side `include()` of that file then turned it into command execution.

Two assumptions were refuted by measurement:

#### `session.upload_progress.cleanup=On` Does Not Mitigate This

The setting removes the progress entry after the upload completes, but the filename has already been written into the session data.

Stock PHP ships with `cleanup=On`.

A mitigation that only helps when the attacker loses a race is not a mitigation.

#### Patching PHP Does Not Stop the Application-Side Chain

The remaining condition is on the application side: an unsafe `include()`. The engine patch does not remove that application behaviour.

This is why the two failures have to be reported separately. A version-only statement about the engine bug says nothing about an application that was already including session files.

---

### Day 2 — The Target Becomes a Lab, Not a Claim

From day two onward, every claim in the log has an instrument behind it.

We built:

- our own harness for the arming stage, `A`;
- a gdb observer reading engine state directly instead of inferring from response text.

The operating principle for the whole week was:

> If a measurement and the plan disagree, the plan is wrong.

---

### Day 3 — Outside Material, and the Parts That Matter

A published recipe for the memory-corruption chain appeared. Instead of adopting it directly, we documented it as an oracle and re-measured every number ourselves.

Three lab targets were built to ask different questions:

1. `mod_php`
2. PHP-FPM
3. WordPress on PHP 8.4.26

The WordPress run answered a real objection:

> “Our application does not use sessions at all.”

That is not a defence against the engine-level failure, because the vulnerable code is in the engine, not in the application.

Day three also produced the first argument-shaping work for the forged destructor. The first attempt executed with a garbage argument because the target function reads its parameter from hand-built execution data. Getting the call to carry a real string was a separate problem from getting it called.

A first defence map was written as “what would actually have to be true on the host”. Two claims were measured instead of merely listed:

- `cleanup=On` is not a mitigation;
- “we do not use sessions” is not a defence.

---

### Day 4 — Gates Before Exploit Work

Two prerequisites were treated as gates, not as things to discover later.

#### Gate 1 — Session-Identifier Adoption

Whether a multipart field can set the session identifier under a stock configuration decides the geometry of everything that follows.

Measured result: it can, via the upload-progress path.

The resulting matrix was consistent across 24/24 test cells.

#### Gate 2 — Arming Stability

Our own `A1` request armed a stock 128 MB target 3/3.

The allocation failure landed after session decode, and the stale `PS(id)` survived into the next request.

Then the fix side was examined, which also feeds the `docs/` directory:

- the upstream patch was read from PHP source and measured on 8.4.27 and 8.5.12;
- an extension probe asked whether the core fix protects extension-owned state; the answer was: not fully;
- the engine-held handle `BG(unserialize).data` was located and measured, and its consumer was traced;
- this corrected two earlier claims of ours.

Several grooming and `objdump` suggestions from outside material were tested rather than adopted. None reproduced in our environment.

---

### Day 5 — Disclosure, Execution, Negative Control

The final day was the one that mattered. It proceeded in four phases.

---

## Phase 1 — `B1`: 23 Iterations to a Usable Window

### Iterations 1–5

The forge was not landing, and the post-hoc scan could not explain why.

Iteration 3 was retracted in iteration 4.

### Iterations 6–10

The allocator was measured directly.

We decoded:

- `free_pages`
- `free_map`
- `page_info[]` per bin

This is where the model stopped being a guess.

### Iterations 11–13

We aimed at the freed slot in three different ways. Each missed for a reason that became known later.

### Iterations 14–18

Two levers were identified:

1. binary-safe channels read out of `ext/session/session.c`;
2. placement measured per chunk.

Iteration 17 killed a whole family of approaches:

> The stale pointer sits in a 48-byte small-bin page, so no large allocation can ever cover it.

Iteration 18 proved that the encode path was reachable inside the body phase and explained why the forgery still could not work there.

### Iteration 19

The leak reproduced with our own tools.

Initial reproducible result:

- 8,192 bytes disclosed;
- 62 chunk pointers visible;
- reproduced 3/3 in fresh containers.

---

## Phase 2 — Module Base

### Iteration 20

The disclosed window initially held chunk addresses only.

The only `libphp` pointers in the chunk were interned keys from `progress->data`, and they sat one page below the stale identifier. The window reads forward, so those pointers were unreachable.

### Iteration 21

Two paths were tested.

#### Path 1 — Move `PS(id)` Lower

Closed.

Only one arming shape reaches the encode site, and the page position is fixed.

#### Path 2 — Allocate Hash Tables After `PS(id)`

Implemented and measured.

The allocations landed in other chunks.

### Iteration 22

The missing ingredient was geometry taken from the oracle, then re-measured locally.

A value sized to fill the chunk leaves a small, computable number of free pages at its tail. From those pages, nested POST arrays were carved out as real `zend_array` structures whose `pDestructor` is `zval_ptr_dtor`. The page index, the free-page count and the array count are build-specific and stay in the private lab; what matters here is that the technique is a size computation, not a guess.

Result:

- 876 copies in the disclosed window;
- a cookie-derived `libphp` base equal to gdb’s value in 3/3 fresh containers.

---

## Phase 3 — Execution

### Iteration 23

The execution test combined:

- `A2`: cheap allocation failure inside the multipart parser;
- `B2`: crafted structures used to verify control flow.

The first attempt succeeded.

Verification:

- 3/3 on the single-worker baseline;
- 3/3 on the 150-worker MPM configuration;
- one run with an arbitrary command line.

---

## Phase 4 — Negative Control

A working exploit without a control is only half a result.

PHP 8.4.27 was the obvious negative arm. However, simply observing “no execution” is not enough. The chain could fail because:

- the build’s memory use shifted;
- layout constants moved;
- the lab environment was broken.

Therefore the control was defined first.

Four signals were required to agree on a demonstrably healthy target. The control was then run as a paired experiment:

- same host;
- same scripts;
- same arguments;
- only the image differs;
- the negative arm was handed the true addresses of its own build.

### Result on 8.4.27

The negative arm failed at the first step.

On 8.4.27, the arming request returned `200 OK` with an ordinary 32-character session cookie and no allocation failure at all.

This held across:

- every tested `--big` value from 70 to 90;
- every tested `memory_limit` from 8M to 128M.

Afterwards, `PS(id)` was NULL.

Two sweeps were needed to show this was not merely shifted geometry:

- the `4096` signature at the encode site never appeared on 8.4.27 at any tested limit;
- below 64M, failure occurred on a completely different 2 MiB allocation.

The cheap-OOM arming variant behaved the same way.

Execution with the build’s own addresses produced:

- no file;
- no crash;
- a normal 200 response.

During the same paired run, the positive arm produced `uid=33(www-data)` and a segfaulted worker.

Conclusion:

> On 8.4.27, nothing arms, nothing leaks, and nothing executes through the known route.

Full protocol and numbers are in:

- `docs/NEGATIVE_CONTROL_8.4.27.md`

---

## Day 6 — After the Log Was Written: Why 8.4.27 Differs, and Which Builds Still Arm

The negative control said *what* happens on 8.4.27. It did not say *why*, and a negative without a
mechanism is weak evidence. The sixth day was spent turning it into a mechanism, and then using that
mechanism as an instrument on other builds.

#### The `4096` Signature

On 8.4.26 the arming step needs one very specific refusal: a page-sized allocation (4096 bytes) rejected
*inside the session encoder*, while a page is still free in the current chunk. That is the state that
leaves `PS(id)` dangling into the next request.

This makes the arming testable from the container log alone. The signature is a number:

```
tried to allocate 4096
```

No addresses, no calibration, no debugger. That is what turned it into an instrument.

#### Why 8.4.27 Does Not Produce It

The image was rebuilt from source with symbols (`-O0 -g`) so the failing allocation could be named
instead of inferred. Every failure at the ceiling shape has the same stack:

```
_zend_new_array_0
  _emalloc_56                    <- a 56-byte request
    zend_mm_alloc_small_slow     <- small bin 6 needs a fresh page
      zend_mm_alloc_pages        <- pages_count=1
        zend_mm_check_limit      <- add_size=2097152
```

A 56-byte request is refused, and the number in the message is 2 MiB because this build charges the
*whole chunk* against the limit when the current chunk cannot satisfy the page. On 8.4.26 the same check
is charged 4096, because a page is available and only the page is counted.

Same code path, different accounting granularity. The arming step depends entirely on *which* allocation
is the one that fails.

The encoder is still reachable on 8.4.27: breakpoints on `php_url_encode` and `php_session_initialize`
fire for the arming body and for a body carrying a file part. What changes is *when* memory runs out
relative to that call. On 8.4.26 it runs out inside the encoder; on 8.4.27 the request dies during input
parsing, and `PS(id)` is still NULL afterwards.

#### The Limit as a Lever, Tuned to the Byte

If the accounting is the obstacle, the limit is the lever. The exact cost of a body was measured by
sending it at `probe.php` and reading `memory_get_usage()` / `memory_get_peak_usage()` at script entry:

```
42 multipart values x 3 000 000 B  ->  peak = 130 024 328 B  (debug build)
42 multipart values x 3 000 000 B  ->  peak = 256 613 176 B  (stock apache)
```

The factor of two is the parser holding the part buffer and the value copy at the same time, and it
explains an earlier surprise: tuning the limit to "peak plus a few kilobytes" was never enough, because
the peak visible at script entry is not the momentary peak during parsing.

Limits were then set byte-precisely around the measured peak and the failing allocation recorded. Every
result stayed at megabyte granularity: the value being copied (`3014656`), a chunk (`2097152`), or one
new observation, a chunk *plus* a page (`2113536`). Across roughly fifty runs of this experiment, a bare
`4096` never appeared.

The same tuning on the stock image found the cliff by bisection to a **3906-byte window** (fails at
260 437 500, succeeds at 260 441 406) and then probed it in 512-byte steps. Nine probes above the cliff,
all `200 OK`. If a page-sized refusal were reachable by tuning, that is where it had to appear.

#### A Trap That Produces False Negatives

Stock `php.ini-production` sets `post_max_size = 8M`, and a 234 MB body is then not parsed at all:

| framing | `post_max_size` | `post_nodes` |
| --- | --- | --- |
| chunked | 8M | 5 |
| content-length | 8M | 1 |
| chunked | 256M | 45 |

Two corrections came out of this. First, **chunked framing does not bypass the `post_max_size` gate** on
these builds; it is parsed slightly further and then abandoned. Second, every "no OOM" result has to be
read together with whether the body was parsed at all, or the table says nothing.

#### PHP-FPM: Measured, Then Closed

| Question | Result |
| --- | --- |
| Does the arming fire under FPM? | Yes, identically: `4096`, same stack |
| Does the state survive into the next request? | No: `child N exited with code 70` and a fresh child starts; `PS(id)` is never stale |
| Does the body even reach PHP behind nginx? | Not by default: `client_max_body_size` is 1 MiB, so the arming body gets a `413` first |

The reason is structural, not a tuning problem. In prefork, an out-of-memory bailout ends the request and
the child keeps its heap; in FPM the worker is terminated and replaced, so the chunk that held the
dangling pointer is discarded with it. PHP-FPM is therefore *not* an open question for this chain: it is
a closed negative.

#### A Second Positive Arm and Four More Negatives

"No OOM" is only meaningful if the instrument is known to see the signature when it exists. A build
expected to be vulnerable was therefore tested as a positive control on a different branch:

| Image | Same body as the chain (`parts=860`) |
| --- | --- |
| `php:8.4.26-apache` | **`4096`**, full chain 3/3 |
| **`php:8.5.11-apache`** | **`4096`**, at stock ini and with `post_max_size` raised |
| `php:8.4.27-apache` | no OOM |
| `php:8.5.12-apache` | no OOM |
| `php:8.3.36-apache` | no OOM |
| `php:8.2.35-apache` | no OOM |

The positive arm is the important row: the instrument detects the signature on 8.5.11 with no tuning at
all, so the four negatives are measurements, not artefacts.

Where those four do exhaust memory, the refusal is a value copy, a chunk or a chunk plus a page. Never a
page. The fix is present in all of them for this chain class.

Full tables and method:

- `docs/VERSION_MATRIX.md`

---

## Verification Summary

| Test arm | Arming | Disclosure | Module base | Execution |
| --- | ---: | ---: | ---: | ---: |
| Positive: 8.4.26 | Yes | Yes | Yes | Yes |
| Negative: 8.4.27 | No | No | No | No |
| Positive signature: 8.5.11 | Yes | not attempted here | n/a | n/a |
| Negative signature: 8.5.12, 8.3.36, 8.2.35 | No | n/a | n/a | n/a |
| PHP-FPM, 8.4.26 | Yes | No | n/a | n/a |

Positive-arm verification:

- 3/3 single-worker baseline;
- 3/3 150-worker MPM configuration;
- 1 arbitrary command run.

Signature-level verification (the `4096` arming test, no addresses required):

- 8.5.11 reproduces the signature with the same body at stock ini;
- 8.5.12, 8.3.36, 8.2.35 and 8.4.27 do not, at any tested limit;
- the full chain on 8.5.11 was verified separately with a different PoC in this lab (3/3).

Negative-control verification:

- paired experiment;
- same host and scripts;
- negative arm received true addresses for its own build;
- sweeps across body size and memory limit showed no evidence of the same condition.

PHP-FPM verification:

- the arming fires with the same `4096` failure;
- the worker is replaced (`exit code 70`) and a fresh child serves the next request;
- the body must also pass nginx's `client_max_body_size`, which is 1 MiB by default.

---

## Where the Problems Actually Were

Ranked by how much time they cost, not by how interesting they are.

### 1. `display_errors=Off` Hides the Evidence

The arming allocation failure does not appear in the HTTP response.

Every arming question had to be answered from container logs or breakpoints:

- did it OOM?
- at which allocation size?
- in which phase?

Only one shape armed reliably. Everything else failed silently in a way that looked similar.

---

### 2. A Wrong Model of the Value Layout, with Two Errors Cancelling

A large multipart value becomes a `zend_string` whose data starts at an offset into its page-aligned run.

Our tool documented one constant incorrectly while also treating another offset incorrectly. The two mistakes cancelled, so placement appeared to work until a later change made one of them visible.

A second trap: the value had to end at the fake header. Otherwise the claimed length read our own filler, producing a plausible-looking leak of zeros.

Both traps are recorded in:

- the private research log

They are recorded as traps, not merely history.

---

### 3. Post-Hoc Scans Cannot Answer “Did My Forge Land?”

Scanning memory after the request measures the allocator’s next state, not the request itself.

The fix was to go inside the request:

- a per-part random marker;
- breakpoints on the encode function keyed on the forged length argument.

That single change ended a long series of inconclusive experiments.

---

### 4. The One-Page Miss

The chunk did contain `libphp` pointers — exactly two interned keys from `progress->data`. They were allocated just before the session identifier.

But they were one page below the disclosed window, and the window only read forward.

Intuition says:

> The pointer must be nearby.

Measurement says:

> Nearby is not enough.

---

### 5. The `urlencode` Trap

The session encoder is `php_url_encode`, which uses `urlencode` semantics.

Byte `0x20` becomes `+`.

One relevant `libphp` symbol had a low byte of `0x20`, so every leaked copy arrived with `+` encoded into it. The leak remained lossless, but only if the decoder knew how to interpret the encoding.

For one round, the leak looked pointer-free while the chunk demonstrably held hundreds of pointers.

---

### 6. Constants That Are Not Constants

A simple `libphp - heap` delta looked like an obvious shortcut for deriving the base.

Measured over four fresh containers, it moved in `0x20000` steps:

- −6592 pages
- −6464 pages
- −6752 pages
- −6624 pages

A delta guess would have required multiple attempts, each crashing a worker.

The disclosed window supplies the base exactly in one request, with a large pointer margin.

The negative result is preserved in:

- the private gdb probe

---

### 7. Beliefs That Had to Be Broken by Measurement

Several assumptions had to be discarded after measurement:

- `session.upload_progress.cleanup=On` is not a mitigation;
- “we do not use sessions” is not a defence against the engine-level condition;
- patching PHP does not uniformly stop every chain, because the engine bug and the application-level unsafe include fail independently;
- outside grooming and offset suggestions had to be tested, not adopted; none reproduced in our lab.

Conflating the two failure classes produces a wrong severity assessment.

---

### 8. Lab Plumbing Cost More Than It Should

Several uninteresting but time-consuming issues appeared:

- relative paths in a runner mounting an empty docroot, causing 404 responses;
- a non-world-readable docroot, causing 403 responses;
- shell quoting problems;
- PowerShell rewriting newline sequences inside Python files;
- a worker dying during arming in one configuration but surviving in another.

None of these are interesting by themselves, but each cost hours. Each is now documented in `docs/`.

---

### 9. Our Own Reporting Bug, at the Very End

The runner filtered proof output through a keyword list. A multi-line command appeared to have run only its last line.

The chain was correct; the display was not.

Lesson:

> When output looks short, check the artefact, not the screen.

---

### 10. Container Logs Belong to the Container, Not to the Request

Reading the last `tried to allocate` line from `docker logs` answers "what failed recently", not "what
failed in *this* request". A `200 OK` row in one sweep carried the previous run's `3014656`, and a
non-monotonic result appeared that would have been reported as a finding.

The fix is to slice the log to the lines produced by that one request, and to treat the *request status*
as the authority.

This is the same class of error as item 9: reading the instrument's stream instead of the event.

---

### 11. Two Configuration Traps That Look Like Physics

`post_max_size = 8M` means a 234 MB multipart body is never parsed, and an unparsed body is
indistinguishable from "this build does not allocate memory for that shape". A comparison table built on
such bodies says nothing at all.

Related, and worse: chunked framing was assumed to bypass that gate, because it carries no
`Content-Length`. Measured, it does not. It is parsed slightly further and then abandoned.

Both traps are now stated before the tables they would otherwise invalidate.

---

## What Made It Work

### Gates Before Exploit Work

Session-identifier adoption and `A1` arming were closed first. Later failures could therefore not be caused by those prerequisites.

### Instruments That Read Engine State

We relied on gdb and allocator inspection rather than response-text inference.

Observed state included:

- `ps_globals`
- `alloc_globals`
- `page_info[]`
- `/proc/<pid>/maps`

### Failure Is a Result

The log contains as many closed paths as open ones. Each closed path explains why the next attempt was not a guess.

### An Oracle, Used as an Oracle

Published outside material suggested what to try. Every number used in this repository was measured on our own image.

That is why the offsets and constants in `docs/` are ours, not borrowed.

### Fail Closed

The tooling refuses to guess:

- the address decoder refuses to name a base when the disclosed window has no fingerprint cluster;
- the chain runner stops rather than trying a guessed address.

---

## What Is Still Open

### Automatic Layout Calibration

Some constants are image-specific. Measuring them automatically from debugger output would allow the PoC to survive an image change without a manual step.

Relevant tooling:

- the private gdb probe

### Other SAPIs

Closed for PHP-FPM: the arming fires with the identical `4096` failure, but FPM terminates the worker on
a bailout and starts a fresh one, so the chunk holding the dangling pointer is discarded. Measured, not
assumed.

Not tested: SAPIs that neither replace the worker nor reuse a prefork child. Only prefork-style child
reuse has been shown to carry the state across requests.

Details:

- `docs/FPM.md`

### Patched Builds

The negative control shows the known route is closed on 8.4.27. The *mechanism* is now known too: that
build charges its allocation-limit checks in 2 MiB units, so the page-sized refusal the arming step needs
does not occur. Byte-level limit tuning, including on the stock image, did not reach it.

The same instrument places 8.5.12, 8.3.36 and 8.2.35 on the closed side, and 8.5.11 on the open side.

What remains open is not "does 8.4.27 arm" but "is there any allocation failure, of another size or in
another phase, that leaves the same dangling state". Every failure reachable so far lands in the
multipart parser and leaves state clean.

Details:

- `docs/NEGATIVE_CONTROL_8.4.27.md`
- `docs/VERSION_MATRIX.md`

### A Second Branch in Our Own Tooling

The arming signature is reproducible on 8.5.11 with the same body, so the chain should exist there. It
has been verified end to end with a separate PoC in this lab, but not yet with the tooling in the private PoC directory,
which carries 8.4.26 constants. Recalibrating `zval_ptr_dtor` and `zif_system` for that image is
mechanical, and is the cheapest remaining step that would widen the result.

Relevant tooling, all withheld with the code:

- the address decoder and the state probe.

---

## Defensive Relevance

### Detection Note

The arming request has a distinctive shape:

- five eight-character query parameters;
- a multipart body without an existing session;
- a 500 response;
- a one-page allocation failure visible in logs.

Defenders can look for this combination, especially when followed by session-related or unusual multipart traffic.

### Mitigation Notes

- Patch PHP to a version where the engine-level route is fixed.
- Do not rely on `session.upload_progress.cleanup=On` as a mitigation for the application-level chain.
- Avoid including session files or other server-written state files.
- Treat unexpected allocation failures during multipart parsing as suspicious when paired with session-progress activity.
- Verify both engine-level and application-level exposure separately.

---

## Repository Map

| Path | Purpose |
| --- | --- |
| `lab/` | The prefork target: container runner, single-worker Apache conf, diagnostic docroot |
| `lab-fpm/` | The FPM target: nginx + `php:8.4.26-fpm`, one static worker |
| `docs/` | Measurement records: the negative control, the FPM result, the version matrix, session-id adoption, A1 calibration |
| `HISTORY.md` | This document |
| `LICENSE`, `NOTICE.md` | Licence and provenance |

Documents worth reading first:

- `docs/NEGATIVE_CONTROL_8.4.27.md`
- `docs/VERSION_MATRIX.md`
- `docs/FPM.md`
- `docs/SID_ADOPTION.md`

**Withheld from this repository:** the PoC itself (the four request stages and their helpers), the gdb
probes used to calibrate them, and the two documents that amount to a runbook (the B1 iteration log and
the chain runbook). Together they are the working exploit, so they stay in the private lab. What is
published here is the part that helps a defender decide whether a build is affected, and why.

The measurement instruments are withheld with the code. Their *questions* are not:

- what does a body cost, measured at script entry, for size searches;
- is the encoder reached at all on this build;
- what does a request leave behind in the session state.

---

## Final Note

This history is intentionally written as a research record, not as a success story.

The most important result is not only the final chain, but the set of measurements that distinguish:

- what looked true;
- what was tested;
- what was disproven;
- what still remains open.