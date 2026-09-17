<?php

require_once __DIR__ . '/auth.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}