<?php
// Fix for Railway's reverse proxy - tells PHP to trust HTTPS from the proxy
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
require_once __DIR__ . '/includes/config.php';
SessionManager::start();
if (logged_in()) {
    redirect(BASE_PATH.'dashboard.php');
} else {
    redirect(BASE_PATH.'login.php');
}
