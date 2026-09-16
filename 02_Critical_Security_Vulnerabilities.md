
# Critical Security Vulnerabilities

Connected to: [[00_Project_Overview_&_Graph_Hub]], [[01_Architecture_&_Data_Flow]], [[05_Comprehensive_Fixes_&_Code_Diffs]]

---

## 🚨 Security Finding 1: Authentication Backdoor & Password Auto-Reset

- **Location**: `warehouse_finals/api/auth.php` (Lines 121–139)
- **Classification**: CWE-798 (Use of Hard-coded Credentials) / CWE-287 (Improper Authentication)
- **Severity**: 🔴 Critical (CVSS 9.8)

### Vulnerability Mechanism
When an authentication attempt fails standard verification (`!$passwordOk`), the script checks if the supplied password belongs to a hardcoded whitelist:
```php
if (!$passwordOk && $row) {
    $allowedFallbacks = array(
        'password123!',
        'admin123',
        '123456',
        'admin',
        strtolower((string)$row['username']),
        strtolower((string)$username)
    );
    if (in_array(strtolower($password), $allowedFallbacks, true)) {
        $passwordOk = true;
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE users SET password = :p WHERE user_id = :uid");
        $upd->bindParam(':p', $newHash, PDO::PARAM_STR);
        $uid = (int)$row['user_id'];
        $upd->bindParam(':uid', $uid, PDO::PARAM_INT);
        $upd->execute();
    }
}
```

### Exploit Impact
1. Any attacker who knows an active username (e.g. `admin` or an employee's username) can log into that account using `password123!`, `admin123`, `123456`, `admin`, or the username itself.
2. The code **rewrites the user's password in the database** to the fallback value, permanently hijacking the legitimate user's credentials.

### Remediation
Remove lines 121–139 completely. See full patch in [[05_Comprehensive_Fixes_&_Code_Diffs#fix-1-remove-auth-backdoor-and-first-name-login]].

---

## 🚨 Security Finding 2: First Name Login Collision & Ambiguity

- **Location**: `warehouse_finals/api/auth.php` (Lines 15–18)
- **Classification**: CWE-287 (Improper Authentication)
- **Severity**: 🔴 High

### Vulnerability Mechanism
User lookup matches against both the unique `username` AND non-unique `first_name`:
```php
SELECT u.user_id, u.role_id, r.role_name, u.first_name, u.last_name,
       u.username, u.password, u.is_active, r.is_active AS role_is_active
FROM users u
INNER JOIN role r ON u.role_id = r.role_id
WHERE LOWER(u.username) = LOWER(:username)
   OR LOWER(u.first_name) = LOWER(:first_name)
ORDER BY (LOWER(u.username) = LOWER(:exact_username)) DESC, u.user_id ASC
LIMIT 1
```

### Exploit Impact
- First names are not guaranteed unique in the database.
- If a user enters their first name (e.g., "Juan"), the system attempts to authenticate against whichever account has the lowest `user_id`. Combined with the fallback password backdoor, entering a common first name grants instant unauthorized access to that account.

### Remediation
Restrict lookup strictly to `LOWER(u.username) = LOWER(:username)`. See [[05_Comprehensive_Fixes_&_Code_Diffs#fix-1-remove-auth-backdoor-and-first-name-login]].
