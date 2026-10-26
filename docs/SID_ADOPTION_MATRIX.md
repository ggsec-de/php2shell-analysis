# Session-id adoption: the deterministic matrix

Earlier measurements contradicted each other — the same `PHPSESSID` value was adopted in one shape
and replaced in another with only the field count changing. Since every later stage rests on this,
it was measured as a matrix: two knobs, three repeats per cell, stock `php:8.4.26-apache`
(digest-pinned), minimal docroot.

Tool: a private PoC stage. Signal: **no `Set-Cookie` = adopted**; `Set-Cookie` with a different id
= replaced (PHP generated one).

## Result — 24/24 cells deterministic (3/3 each)

| files | progress pos | transport | outcome |
|---|---|---|---|
| 0 | first | field | **replaced** |
| 1, 2, 5, 19, 25 | first | field | **adopted** (3/3 each) |
| 0, 1, 2, 5, 19 | last | field | **replaced** |
| 25 | last | field | **adopted** |
| 0 | first | cookie | **replaced** |
| 1, 2, 5, 19, 25 | first | cookie | **adopted** |
| 0, 1, 2, 5, 19 | last | cookie | **replaced** |
| 25 | last | cookie | **adopted** |

```
Determinism check: all cells consistent
```

## The rule

1. **Field or cookie makes no difference.** All six "first" rows are identical for both transports,
   and so are all six "last" rows. The callback takes whichever id is available; `use_only_cookies`
   does not distinguish them here.
2. **What matters is whether a file part follows the progress field.** `progress first` + at least
   one file part = adopted, every time. `progress first` with no file part = replaced (there is
   nothing to attach the progress entry to).
3. **`progress last` is rejected for up to 19 file parts and adopted at 25.** The crossover sits at
   `max_file_uploads` (default 20): past the cap PHP stops registering uploads, which changes which
   event the callback sees last. Deterministic, but a config-dependent edge — do not build on it.
4. Valid charset and length are still required (see [SID_ADOPTION.md](./SID_ADOPTION.md)); this
   matrix used a 32-hex value throughout, so every "replaced" above is a *logic* decision, not a
   rejected string.

## What this changes

- **B1 iteration 2 was not a mystery, it was the wrong shape.** `b1_probe.py` sends the file part
  *before* the progress field, i.e. the "progress last" row — which is rejected, so PHP generated a
  new id and there was nothing stale to encode. With `progress first` the id would be adopted, and
  that is equally useless for a leak: adoption overwrites the stale pointer.
- **A leak cannot come from `PS(id)` on this build.** Both directions are now excluded by
  measurement: adopted (pointer overwritten) or rejected (no session init, no `send_cookie`). The
  reference's elaborate `LEAK_SPEC` is therefore not decoration — the forged `zend_string` objects
  are the mechanism, not an optimisation.
- **The shape is now selectable on purpose.** Any stage that needs the id adopted must put the
  progress field first and include a file part; any stage that needs the id *untouched* must use
  progress-last with fewer than 20 file parts.

## Reproduce

```bash
cd ~/labs/local/php2shell-modphp-lab
# one cell of the matrix: three repeats per configuration
# narrower run, one knob:
# file-part counts 0, 1 and 2, five repeats each
```
