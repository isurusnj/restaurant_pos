<?php
session_start();
session_unset();
session_destroy();
header('Location: /restaurant_pos/login.php');
exit;
