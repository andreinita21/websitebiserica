<?php
require_once __DIR__ . '/../includes/auth.php';

bsv_logout();
header('Location: login.php');
exit;
