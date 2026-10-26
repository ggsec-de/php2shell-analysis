#!/bin/bash
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 GG ADVANCED IT Security UG

# start_target.sh - bring up the php2shell lab target.
#
# The target is the *official* image, unmodified: only two things are done to it, both at start:
#   * the php.ini-production file the image ships is copied to php.ini (the image does not do this
#     itself); without it the arming OOM lands in the session decoder instead of the encoder and the
#     chain does not start,
#   * a minimal docroot is mounted. It prints "OK", so a request that reaches PHP answers 200 and the
#     leak response is easy to recognise.
#
# With --single the prefork configuration is overridden to one worker, which removes the need to pin
# a child. Without it the image's own prefork config applies (StartServers 5, MaxRequestWorkers 150)
# and the chain has to hold 149 connections first - the private chain runner can be told to do that.
#
# usage:
#   ./lab/start_target.sh              # official MPM (150 workers)
#   ./lab/start_target.sh --single     # one worker (baseline)
#   ./lab/start_target.sh --stop
set -eu

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$HERE/.." && pwd)

# Pinned by digest: a tag moves, and a rebuilt image moves the offsets this PoC depends on.
# The tested image is `php:8.4.26-apache`, aarch64, digest below.
IMAGE=${IMAGE:-php@sha256:60111400ff77bdc44fa79b4f072afe49a42abba71e0846ad496be4761dc9c11a}
NAME=${NAME:-p2s-target}
PORT=${PORT:-18110}

if [ -n "${DOCKER:-}" ]; then
  :
elif docker info >/dev/null 2>&1; then
  DOCKER=docker
else
  DOCKER="sudo docker"
fi

SINGLE=0
for arg in "$@"; do
  case "$arg" in
    --single) SINGLE=1 ;;
    --stop)
      $DOCKER rm -f "$NAME" >/dev/null 2>&1 || true
      echo "stopped $NAME"
      exit 0
      ;;
    *) echo "usage: $0 [--single|--stop]" >&2; exit 2 ;;
  esac
done

MOUNT=""
if [ "$SINGLE" = 1 ]; then
  MOUNT="-v $HERE/apache-single-worker.conf:/etc/apache2/conf-enabled/zz-p2s.conf:ro"
fi

# Apache runs as www-data inside the container, so the mounted docroot must be world-readable
# whatever the checkout's umask was. A 0700 directory here produces a 403 on every request, which
# looks like a broken target rather than a permission problem.
chmod -R a+rX "$HERE/www" 2>/dev/null || true

$DOCKER rm -f "$NAME" >/dev/null 2>&1 || true
# shellcheck disable=SC2086
$DOCKER run -d --name "$NAME" $MOUNT \
  -p "127.0.0.1:$PORT:80" \
  -v "$HERE/www:/var/www/html:ro" \
  "$IMAGE" /bin/sh -c \
  'cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini;
   exec apache2-foreground' >/dev/null

for _ in $(seq 1 40); do
  if [ "$($DOCKER exec "$NAME" sh -c 'wget -qO- http://127.0.0.1/index.php 2>/dev/null' || true)" = "OK" ]; then
    break
  fi
  sleep 0.5
done
REPLY=$(curl -s -m 3 "http://127.0.0.1:$PORT/index.php" || true)
if [ "$REPLY" != "OK" ]; then
  echo "target did not answer OK; last container output:" >&2
  $DOCKER logs --tail 5 "$NAME" >&2 2>&1 || true
  echo "hint: 403 means the mounted docroot is not world-readable, 404 that no docroot was mounted" >&2
fi

echo "target   : $($DOCKER ps --filter "name=$NAME" --format '{{.Names}} {{.Status}}')"
echo "reply    : $REPLY"
echo "image    : $($DOCKER inspect -f '{{.Config.Image}}' "$NAME")"
echo "arch     : $($DOCKER exec "$NAME" uname -m)"
echo "php      : $($DOCKER exec "$NAME" php -v | head -1)"
$DOCKER exec "$NAME" php -i 2>/dev/null | grep -E \
  '^display_errors|^memory_limit|^post_max_size|^output_buffering|^session.upload_progress.cleanup|^session.use_only_cookies' \
  | head -8
echo "--- MPM:"
if [ "$SINGLE" = 1 ]; then
  echo "workers  : 1 (lab/apache-single-worker.conf, non-official)"
else
  $DOCKER exec "$NAME" sh -c "grep -v '^#' /etc/apache2/mods-enabled/mpm_prefork.conf | sed '/^$/d'"
  echo "workers  : official - run the chain with --workers 150"
fi
