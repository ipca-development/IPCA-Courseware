<?php
require_once __DIR__ . '/../src/bootstrap.php';
cw_logout();
redirect('/login.php');