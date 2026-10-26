# php2shell — analysis and negative control

What is published here is the **measurement half** of a reproduction of CVE-2026-109618 / CVE-2026-109619:
which PHP builds are affected by this chain class, how the fix actually behaves, and why the chain does not
transfer to PHP-FPM. The working exploit (the four request stages, their calibration probes and the
runbook) is withheld in a private lab — see [NOTICE.md](NOTICE.md) for what that means and why.

The research narrative, including the wrong turns, is in [HISTORY.md](HISTORY.md).

## The two failure classes, kept apart

`session.upload_progress` fails in two ways that are easy to conflate, and conflating them produces a
wrong severity assessment:

| class | what it is | fixed by |
|---|---|---|
| **application-level** | the upload-progress handler writes an unsanitised filename into the session file; an application that later `include()`s session state executes attacker-shaped content | the application, not the engine |
| **engine-level** | an allocation failure inside the session encoder leaves a dangling session identifier; a later request reads through it and discloses memory | a PHP patch |

The engine-level condition is what this repository measures.

## What the measurements say

Three results, each obtained by running the same shapes on the official images with a single Apache child
and reading the failing allocation out of the container log. The arming step needs one very specific
refusal — a **4096-byte page** inside the session encoder — so that number is the signature.

| image | arming signature | reading |
|---|---|---|
| `php:8.4.26-apache` | **`4096`** | affected; full chain verified 3/3 |
| `php:8.5.11-apache` | **`4096`** | affected, with the same body, at stock ini |
| `php:8.4.27-apache` | none | fixed |
| `php:8.5.12-apache` | none | fixed |
| `php:8.3.36-apache` | none | fixed |
| `php:8.2.35-apache` | none | fixed |

Where the fixed builds do exhaust memory, the amount refused is a value copy, a 2 MiB chunk or a chunk
plus a page — never a page. The mechanism is in
[docs/NEGATIVE_CONTROL_8.4.27.md](docs/NEGATIVE_CONTROL_8.4.27.md): that build charges allocation-limit
checks in 2 MiB units, so a 56-byte allocation is refused with `tried to allocate 2097152` and the request
dies during input parsing, before the encoder is reached.

Byte-level tuning of `memory_limit` does not change that — the cliff was bisected to a 3906-byte window on
the stock image and probed in 512-byte steps; every probe above it succeeded, none produced a `4096`.
Detailed tables: [docs/VERSION_MATRIX.md](docs/VERSION_MATRIX.md).

## What does not transfer

**PHP-FPM.** The arming fires there with the identical `4096` failure, but FPM terminates the worker on a
bailout and starts a fresh one, so the chunk holding the dangling pointer is discarded. Behind nginx the
arming body also has to pass `client_max_body_size`, which is 1 MiB by default. Measured, with the compose
file included here: [docs/FPM.md](docs/FPM.md).

## What is in this repository

| Path | Purpose |
|---|---|
| `lab/` | prefork target: container runner, single-worker Apache conf, diagnostic docroot |
| `lab-fpm/` | FPM target: nginx + `php:8.4.26-fpm`, one static worker |
| `docs/NEGATIVE_CONTROL_8.4.27.md` | the paired control, its protocol and every number it produced |
| `docs/VERSION_MATRIX.md` | the `4096` signature across six images |
| `docs/FPM.md` | why the chain is prefork-specific |
| `docs/SID_ADOPTION.md`, `docs/SID_ADOPTION_MATRIX.md` | can a multipart request choose its own session id |
| `docs/A1_CALIBRATION.md` | where the arming allocation failure has to land, and why |
| `HISTORY.md` | five days, 56 commits, 23 iterations — including what was disproved |
| `NOTICE.md` | provenance, what is withheld, licence |

## Running the lab harness

The target side is included, so the claims can be re-derived with your own client.

```bash
docker pull php@sha256:60111400ff77bdc44fa79b4f072afe49a42abba71e0846ad496be4761dc9c11a
bash lab/start_target.sh --single     # one Apache child, stock php.ini-production
curl -s http://127.0.0.1:18110/index.php          # OK
curl -s http://127.0.0.1:18110/probe.php -F a=1   # what PHP built from the body
```

`probe.php` reports `post_nodes`, `post_depth`, `memory_usage`, `memory_peak` and the active limits. It is
the instrument that turns "did it crash" into "how close to the limit was it" — send any multipart body at
it, including a chunked one, and read the numbers.

## Detection

The arming request has a shape: several eight-character query parameters, a multipart body that starts a
session the client then does not use, a `500` response, and a **one-page allocation failure** in the log
followed by session traffic from an address that never authenticated. Treat an allocation failure during
multipart parsing as suspicious when it is paired with upload-progress activity.

## Mitigation

- Patch PHP. The signature is absent on 8.4.27, 8.5.12, 8.3.36 and 8.2.35 in these measurements.
- Do not treat `session.upload_progress.cleanup=On` as a mitigation for the application-level chain.
- Do not `include()` session files or other server-written state.
- Verify engine-level and application-level exposure separately; they fail independently.

## Scope

Lab only. The arming requests exhaust a target's memory limit and crash a worker, so they are visible in
logs and destructive by construction. Nothing here is a licence to point anything at a system you do not
own or have written permission to test.

## Licence

MIT, copyright 2026 **GG ADVANCED IT Security UG**. Full text in [LICENSE](LICENSE).
