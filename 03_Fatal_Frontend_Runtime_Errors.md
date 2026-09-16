# Fatal Frontend Runtime Errors

Connected to: [[00_Project_Overview_&_Graph_Hub]], [[01_Architecture_&_Data_Flow]], [[05_Comprehensive_Fixes_&_Code_Diffs]]

---

## 💥 Bug 1: Product Add & Edit DOM ID Mismatch Crash

- **Location**: `warehouse_finals/js/app.js` (Lines 436–438, 449–451) vs `warehouse_finals/index.html` (Lines 402–413)
- **Error**: `Uncaught TypeError: Cannot read properties of null (reading 'value')`
- **Severity**: 🟠 High (Blocks core functionality)

### Root Cause
In `index.html`, the inputs for unit measure, unit price, and reorder level are defined as:
```html
<input type="text" class="form-control" id="unit_measure" required>
<input type="number" class="form-control" id="unit_price" step="0.01" min="0" required>
<input type="number" class="form-control" id="reorder_level" min="0" required>
```
However, `app.js` queries non-existent element IDs prefixed with `product_`:
```javascript
// in editProduct():
document.getElementById('product_unit_measure').value = item.unit_measure;
document.getElementById('product_unit_price').value = item.unit_price;
document.getElementById('product_reorder_level').value = item.reorder_level;

// in saveProduct():
unit_measure: document.getElementById('product_unit_measure').value.trim(),
unit_price: parseFloat(document.getElementById('product_unit_price').value),
reorder_level: parseInt(document.getElementById('product_reorder_level').value)
```
### Result
Every time a user clicks **Add Product** or **Edit Product**, JavaScript throws a TypeError on null and execution halts. No products can be created or edited.

---

## 💥 Bug 2: Supplier Add & Edit DOM ID Mismatch Crash

- **Location**: `warehouse_finals/js/app.js` (Lines 513–515, 525–527) vs `warehouse_finals/index.html` (Lines 440–454)
- **Error**: `Uncaught TypeError: Cannot read properties of null (reading 'value')`
- **Severity**: 🟠 High (Blocks core functionality)

### Root Cause
In `index.html`, supplier modal inputs use:
- `id="contact_person"`
- `id="phone"`
- `id="email"`
- `id="address"`

However, `app.js` queries:
- `document.getElementById('supplier_contact_person')`
- `document.getElementById('supplier_phone')`
- `document.getElementById('supplier_email')`
And completely forgets to submit the `address` field!

### Result
Clicking **Add Supplier** or **Edit Supplier** immediately crashes with a null reference exception.

---

## 💥 Bug 3: Product ↔ Supplier Link Crash & Field Mismatch

- **Location**: `warehouse_finals/js/app.js` (Lines 589, 611) vs `warehouse_finals/index.html` (Line 485) vs `warehouse_finals/api/product_supplier.php`
- **Error**: `Uncaught TypeError: Cannot read properties of null (reading 'value')`
- **Severity**: 🟠 High (Blocks core functionality)

### Root Cause
1. `index.html` specifies `<input type="number" id="supplier_unit_cost">`.
2. `app.js` calls:
   ```javascript
   notes: document.getElementById('link_notes').value.trim()
   ```
   Element `link_notes` does not exist in the DOM, throwing a fatal null error.
3. In `renderLinks()`, `app.js` looks for `.row-notes` instead of formatting `supplier_unit_cost` via `peso()`.

### Result
Creating any Product-to-Supplier linkage crashes the browser script. Even if submitted, costs are not recorded or displayed.

---

See full code resolutions in [[05_Comprehensive_Fixes_&_Code_Diffs#fix-2-frontend-dom-id-mismatches]].
