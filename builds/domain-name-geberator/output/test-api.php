<?php
header('Content-Type: application/json');

$check = $_GET['check'] ?? '';

switch ($check) {
    case 'php_version':
        echo json_encode([
            'pass' => version_compare(PHP_VERSION, '7.4.0', '>='),
            'version' => PHP_VERSION
        ]);
        break;

    case 'mysql':
        echo json_encode([
            'pass' => extension_loaded('pdo_mysql'),
            'loaded' => extension_loaded('pdo_mysql')
        ]);
        break;

    case 'curl':
        echo json_encode([
            'pass' => extension_loaded('curl'),
            'loaded' => extension_loaded('curl')
        ]);
        break;

    case 'json':
        echo json_encode([
            'pass' => extension_loaded('json'),
            'loaded' => extension_loaded('json')
        ]);
        break;

    case 'database':
        try {
            require_once 'config.php';
            $pdo = getDB();
            echo json_encode([
                'pass' => true,
                'message' => 'Database connected successfully'
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'pass' => false,
                'error' => 'Database connection failed'
            ]);
        }
        break;

    case 'openai_config':
        require_once 'config.php';
        echo json_encode([
            'pass' => defined('OPENAI_API_KEY') && OPENAI_API_KEY !== 'your-openai-api-key-here',
            'configured' => defined('OPENAI_API_KEY')
        ]);
        break;

    case 'namecheap_config':
        require_once 'config.php';
        echo json_encode([
            'pass' => defined('NAMECHEAP_API_KEY') && NAMECHEAP_API_KEY !== 'your-namecheap-api-key',
            'configured' => defined('NAMECHEAP_API_KEY')
        ]);
        break;

    default:
        echo json_encode(['error' => 'Unknown check']);
}
