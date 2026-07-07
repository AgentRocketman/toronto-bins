<?php
/**
 * Installation and Configuration Wizard
 * Run this file once to setup the application
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 1) {
        $dbHost = $_POST['db_host'] ?? 'localhost';
        $dbName = $_POST['db_name'] ?? 'domain_generator';
        $dbUser = $_POST['db_user'] ?? 'root';
        $dbPass = $_POST['db_pass'] ?? '';

        try {
            $pdo = new PDO("mysql:host=$dbHost;charset=utf8mb4", $dbUser, $dbPass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$dbName`");

            $schema = file_get_contents(__DIR__ . '/database/schema.sql');
            $schema = str_replace(['CREATE DATABASE IF NOT EXISTS domain_generator CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;', 'USE domain_generator;'], '', $schema);

            $statements = array_filter(array_map('trim', explode(';', $schema)));
            foreach ($statements as $statement) {
                if (!empty($statement) && stripos($statement, 'DELIMITER') === false) {
                    $pdo->exec($statement);
                }
            }

            $_SESSION['db_config'] = compact('dbHost', 'dbName', 'dbUser', 'dbPass');
            header('Location: ?step=2');
            exit;

        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }

    if ($step === 2) {
        session_start();
        $dbConfig = $_SESSION['db_config'] ?? [];

        $openaiKey = $_POST['openai_key'] ?? '';
        $namecheapUser = $_POST['namecheap_user'] ?? '';
        $namecheapKey = $_POST['namecheap_key'] ?? '';
        $namecheapIp = $_POST['namecheap_ip'] ?? '';

        if (empty($openaiKey) || empty($namecheapUser) || empty($namecheapKey) || empty($namecheapIp)) {
            $error = "All API credentials are required";
        } else {
            $configContent = "<?php\n";
            $configContent .= "// Database configuration\n";
            $configContent .= "define('DB_HOST', '{$dbConfig['dbHost']}');\n";
            $configContent .= "define('DB_NAME', '{$dbConfig['dbName']}');\n";
            $configContent .= "define('DB_USER', '{$dbConfig['dbUser']}');\n";
            $configContent .= "define('DB_PASS', '{$dbConfig['dbPass']}');\n\n";

            $configContent .= "// API Keys\n";
            $configContent .= "define('OPENAI_API_KEY', '$openaiKey');\n";
            $configContent .= "define('NAMECHEAP_API_USER', '$namecheapUser');\n";
            $configContent .= "define('NAMECHEAP_API_KEY', '$namecheapKey');\n";
            $configContent .= "define('NAMECHEAP_USERNAME', '$namecheapUser');\n";
            $configContent .= "define('NAMECHEAP_CLIENT_IP', '$namecheapIp');\n\n";

            $configContent .= file_get_contents(__DIR__ . '/config.php');
            $configContent = preg_replace('/define\(\'DB_HOST\'.*?\);/s', '', $configContent);
            $configContent = preg_replace('/define\(\'DB_NAME\'.*?\);/s', '', $configContent);
            $configContent = preg_replace('/define\(\'DB_USER\'.*?\);/s', '', $configContent);
            $configContent = preg_replace('/define\(\'DB_PASS\'.*?\);/s', '', $configContent);
            $configContent = preg_replace('/define\(\'OPENAI_API_KEY\'.*?\);/s', '', $configContent);
            $configContent = preg_replace('/define\(\'NAMECHEAP_API_USER\'.*?\);/s', '', $configContent);
            $configContent = preg_replace('/define\(\'NAMECHEAP_API_KEY\'.*?\);/s', '', $configContent);
            $configContent = preg_replace('/define\(\'NAMECHEAP_USERNAME\'.*?\);/s', '', $configContent);
            $configContent = preg_replace('/define\(\'NAMECHEAP_CLIENT_IP\'.*?\);/s', '', $configContent);
            $configContent = preg_replace('/<\?php\s*<\?php/s', '<?php', $configContent);

            if (file_put_contents(__DIR__ . '/config.php', $configContent)) {
                header('Location: ?step=3');
                exit;
            } else {
                $error = "Failed to write config file. Check permissions.";
            }
        }
    }
}

session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domain Generator - Installation</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 1rem;
            padding: 2.5rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        h1 { color: #111827; margin-bottom: 0.5rem; }
        .subtitle { color: #6b7280; margin-bottom: 2rem; }
        .steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e5e7eb;
        }
        .step {
            flex: 1;
            text-align: center;
            padding: 0.5rem;
            color: #9ca3af;
            font-weight: 600;
        }
        .step.active { color: #6366f1; }
        .step.complete { color: #10b981; }
        .form-group {
            margin-bottom: 1.5rem;
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #374151;
        }
        input, textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 0.5rem;
            font-size: 1rem;
            font-family: inherit;
        }
        input:focus, textarea:focus {
            outline: none;
            border-color: #6366f1;
        }
        .btn {
            width: 100%;
            padding: 0.875rem;
            background: #6366f1;
            color: white;
            border: none;
            border-radius: 0.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
        }
        .btn:hover { background: #4f46e5; }
        .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }
        .success {
            background: #d1fae5;
            color: #059669;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }
        .help-text {
            font-size: 0.875rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }
        .complete-screen {
            text-align: center;
        }
        .complete-icon {
            font-size: 4rem;
            color: #10b981;
            margin-bottom: 1rem;
        }
        .btn-secondary {
            background: #8b5cf6;
            margin-top: 1rem;
        }
        .btn-secondary:hover { background: #7c3aed; }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($step < 3): ?>
        <h1>🚀 Installation Wizard</h1>
        <p class="subtitle">Let's set up your Domain Name Generator</p>

        <div class="steps">
            <div class="step <?= $step >= 1 ? ($step === 1 ? 'active' : 'complete') : '' ?>">
                1. Database
            </div>
            <div class="step <?= $step >= 2 ? ($step === 2 ? 'active' : 'complete') : '' ?>">
                2. API Keys
            </div>
            <div class="step <?= $step >= 3 ? 'active' : '' ?>">
                3. Complete
            </div>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <form method="POST">
                <div class="form-group">
                    <label>Database Host</label>
                    <input type="text" name="db_host" value="localhost" required>
                    <div class="help-text">Usually 'localhost'</div>
                </div>

                <div class="form-group">
                    <label>Database Name</label>
                    <input type="text" name="db_name" value="domain_generator" required>
                    <div class="help-text">Will be created if it doesn't exist</div>
                </div>

                <div class="form-group">
                    <label>Database Username</label>
                    <input type="text" name="db_user" value="root" required>
                </div>

                <div class="form-group">
                    <label>Database Password</label>
                    <input type="password" name="db_pass" placeholder="Leave blank if none">
                </div>

                <button type="submit" class="btn">Create Database & Continue</button>
            </form>
        <?php endif; ?>

        <?php if ($step === 2): ?>
            <form method="POST">
                <div class="form-group">
                    <label>OpenAI API Key</label>
                    <input type="text" name="openai_key" placeholder="sk-..." required>
                    <div class="help-text">Get from platform.openai.com</div>
                </div>

                <div class="form-group">
                    <label>Namecheap Username</label>
                    <input type="text" name="namecheap_user" required>
                </div>

                <div class="form-group">
                    <label>Namecheap API Key</label>
                    <input type="text" name="namecheap_key" required>
                    <div class="help-text">Enable in your Namecheap account settings</div>
                </div>

                <div class="form-group">
                    <label>Your Server IP Address</label>
                    <input type="text" name="namecheap_ip" value="<?= $_SERVER['SERVER_ADDR'] ?? '' ?>" required>
                    <div class="help-text">Must be whitelisted in Namecheap</div>
                </div>

                <button type="submit" class="btn">Save Configuration</button>
            </form>
        <?php endif; ?>

        <?php if ($step === 3): ?>
            <div class="complete-screen">
                <div class="complete-icon">✓</div>
                <h1>Installation Complete!</h1>
                <p class="subtitle">Your Domain Name Generator is ready to use</p>

                <a href="index.html" class="btn" style="display: block; text-decoration: none; color: white;">
                    Launch Application
                </a>

                <button onclick="deleteInstaller()" class="btn btn-secondary">
                    Delete Installer (Recommended)
                </button>

                <div style="margin-top: 2rem; padding: 1rem; background: #fef3c7; border-radius: 0.5rem;">
                    <strong>⚠️ Security Note:</strong> Please delete install.php after installation is complete.
                </div>
            </div>

            <script>
                function deleteInstaller() {
                    if (confirm('Are you sure you want to delete the installer?')) {
                        fetch('install.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'delete_installer=1'
                        }).then(() => {
                            alert('Please manually delete install.php from your server');
                        });
                    }
                }
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
