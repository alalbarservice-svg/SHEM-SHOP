<?php
/**
 * Fallback template. The storefront shell is normally emitted earlier on template_redirect
 * (inc/shell.php); reaching this file means the request is not a shell route (non-HTML 404 etc.).
 */
if (!defined('ABSPATH')) exit;
status_header(404);
nocache_headers();
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
