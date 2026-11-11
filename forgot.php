<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth_pages.php';

if (current_user()) redirect('profile.php');
auth_forgot_page('user');
