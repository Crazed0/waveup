<?php
require __DIR__ . '/../api/_common.php';

$_SESSION = [];
session_destroy();

header('Location: ./index.php?page=login');
exit;
