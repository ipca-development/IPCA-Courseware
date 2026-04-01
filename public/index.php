<?php
require_once __DIR__ . '/../src/bootstrap.php';
if (!cw_is_logged_in()) redirect('/login.php');
redirect('/admin/dashboard.php');