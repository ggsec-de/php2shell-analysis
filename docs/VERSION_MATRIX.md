# Which builds still arm (version matrix)

The chain needs one very specific failure: an allocation refused **at the session encoder**, with a page
still free in the current chunk, so that `PS(id)` is left dangling. On 8.4.26 that failure is a
`4096`-byte page request inside `php_url_encode`; it is the arming signature, and it can be looked for
on any build without calibrating addresses. This document records that lookup across the official
`php:*-apache` images.

## Protocol

One stock image per row, no rebuilds, one Apache child
(`lab/apache-single-worker.conf`), `memory_limit = 128M`, everything else from the image's
`php.ini-production`. Three shapes are sent to `index.php`, chunked, with a fixed session id:

| shape | body |
|---|---|
| **S1 native** | `--parts 860 --big 78 --big-len 8000` &#8212; the shape the chain actually uses |
| **S2 chunk fill** | `--parts 0 --big 78 --big-len 1500000` &#8212; values below the huge threshold, so they occupy chunk pages |
| **S3 huge fill** | `--parts 0 --big 78 --big-len 3000000` &#8212; values above 2 MiB, allocated outside chunks |

The failing allocation is read from the container log, sliced to the lines produced by that one request
(a container log keeps older lines, so an unsliced grep will happily report the previous run's OOM).

```bash
# one row
docker run -d --name t -p 127.0.0.1:PORT:80 \
  -v "$PWD/lab/www:/var/www/html:ro" \
  -v "$PWD/lab/apache-single-worker.conf:/etc/apache2/mods-enabled/mpm_prefork.conf:ro" \
  php:8.5.12-apache /bin/sh -c \
  'cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini; exec apache2-foreground'
# send shape S1 with your own client, then read the refusal size out of the container log
docker logs t 2>&1 | grep -oE 'tried to allocate [0-9]+'
```

## The `post_max_size` gate comes first

On stock ini these images have `post_max_size = 8M`, and a 234 MB body is not parsed at all &#8212; which
looks exactly like "no OOM" and would produce a false negative:

```
78 values x 3 000 000 B, stock ini, chunked        -> post_nodes = 5,  usage 16.5 MiB
78 values x 3 000 000 B, stock ini, content-length -> post_nodes = 1
78 values x 3 000 000 B, post_max_size = 256M      -> post_nodes = 45, memory_peak = 256 613 176 B
```

Two things follow, and both matter for reading the table below.

* **Chunked framing does not bypass the gate** on these builds. It is parsed slightly further (5 nodes
  against 1) and then abandoned. Claims to the contrary do not reproduce here.
* **A no-OOM row only means something when the body was actually parsed.** S1 is 6 MB and fits under
  8M, so it runs on stock ini; S2 and S3 need `post_max_size` raised, and their rows are labelled
  accordingly.

## Results

Failing allocation per shape. `4096` = the arming signature; anything else is the parser.

| image | S1 native | S2 chunk fill | S3 huge fill |
|---|---|---|---|
| `php:8.4.26-apache` | **`4096`** &#8212; arms, full chain 3/3 | &#8212; | &#8212; |
| **`php:8.5.11-apache`** | **`4096`** (stock ini **and** `post_max_size=256M`) | `1503232` | &#8212; |
| `php:8.4.27-apache` | no OOM | `2097152` | `3014656` |
| `php:8.5.12-apache` | no OOM | `2097152` | `3014656` |
| `php:8.3.36-apache` | no OOM | `2097152` | `3014656` |
| `php:8.2.35-apache` | no OOM | `2097152` | `3014656` |

S2/S3 columns for 8.4.27, 8.5.12, 8.3.36 and 8.2.35 were measured with `post_max_size = 256M`; the S1
column was measured at stock ini, where the 6 MB body is parsed normally.

## What the table says

**`4096` is reproducible on demand, and it appears on exactly the builds that are expected to be
vulnerable.** `php:8.5.11-apache` is the positive control for the instrument: the same body that arms
8.4.26 produces the same page-sized refusal there, at stock ini, without any tuning. So the "no OOM"
cells are not an artefact of the probe &#8212; the probe sees the signature when it exists.

**The other four builds fail the same way, and it is not the same failure.** Where they do exhaust
memory, the amount refused is a 1.5 MB value (`1503232`), a 2 MiB chunk (`2097152`) or a 3 MB value
copy (`3014656`) &#8212; never a page. Their default ini, which differs from 8.4.26 in
`max_multipart_body_parts` (`-1` here, `1020`-derived from `max_input_vars` on 8.4.27), does not change
that: with the gate removed and the parser fully engaged, the granularity is still megabytes.

**8.5.12 and 8.3.36 match an independent result.** A separate PoC in this lab recorded
`php-fixed:8.5.12` and `php:8.3.36` as clean negatives (0/3 leak, 0/3 fire) and 8.3.x as structurally
blocked for this chain class. The signature table above agrees, by a different instrument.

## Porting status for 8.5.11

The signature says affected, so the chain should exist there. Porting our own tooling to that build was
attempted and is not finished. What was measured:

- the arming step reproduces exactly: same `4096` refusal, and the stale session identifier survives into
  the next request as a readable string, just as on 8.4.26;
- the disclosure step does not transfer as-is. The next request dies inside session initialisation, which
  runs *before* the request body is parsed, so the forged value is not yet in memory at the moment the
  stale identifier is dereferenced;
- which 2 MiB chunk that value lands in differs between the builds, and the working geometry depends on
  that assignment, not only on the page offset.

Everything that can be read off the image's symbol table (module base offsets) transfers directly. The
geometry does not, and re-deriving it is a size-and-order search against this build rather than a constant
edit.

## Second architecture: the shape is a property of PHP, not of the CPU

The arm64 measurements above were repeated on x86-64 (`php:8.4.26-apache`, amd64, stock ini, one Apache
child) to separate "this is how PHP behaves" from "this is how our machine behaves". The result is
reassuring for anyone using these notes on their own hardware:

| step | arm64 | x86-64 |
| --- | --- | --- |
| arming failure | `4096` | `4096` |
| disclosure window | 819 200 B | 819 200 B |
| destructor copies in the window | 876 | 876 |

Same bodies, same page, same counts. The allocation geometry that the arming step depends on belongs to
the session handler, not to the instruction set, and the fix behaves the same way on both.

What the *build* decides, and therefore has to be read from it rather than assumed:

- **symbol offsets** - resolvable from each image's own symbol table;
- **where the main allocator chunk sits** relative to the chunk the disclosure reads. On arm64 that
  distance measured 0x400000 on every fresh container, which makes it look like a constant. On x86-64 the
  same measurement came back as 0x200000, 0x600000 and 0x800000 in three containers, and the fixed value
  produced one success in three attempts. The window does contain the answer - a second cluster of chunk
  pointers - so the distance can be derived per run instead of assumed.

That is the takeaway worth carrying to other builds: a distance that is stable on one target is not a
constant, and a PoC that hardcodes it will look broken exactly where it is most needed.

*Method note, because it cost time here too:* a pointer test written for one architecture can silently
reject valid addresses on another. Ours did, and it made a perfectly good 800 KiB window look empty.

## Limits

- The signature is not the chain. A `4096` says the arming step is reachable; it does not by itself
  prove disclosure or execution, which need addresses recalibrated for that build. The full chain was
  verified end-to-end on 8.4.26 (3/3) and, by the separate PoC, on 8.5.11 (3/3).
- Stock `php:*-apache` images only, aarch64, single Apache child. No FPM row here (FPM cannot carry the
  state across requests at all, see [FPM.md](FPM.md)), no x86_64, no distro packages, no opcache.
- "No OOM" is a statement about these three shapes and this ini. It is not a proof that no body
  exhausting a page can be built for these builds; it is the same class of evidence as the negative
  control, one instrument stronger because the positive control is on a different branch.
