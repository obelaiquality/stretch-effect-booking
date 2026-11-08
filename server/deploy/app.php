<?php
// Copy this file to public_html/app.php on the host and set SERVER_DIR to where you uploaded the server/ folder
// (outside public_html). It only forwards the request to the real front controller.
$serverDir = '/home/CPANELUSER/stretch-effect/server';
require $serverDir . '/public/index.php';
