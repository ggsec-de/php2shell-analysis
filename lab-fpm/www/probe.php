<?php
// SPDX-License-Identifier: MIT
// Copyright (c) 2026 GG ADVANCED IT Security UG

// probe.php - the instrument that makes "the same worker answered" a measurement instead of a hope.
//
// In FPM the client cannot address a worker, so before any arming result means anything this has to
// show that consecutive requests landed in the same process. It also reports what the request built,
// which is how a dropped body (input limit) is told apart from a body that was simply not big enough.
header('Content-Type: text/plain');
$deepest = 0;
$count = 0;
$walk = function ($value, $depth) use (&$walk, &$deepest, &$count) {
    $count++;
    if ($depth > $deepest) {
        $deepest = $depth;
    }
    if (is_array($value)) {
        foreach ($value as $item) {
            $walk($item, $depth + 1);
        }
    }
};
$walk($_POST, 0);
echo 'pid=', getmypid(), "\n";
echo 'php=', PHP_VERSION, "\n";
echo 'post_top_level=', count($_POST), "\n";
echo 'post_nodes=', $count, "\n";
echo 'post_depth=', $deepest, "\n";
echo 'memory_peak=', memory_get_peak_usage(true), "\n";
echo 'memory_limit=', ini_get('memory_limit'), "\n";
echo 'session_status=', session_status(), "\n";
