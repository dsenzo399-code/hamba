<?php
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'app' => 'hamba', 'use' => 'index.php?route=auth/csrf']);
