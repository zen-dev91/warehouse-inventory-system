# API, RBAC & Data Inconsistencies

Connected to: [[00_Project_Overview_&_Graph_Hub]], [[01_Architecture_&_Data_Flow]], [[05_Comprehensive_Fixes_&_Code_Diffs]]

---

## ⚠️ Issue 1: Reactivation / Restore Broken Across All Modules

- **Location**: `warehouse_finals/js/app.js` (Line 863) vs backend APIs (`category.php`, `product.php`, `supplier.php`, `role.php`, `users.php`)
- **Severity**: 🟡 Medium

### Root Cause
In `app.js`, `toggleStatus()` constructs the restore URL as:
```javascript
const url = API + endpoint + ... + '&action=' + (newStatus === 1 ? 'reactivate' : 'deactivate');
```
However, every backend `DELETE` handler expects:
```php
$restore = isset($_GET['restore']) && (int)$_GET['restore'] === 1;
```
Because `restore=1` is never passed in the query string, `$restore` evaluates to `false` and `$status` is set to `0`.

### Impact
When an administrator clicks **Reactivate** on an inactive record, the backend deactivates it again. It is structurally impossible to reactivate any category, product, supplier, role, or user from the user interface.

---

## ⚠️ Issue 2: Role Table Column Shift & Non-Existent Column

- **Location**: `warehouse_finals/index.html` (Lines 195, 1022) & `warehouse_finals/js/app.js` (Line 665)
- **Severity**: 🟡 Low-Medium

### Root Cause
- Table header has 3 columns: `Role Name`, `Status`, `Actions`.
- Row template `tmpl-row-role` defines 4 cells: `row-name`, `row-desc`, `row-status`, `row-actions`.
- The database table `role` does not have a `description` column.
- In `renderRoles()`, `clone.querySelector('.row-desc').textContent = r.description || '—'` inserts an extraneous column.

### Impact
Table headers and data columns are shifted and misaligned.

---

## ⚠️ Issue 3: Role Creation & Renaming Is Dead Code

- **Location**: `warehouse_finals/api/role.php` (Lines 47–56, 80–89)
- **Severity**: 🟡 Medium

### Root Cause
1. In `POST api/role.php`, role names are validated with `in_array($name, allowedRoleNames(), true)`. Only 4 role names (`Administrator`, `Warehouse Staff`, `Purchasing Officer`, `Store Requester`) are valid.
2. All 4 roles are pre-seeded in `database.sql`.
3. If a new custom role is submitted, it is rejected with 400. If one of the 4 roles is submitted, it conflicts with 409.
4. In `PUT api/role.php`, renaming is rejected with 409 if `role_name !== $name`.

### Impact
The "Add Role" and "Edit Role" modals in the UI are non-functional and will always return an error.

---

## ⚠️ Issue 4: Stock Receiving Rejects Partial Deliveries

- **Location**: `warehouse_finals/index.html` (Line 673) vs `warehouse_finals/api/stock_receiving.php` (Line 40)
- **Severity**: 🟡 Medium

### Root Cause
The UI modal states: *"Quantity received defaults to the ordered quantity — adjust for short/partial deliveries"*, and renders editable inputs.
However, `stock_receiving.php` strictly asserts:
```php
if (!isset($items[$pid]) || (int)$items[$pid] !== (int)$line['quantity_ordered']) {
    throw new RuntimeException('quantity mismatch');
}
```

### Impact
Any attempt to receive a partial quantity or short shipment throws a 409 Conflict (`Stock receiving failed: quantity mismatch`).

---

## ⚠️ Issue 5: Purchase Order UI Missing Actions & Controls

- **Location**: `warehouse_finals/js/app.js` (Lines 1265–1276) vs `warehouse_finals/index.html` (Line 1045) vs `api/purchase_order.php` (Line 80)
- **Severity**: 🟡 Medium

### Root Cause
`index.html` has an Actions column, but `renderPurchaseOrders()` never injects any action buttons into `.row-actions`.
The backend provides endpoints to:
1. Cancel pending purchase orders (`PUT api/purchase_order.php`).
2. Retrieve purchase order line item details (`GET api/purchase_order.php?mode=detail&po_id=`).

### Impact
Users cannot cancel pending purchase orders or view purchase order line items in the interface.

---

## ⚠️ Issue 6: Dashboard Low Stock Stat Calculation Flaw

- **Location**: `warehouse_finals/js/app.js` (Lines 266–273)
- **Severity**: 🟡 Low-Medium

### Root Cause
```javascript
function countLowStockCandidates(products) {
  if (!products) return 0;
  let total = 0;
  for (let i = 0; i < products.length; i++) {
    if (parseInt(products[i].reorder_level) === 0) total++;
  }
  return total;
}
```
The function counts products whose configured `reorder_level` is set to `0`, rather than comparing available inventory against the reorder threshold.

---

See comprehensive solutions in [[05_Comprehensive_Fixes_&_Code_Diffs]].
