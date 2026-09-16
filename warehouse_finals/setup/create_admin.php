<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require_once __DIR__ . '/../config/db.php';

function prompt(string $label): string
{
    echo $label;
    $value = fgets(STDIN);
    if ($value === false) {
        throw new RuntimeException('Input failed.');
    }
    return trim($value);
}

function promptHidden(string $label): string
{
    echo $label;

    if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
        $command = 'powershell -NoProfile -Command "$p = Read-Host -AsSecureString; $b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($p); [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b)"';
        $value = shell_exec($command);
        echo PHP_EOL;
        return trim((string)$value);
    }

    if (function_exists('shell_exec')) {
        $stty = shell_exec('stty -g 2>/dev/null');
        if ($stty !== null) {
            shell_exec('stty -echo');
            $value = fgets(STDIN);
            shell_exec('stty ' . trim($stty));
            echo PHP_EOL;
            return trim((string)$value);
        }
    }

    $value = fgets(STDIN);
    echo PHP_EOL;
    return trim((string)$value);
}

function validName(string $value): bool
{
    return $value !== '' && strlen($value) <= 50 && preg_match("/^[A-Za-z .'-]+$/", $value) === 1;
}

function validUsername(string $value): bool
{
    return strlen($value) >= 3 && strlen($value) <= 50 && preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1;
}

function validContact(string $value): bool
{
    return $value === '' || (strlen($value) >= 7 && strlen($value) <= 20 && preg_match('/^[0-9+() .-]+$/', $value) === 1);
}

function passwordPolicy(string $password): bool
{
    return strlen($password) >= 12 && strlen($password) <= 255;
}

try {
    $conn = getConnection();

    $check = $conn->query(
        "SELECT COUNT(*) FROM users u
         INNER JOIN role r ON r.role_id = u.role_id
         WHERE r.role_name = 'Administrator'"
    );

    if ((int)$check->fetchColumn() > 0) {
        fwrite(STDERR, "An Administrator account already exists. Bootstrap is disabled.\n");
        exit(1);
    }

    $firstName = prompt('First name: ');
    $lastName = prompt('Last name: ');
    $username = prompt('Username: ');
    $contact = prompt('Contact number (optional): ');
    $password = promptHidden('Password (minimum 12 characters): ');
    $confirm = promptHidden('Confirm password: ');

    if (!validName($firstName) || !validName($lastName)) {
        throw new InvalidArgumentException('Invalid name.');
    }
    if (!validUsername($username)) {
        throw new InvalidArgumentException('Invalid username.');
    }
    if (!validContact($contact)) {
        throw new InvalidArgumentException('Invalid contact number.');
    }
    if (!passwordPolicy($password)) {
        throw new InvalidArgumentException('Password must be between 12 and 255 characters.');
    }
    if (!hash_equals($password, $confirm)) {
        throw new InvalidArgumentException('Passwords do not match.');
    }

    $conn->beginTransaction();

    $roleStmt = $conn->prepare(
        "SELECT role_id FROM role WHERE role_name = 'Administrator' FOR UPDATE"
    );
    $roleStmt->execute();
    $roleId = $roleStmt->fetchColumn();

    if ($roleId === false) {
        throw new RuntimeException('Administrator role is missing from the database.');
    }

    $adminCheck = $conn->prepare(
        "SELECT COUNT(*) FROM users u
         INNER JOIN role r ON r.role_id = u.role_id
         WHERE r.role_name = 'Administrator'"
    );
    $adminCheck->execute();
    if ((int)$adminCheck->fetchColumn() > 0) {
        throw new RuntimeException('An Administrator account already exists. Bootstrap is disabled.');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if ($passwordHash === false) {
        throw new RuntimeException('Password hashing failed.');
    }

    $stmt = $conn->prepare(
        "INSERT INTO users
            (role_id, first_name, last_name, username, password, contact_number, is_active)
         VALUES
            (:role_id, :first_name, :last_name, :username, :password, :contact_number, 1)"
    );
    $intRoleId = (int)$roleId;
    $contactVal = $contact !== '' ? $contact : null;
    $stmt->bindParam(':role_id', $intRoleId, PDO::PARAM_INT);
    $stmt->bindParam(':first_name', $firstName, PDO::PARAM_STR);
    $stmt->bindParam(':last_name', $lastName, PDO::PARAM_STR);
    $stmt->bindParam(':username', $username, PDO::PARAM_STR);
    $stmt->bindParam(':password', $passwordHash, PDO::PARAM_STR);
    $stmt->bindParam(':contact_number', $contactVal, $contactVal === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->execute();

    $conn->commit();

    echo "Administrator account created successfully.\n";
    echo "You may now log in through the normal login page.\n";
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }

    fwrite(STDERR, "Administrator setup failed.\n");
    if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) {
        fwrite(STDERR, $e->getMessage() . "\n");
    } else {
        error_log('Warehouse administrator bootstrap error: ' . $e->getMessage());
    }
    exit(1);
}

