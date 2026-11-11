<?php
// Chart data for the admin dashboard and user pages (real database numbers).
require_once __DIR__ . '/../includes/bootstrap.php';

require_admin();
session_write_close();
stats_respond(null);
