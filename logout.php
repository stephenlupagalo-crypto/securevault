<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();
if (logged_in()) {
    log_activity('logout');
}
session_destroy();
redirect(BASE_PATH.'login.php');
