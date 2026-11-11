<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth_pages.php';

if (current_admin()) redirect('admin/index.php');
auth_forgot_page('admin');
