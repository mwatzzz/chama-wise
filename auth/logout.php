<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(400);
    echo 'Invalid logout request.';
    exit;
}

destroy_session();
redirect('index.php');
