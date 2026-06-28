<?php require 'app/bootstrap.php'; echo db()->query('SELECT NOW() + INTERVAL \'1 MONTH\'')->fetchColumn();
