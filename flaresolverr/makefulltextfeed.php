<?php
// SPDX-License-Identifier: MIT
// Keep the core's top-level helper declarations intact and handle solver errors
// for both feed conversion and extract.php, which includes this entry point.
try {
    require dirname(__FILE__).'/makefulltextfeed-core.php';
} catch (FlareSolverrException $e) {
    if (!headers_sent()) {
        http_response_code(502);
        header('Cache-Control: no-store');
        header_remove('Expires');
    }
    error_log($e->getMessage());
    if (isset($debug_mode) && $debug_mode) {
        debug($e->getMessage());
    } elseif ((defined('_FF_FTR_MODE') && _FF_FTR_MODE === 'simple') || (isset($format) && $format === 'json')) {
        if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array('error' => $e->getMessage()));
    } else {
        if (!headers_sent()) header('Content-Type: text/plain; charset=UTF-8');
        echo 'Feed conversion failed: '.$e->getMessage();
    }
    exit(1);
}
