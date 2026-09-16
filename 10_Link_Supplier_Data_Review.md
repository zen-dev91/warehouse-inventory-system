
# Product ↔ Supplier ("Link Supplier") Data Review & Diagnosis

Connected to: [[00_Project_Overview_&_Graph_Hub]], [[01_Architecture_&_Data_Flow]], [[04_API_&_Schema_Mismatches]], [[05_Comprehensive_Fixes_&_Code_Diffs]], [[06_Modular_Frontend_Architecture]]

---

## 🔍 Executive Summary: Why Does It Show "No Data"?

When opening or working with **"Link Supplier"** (`Product ↔ Supplier`), users frequently encounter an empty view or report that *"there is no data there"*. 

A comprehensive investigation across the database, backend APIs, and frontend controllers revealed **three primary root causes** (along with live verification confirming that the database itself does contain valid data):

| Area | Issue Identified | Impact | Severity |
| :--- | :--- | :--- | :---: |
| **1. Modal UX Flow** | The modal table requires selecting a supplier first in Step 1. | When clicking **"Link Supplier"**, the table is intentionally blank (`Select a supplier to view products.`). Users think it's broken or empty. | 🟡 UX Friction |
| **2. Form Submit Reload Bug** | `<button type="submit" id="btnSaveLinks">` lacks `e.preventDefault()`. | Clicking **Save Links** triggers a native browser form reload, aborting the in-flight Axios POST. Any newly created links fail to save to the database! | 🔴 Critical Bug |
| **3. Modal Dropdown Initialization** | Modal opener does not re-populate suppliers if `cache.suppliers` wasn't loaded. | If the modal is opened before `loadLinks()` finishes, the supplier dropdown remains completely empty (`-- Select Supplier --`), making it impossible to select anything. | 🟠 High |

---

## 🗄️ Live Database & API Audit (Verified)

Direct inspection of the live MySQL database (`warehouse_inventory_db`) confirmed that the backend and database tables are healthy and populated:

```text
=== TABLE RECORD COUNTS ===
- product:          9 active items
- supplier:         6 active suppliers (including recent: Pitok Balota)
- product_supplier: 12 active links
```

### Current Active Product-Supplier Links in MySQL:
| Product ID | Product Name | Supplier ID | Supplier Name | Supplier Unit Cost (PHP) |
| :---: | :--- | :---: | :--- | :---: |
| **1** | Rubber Hex Dumbbell 10kg | 1 | IronCore Fitness Equipment | ₱2,300.00 |
| **2** | Olympic Barbell 20kg | 1 | IronCore Fitness Equipment | ₱5,400.00 |
| **2** | Olympic Barbell 20kg | 6 | Pitok Balota | ₱6,500.00 |
| **3** | Folding Treadmill | 3 | Northmin Cardio Systems | ₱20,000.00 |
| **3** | Folding Treadmill | 6 | Pitok Balota | ₱24,000.00 |
| **4** | Stationary Exercise Bike | 3 | Northmin Cardio Systems | ₱12,800.00 |
| **5** | Adjustable Weight Bench | 2 | PowerFlex Gym Supplies | ₱3,500.00 |
| **6** | Cable Crossover Machine | 2 | PowerFlex Gym Supplies | ₱47,000.00 |
| **6** | Cable Crossover Machine | 6 | Pitok Balota | ₱55,000.00 |
| **7** | Whey Protein Isolate 2lbs | 4 | PeakForm Nutrition Trading | ₱1,450.00 |
| **8** | Plant-Based Protein 2lbs | 4 | PeakForm Nutrition Trading | ₱1,550.00 |
| **9** | Pre-Workout Powder 300g | 5 | MaxGains Supplements Co. | ₱900.00 |

Both `GET api/product_supplier.php` (all links) and `GET api/product_supplier.php?supplier_id=1` (per-supplier lookup) return `HTTP 200` with complete JSON payloads.

---

## 🔬 Root Cause Breakdown

### Root Cause 1: Step-by-Step Dependent Modal Design
In `views/product_supplier.html`, the modal is designed with three sequential steps:
1. **Step 1:** Select Supplier (`<select id="link_supplier_id">`)
2. **Step 2:** Search Products (Optional)
3. **Step 3:** Select Products and Set Supplier Unit Cost (`<tbody id="linkProductList">`)

When a user clicks the dark **"Link Supplier"** button:
- The modal opens with **no supplier selected** (`-- Select Supplier --`).
- The product table body initially contains:
  ```html
  <tr><td colspan="3" class="text-muted fst-italic">Select a supplier to view products.</td></tr>
  ```
- Because the products only load upon triggering the `change` event of `#link_supplier_id`, opening the modal gives the impression that there is no data in the system.

---

### Root Cause 2: Native Form Submit Aborting Saves (Critical Bug)
In `views/product_supplier.html` (Line 144):
```html
<form id="linkForm">
  ...
  <button type="submit" class="btn btn-dark" id="btnSaveLinks">Save Links</button>
</form>
```
In `js/product_supplier.js` (Line 380 & Line 506):
```javascript
el = document.getElementById('btnSaveLinks');
if (el) el.addEventListener('click', saveBulkLinks);

async function saveBulkLinks() { ... } // Note: No 'e' parameter, no 'e.preventDefault()'
```
**The Failure Chain:**
1. The user checks products, sets prices, and clicks **"Save Links"**.
2. `saveBulkLinks()` begins executing and dispatches an asynchronous `axios.post()`.
3. Because the button is `type="submit"` and `<form id="linkForm">` has no `submit` listener calling `e.preventDefault()`, the browser immediately fires a standard HTML form submission.
4. The page reloads instantly (`index.php?` / `index.html?`), **canceling the pending HTTP request**.
5. As a result, the new supplier links are **never saved to the database**, and upon page reload, the table still shows no new data!

---

### Root Cause 3: Incomplete Modal Reset in `js/app.js`
In `js/app.js` (Line 436):
```javascript
} else if (modalId === 'linkModal') {
  document.getElementById('linkModalTitle').textContent = 'Link Products to Supplier';
  var linkSupSel = document.getElementById('link_supplier_id');
  if (linkSupSel) linkSupSel.value = '';
  ...
}
```
`resetModalForAdd('linkModal')` wipes the value of `link_supplier_id` and empties `#linkProductList`, but it **does not verify whether `cache.suppliers` has been populated**. If a user accesses the modal before the main `product_supplier` tab has loaded its API calls, the supplier dropdown has 0 options.

---

### Root Cause 4: Role-Based Access Restrictions (RBAC)
Under `config/permissions.php`:
- **Administrator**: Full view & manage access.
- **Purchasing Officer**: Full view & manage access.
- **Warehouse Staff**: View access only (cannot create or delete links).
- **Store Requester**: `view` = false. If logged in as Store Requester (e.g. `Fritz`), accessing the `product_supplier` API returns `403 Forbidden` and the tab is completely hidden.

---

## 🛠️ Recommended Remediations

### Fix 1: Prevent Form Reload on Save Links
In `views/product_supplier.html`, change `type="submit"` to `type="button"` on the save button:
```html
<!-- BEFORE -->
<button type="submit" class="btn btn-dark" id="btnSaveLinks">Save Links</button>

<!-- AFTER -->
<button type="button" class="btn btn-dark" id="btnSaveLinks">Save Links</button>
```
And in `js/product_supplier.js`, ensure `saveBulkLinks(e)` receives the event and calls `if (e) e.preventDefault();`.

### Fix 2: Auto-Load Products or Prompt Clearly in Modal
When `#linkModal` opens:
- If a supplier filter is already active on the main table, pre-select that supplier in `#link_supplier_id` and immediately trigger `loadProductsForSupplier()`.
- If no supplier is selected, display an alert banner:
  > *💡 Please select a supplier from the dropdown above to view all products and configure their costs.*

### Fix 3: Ensure Suppliers are Populated on Modal Open
In `js/product_supplier.js`, add a listener to ensure the dropdown always has options when the modal is launched:
```javascript
var modalEl = document.getElementById('linkModal');
if (modalEl) {
  modalEl.addEventListener('show.bs.modal', function () {
    populateModalSupplierDropdown();
  });
}
```

---

## 🔗 Related Knowledge Nodes
- [[00_Project_Overview_&_Graph_Hub|System Knowledge Hub]]
- [[01_Architecture_&_Data_Flow|System Architecture & Pipelines]]
- [[04_API_&_Schema_Mismatches|API, RBAC & Database Inconsistencies]]
- [[05_Comprehensive_Fixes_&_Code_Diffs|Engineering Solutions & Source Code Patches]]
- [[06_Modular_Frontend_Architecture|Modular 1-to-1 Frontend Architecture]]
