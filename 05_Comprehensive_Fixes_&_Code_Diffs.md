# Comprehensive Fixes & Code Diffs

Connected to: [[00_Project_Overview_&_Graph_Hub]], [[02_Critical_Security_Vulnerabilities]], [[03_Fatal_Frontend_Runtime_Errors]], [[04_API_&_Schema_Mismatches]]

---

## Fix 1: Remove Auth Backdoor and First Name Login

### File: `warehouse_finals/api/auth.php`

#### Change 1: Require exact username match only (Lines 15–18)
```diff
--- a/warehouse_finals/api/auth.php
+++ b/warehouse_finals/api/auth.php
@@ -14,11 +14,9 @@ function findUserByUsername($conn, $username) {
          FROM users u
          INNER JOIN role r ON u.role_id = r.role_id
-         WHERE LOWER(u.username) = LOWER(:username)
-            OR LOWER(u.first_name) = LOWER(:first_name)
-         ORDER BY (LOWER(u.username) = LOWER(:exact_username)) DESC, u.user_id ASC
+         WHERE LOWER(u.username) = LOWER(:username)
          LIMIT 1"
      );
      $cleanUsername = trim($username);
      $stmt->bindParam(':username', $cleanUsername, PDO::PARAM_STR);
-     $stmt->bindParam(':first_name', $cleanUsername, PDO::PARAM_STR);
-     $stmt->bindParam(':exact_username', $cleanUsername, PDO::PARAM_STR);
      $stmt->execute();
```

#### Change 2: Delete fallback backdoor (Lines 121–139)
```diff
--- a/warehouse_finals/api/auth.php
+++ b/warehouse_finals/api/auth.php
@@ -121,19 +121,0 @@ if ($method === 'POST' && $action === 'login') {
-    if (!$passwordOk && $row) {
-        $allowedFallbacks = array(
-            'password123!',
-            'admin123',
-            '123456',
-            'admin',
-            strtolower((string)$row['username']),
-            strtolower((string)$username)
-        );
-        if (in_array(strtolower($password), $allowedFallbacks, true)) {
-            $passwordOk = true;
-            $newHash = password_hash($password, PASSWORD_DEFAULT);
-            $upd = $conn->prepare("UPDATE users SET password = :p WHERE user_id = :uid");
-            $upd->bindParam(':p', $newHash, PDO::PARAM_STR);
-            $uid = (int)$row['user_id'];
-            $upd->bindParam(':uid', $uid, PDO::PARAM_INT);
-            $upd->execute();
-        }
-    }
```

---

## Fix 2: Frontend DOM ID Mismatches

### File: `warehouse_finals/js/app.js`

#### Product Module Fix:
```diff
--- a/warehouse_finals/js/app.js
+++ b/warehouse_finals/js/app.js
@@ -436,3 +436,3 @@ function editProduct(id) {
-  document.getElementById('product_unit_measure').value = item.unit_measure;
-  document.getElementById('product_unit_price').value = item.unit_price;
-  document.getElementById('product_reorder_level').value = item.reorder_level;
+  document.getElementById('unit_measure').value = item.unit_measure;
+  document.getElementById('unit_price').value = item.unit_price;
+  document.getElementById('reorder_level').value = item.reorder_level;
@@ -449,3 +449,3 @@ async function saveProduct(e) {
-    unit_measure: document.getElementById('product_unit_measure').value.trim(),
-    unit_price: parseFloat(document.getElementById('product_unit_price').value),
-    reorder_level: parseInt(document.getElementById('product_reorder_level').value)
+    unit_measure: document.getElementById('unit_measure').value.trim(),
+    unit_price: parseFloat(document.getElementById('unit_price').value),
+    reorder_level: parseInt(document.getElementById('reorder_level').value)
```

#### Supplier Module Fix:
```diff
--- a/warehouse_finals/js/app.js
+++ b/warehouse_finals/js/app.js
@@ -513,3 +513,4 @@ function editSupplier(id) {
-  document.getElementById('supplier_contact_person').value = item.contact_person || '';
-  document.getElementById('supplier_phone').value = item.phone || '';
-  document.getElementById('supplier_email').value = item.email || '';
+  document.getElementById('contact_person').value = item.contact_person || '';
+  document.getElementById('phone').value = item.phone || '';
+  document.getElementById('email').value = item.email || '';
+  document.getElementById('address').value = item.address || '';
@@ -525,4 +526,5 @@ async function saveSupplier(e) {
-    contact_person: document.getElementById('supplier_contact_person').value.trim(),
-    phone: document.getElementById('supplier_phone').value.trim(),
-    email: document.getElementById('supplier_email').value.trim()
+    contact_person: document.getElementById('contact_person').value.trim(),
+    phone: document.getElementById('phone').value.trim(),
+    email: document.getElementById('email').value.trim(),
+    address: document.getElementById('address').value.trim()
```

#### Product ↔ Supplier Link Module Fix:
```diff
--- a/warehouse_finals/js/app.js
+++ b/warehouse_finals/js/app.js
@@ -589,1 +589,1 @@ function renderLinks(rows) {
-    clone.querySelector('.row-notes').textContent = r.notes || '—';
+    clone.querySelector('.row-cost').textContent = peso(r.supplier_unit_cost);
@@ -611,1 +611,1 @@ async function saveLink(e) {
-    notes: document.getElementById('link_notes').value.trim()
+    supplier_unit_cost: document.getElementById('supplier_unit_cost').value.trim()
```
And in `warehouse_finals/index.html` line 1015:
```diff
--- a/warehouse_finals/index.html
+++ b/warehouse_finals/index.html
@@ -1015,1 +1015,1 @@
-    <td class="row-notes"></td>
+    <td class="font-monospace row-cost"></td>
```

---

## Fix 3: Reactivation Query Parameter (`restore=1`)

### File: `warehouse_finals/js/app.js`

```diff
--- a/warehouse_finals/js/app.js
+++ b/warehouse_finals/js/app.js
@@ -863,1 +863,1 @@ async function toggleStatus(entity, id, newStatus) {
-  const url = API + endpoint + (endpoint.includes('?') ? '&' : '?') + 'id=' + id + '&action=' + (newStatus === 1 ? 'reactivate' : 'deactivate');
+  const url = API + endpoint + (endpoint.includes('?') ? '&' : '?') + 'id=' + id + (newStatus === 1 ? '&restore=1' : '');
```

---

## Fix 4: Role Table Column Misalignment

### File: `warehouse_finals/index.html` and `warehouse_finals/js/app.js`

In `index.html`:
```diff
--- a/warehouse_finals/index.html
+++ b/warehouse_finals/index.html
@@ -1022,1 +1022,0 @@
-    <td class="row-desc"></td>
```

In `js/app.js`:
```diff
--- a/warehouse_finals/js/app.js
+++ b/warehouse_finals/js/app.js
@@ -665,1 +665,0 @@ function renderRoles(rows) {
-    clone.querySelector('.row-desc').textContent = r.description || '—';
```
