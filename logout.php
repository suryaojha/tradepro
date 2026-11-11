<?php
require_once __DIR__ . '/includes/bootstrap.php';

unset($_SESSION['uid']);
session_regenerate_id(true);
redirect('login.php');
