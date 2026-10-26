# PHP-FPM: the same bug, a different SAPI — and why the chain does not transfer

The Apache arm works because one process keeps living after a request is aborted by an out-of-memory
bailout. This document measures whether PHP-FPM behaves the same way. It does not, and the difference
is structural rather than a matter of tuning.

The lab in `lab-fpm/` exists to make that measurement reproducible: nginx in front of `php:8.4.26-fpm`,
the official `php.ini-production` copied in, `memory_limit=128M`, `display_errors=Off`,
upload-progress `cleanup=On`, and `pm = static`, `pm.max_children = 1` so that "same worker" is a fact
of configuration rather than an assumption.

## Pinning: prefork lets the client choose, FPM does not

| | Apache prefork | PHP-FPM |
|---|---|---|
| who terminates the client connection | the worker itself | nginx |
| how a request is routed to a process | the child that accepted the connection serves it until the connection closes | nginx opens its own FastCGI connection; the master hands each request to any idle worker |
| how a client can pin one process | **hold `MaxRequestWorkers-1` children** with half-sent requests (a private PoC stage), so the next request must go to the only free child | **cannot**; the pin has to be created by configuration (`pm.max_children = 1`) |
| on a real deployment | the trick works against an unmodified target | no client-side equivalent |

Measured, `pm.max_children = 1`, five consecutive requests: `pid=10 pid=10 pid=10 pid=10 pid=10`.

## The measurement

Same request, same tool, same arguments, one fresh container each:

```
                                     Apache prefork          PHP-FPM
worker before arming                 141934 (host pid)       24 (container pid)
arming OOM site                      tried to allocate 4096  tried to allocate 4096
worker after arming                  141934  SAME            25  DIFFERENT
state left behind                    PS(id)=0x7ffedfd37000   none — a new process
```

The important line is the second one: **the arming fires identically on both SAPIs**. The one-page
allocation failure at the disclosure encoder is reached on FPM too, which means the vulnerable code
path is exercised. What differs is what happens to the process afterwards.

From the FPM log:

```
PHP Fatal error:  Allowed memory size of 134217728 bytes exhausted (tried to allocate 4096 bytes)
WARNING: [pool www] child 10 exited with code 70 after 35.6 seconds from start
NOTICE: [pool www] child 23 started
```

FPM terminates a worker whose request ended in a bailout (exit code 70, its software-failure code) and
forks a fresh one. The heap that held the dangling `PS(id)` goes with it. Prefork does the opposite: the
child finishes the request with a 500 and goes back to the idle pool, keeping its heap.

## The body-phase route fails the same way

The obvious alternative is the *other* arming shape: `A2` (the cheap OOM inside the multipart parser)
happens after request startup, so one might expect the worker to survive it. Measured on the same FPM
target:

```
before A2   pid=23
A2          HTTP 502, PHP Fatal error: ... exhausted (tried to allocate 20480 bytes)
after A2    pid=24          WARNING: [pool www] child 23 exited with code 70
```

Both phases kill the worker. There is no ordering in which a later request can read through a pointer
that belonged to a process FPM has already replaced.

## A second, accidental barrier in front of FPM

Before any of this, the first A1 attempt against the FPM lab returned:

```
HTTP/1.1 413 Request Entity Too Large
```

not from PHP but from **nginx**: `client_max_body_size` defaults to 1 MiB, and the arming body is about
1.01 MiB. The disclosure body (33 MB) is blocked by the same default. On an nginx-plus-FPM deployment
the stock configuration therefore stops this chain at the door before the interpreter is reached; the
lab raises the limit deliberately (`client_max_body_size 64m`) because otherwise nothing can be measured.

## What this means

- **The chain is prefork-specific.** Not because FPM cannot reach the vulnerable code — it can, and the
  failure site is identical — but because FPM ends the worker that would have carried the state into the
  next request.
- **`pm.max_children = 1` is not the same as `MaxRequestWorkers 1`.** Pinning FPM to a single worker
  does not help an attacker: the single worker is still replaced after every bailout.
- **For defenders:** running PHP-FPM behind a proxy that terminates requests is a strong structural
  mitigation for the cross-request half of this bug class, independent of patching. Raising
  `client_max_body_size` removes the accidental 413 barrier and should be done deliberately, not to
  "make it work".
- **For the PoC:** everything in the private PoC directory assumes prefork, and a private PoC stage only makes sense
  there. The FPM lab is shipped so the negative result can be reproduced rather than trusted.

## Reproduce

```bash
cd POC_Final
(cd lab-fpm && docker compose -p p2s-fpmchain up -d)
sleep 5
curl -s http://127.0.0.1:18140/index.php                 # OK
curl -s http://127.0.0.1:18140/probe.php | grep '^pid='  # repeat: same pid

# send the arming body with the private PoC client, then look at the pool
docker logs --tail 4 p2s-fpmchain-fpm                    # tried to allocate 4096, exit code 70
curl -s http://127.0.0.1:18140/probe.php | grep '^pid='  # a different pid

# and the worker is stripped, so calibration needs a build with symbols (private)

(cd lab-fpm && docker compose -p p2s-fpmchain down)
```
