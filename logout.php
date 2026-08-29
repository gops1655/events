<?php
require __DIR__ . '/includes/init.php';
require_login();
logout();
redirect('index.php');
