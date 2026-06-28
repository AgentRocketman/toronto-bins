<?php
require_once __DIR__ . '/config.php';

session_start();
header('Content-Type: application/json');

try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'POST') {
        // Login
        $input = json_decode(file_get_contents('php://input'), true);
        $password = $input['password'] ?? '';

        if ($password === MC_ADMIN_PASSWORD) {
            $_SESSION['mc_authenticated'] = true;
            echo json_encode(['success' => true]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid password']);
        }
    } elseif ($method === 'DELETE') {
        // Logout
        $_SESSION['mc_authenticated'] = false;
        session_destroy();
        echo json_encode(['success' => true]);
    } elseif ($method === 'GET') {
        // Check auth status
        echo json_encode([
            'authenticated' => isset($_SESSION['mc_authenticated']) && $_SESSION['mc_authenticated'] === true
        ]);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
