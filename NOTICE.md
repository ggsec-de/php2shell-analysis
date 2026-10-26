# Provenance and licensing

## What this repository contains

This is the **write-up half** of the work: the measurement records, the negative control, the version
matrix, the FPM result, and the lab harness that stands up the targets. Everything here was written by
GG Advanced IT Security while reproducing the chain, and is released under the MIT licence (see below).

## What it does **not** contain, and why

**The exploit itself is withheld.** The four request stages, their helpers, the gdb probes used to
calibrate them, and the two documents that read as a runbook are kept in a private lab. Publishing them
would mean publishing a working remote-code-execution chain, which is a different act from publishing the
analysis of one. What is here is what a defender needs in order to decide whether a build is affected and
why, without handing over the tooling.

Two further directories from the private lab were left out for licensing reasons:

| left out | why |
|---|---|
| `fake_exploits/` (four third-party exploit repositories, ~34 MB) | no licence file upstream, therefore no grant of rights. They were used as a black-box oracle during the work and are not redistributed here. |
| `reference/phpcve-lab-tools/` (a vendored copy of another researcher's tools) | same reason — upstream publishes no licence. The tools were used to pin the geometry; all numbers derived from them were re-measured with our own instruments and are recorded in `docs/`. |

The obvious consequence, stated plainly: **this repository cannot be run as a drop-in replacement for
those tools.** It can be run end to end on its own (`lab/start_target.sh`). If you want
the oracle material, fetch it from upstream and respect its terms — do not expect it here.

## Prior work

The bug class, the original pure-HTTP research, and the first public write-ups are not ours. This work
started from another researcher's published results and independently rebuilt the chain with our own
tooling — every offset, every page number and every allocation size in `docs/` was re-measured on this
image, because a number copied from a different build only looks like knowledge.

If you are the author of upstream material referenced in `docs/` and want a correction or a link
changed, open an issue.

## Every constant is image-specific

Nothing in this work is a property of "PHP" in general. Offsets, page numbers, allocation sizes and the
geometry of the arming step all belong to one build, and every one of them was re-measured here rather
than copied:

```
php@sha256:60111400ff77bdc44fa79b4f072afe49a42abba71e0846ad496be4761dc9c11a   (php:8.4.26-apache, aarch64)
```

The digest is pinned in `lab/start_target.sh` so that statement stays true over time. Exact offsets are
not published; the published results are the ones that answer "is this build affected", and those are in
`docs/`.

## A note on what the numbers mean

Three of the documents here exist because a claim without a measurement is a guess:

- `docs/NEGATIVE_CONTROL_8.4.27.md` — the fix, measured. Including the mechanism: that build charges
  allocation-limit checks in 2 MiB units, so the page-sized refusal the chain needs never occurs.
- `docs/VERSION_MATRIX.md` — six images, one question: does the arming signature appear.
- `docs/FPM.md` — why the chain does not transfer to PHP-FPM, measured rather than assumed.

## Embargo

The exploit code, its calibration probes and the runbook are held back **until at least 25 November
2026**. This repository publishes the analysis only: enough to decide whether a build is affected, to
recognise the traffic, and to check that a patch is in place.

If the date moves, this file and `README.md` change in the same commit, so the date published here is
always the current plan rather than a historical marker. Nothing in this repository is a promise that the
code will be released on that date; it is a statement of when it will *not* be.

## Licence

**MIT**, copyright 2026 GG ADVANCED IT Security UG. The full text is in [LICENSE](LICENSE).

Everything under `lab/`, `lab-fpm/`, `docs/` and the top-level Markdown files was written by us, so the
whole repository is covered by that grant. The shell script carries an SPDX header:

```
SPDX-License-Identifier: MIT
Copyright (c) 2026 GG ADVANCED IT Security UG
```

The third-party material described above is **not** part of this grant, because it is not in this
repository and was never ours to license. If you reuse the numbers or the method, attribution is welcome
but not required by MIT; attribution for the *prior work* described above is a courtesy, not a licence
condition.

## Responsible use

The material describes a memory-safety bug in a specific PHP build. It is published for defenders:
to recognise the traffic pattern, to verify that a patch is in place, and to test their own
infrastructure. The arming requests are destructive by construction — they exhaust the target's memory
limit and crash a worker — so running them against anything you do not own is both illegal in most
jurisdictions and trivially visible in logs.
