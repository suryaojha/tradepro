<?php
require_once __DIR__ . '/../includes/bootstrap.php';

unset($_SESSION['admin_id']);
session_regenerate_id(true);
redirect('admin/login.php');
