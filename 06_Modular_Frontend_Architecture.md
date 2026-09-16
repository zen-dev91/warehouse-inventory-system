# Modular 1-to-1 Frontend Architecture

Connected to: [[00_Project_Overview_&_Graph_Hub]], [[01_Architecture_&_Data_Flow]], [[05_Comprehensive_Fixes_&_Code_Diffs]]

---

## 🎯 Modular Architecture Design

To make finding feature functions effortless, the monolithic `index.html` and `js/app.js` have been decomposed into dedicated, modular files with exact 1-to-1 correspondence with the backend API endpoints in `warehouse_finals/api/`.

```
warehouse_finals/
├── api/                   # Backend REST Endpoints (PHP PDO)
│   ├── adjustment.php
│   ├── auth.php
│   ├── category.php
│   ├── product.php
│   ├── product_supplier.php
│   ├── purchase_order.php
│   ├── purchase_return.php
│   ├── role.php
│   ├── stock_receiving.php
│   ├── stock_releasing.php
│   ├── stocks_return.php
│   ├── supplier.php
│   └── users.php
├── js/                    # Frontend Controller Modules (Vanilla JS + Axios)
│   ├── adjustment.js
│   ├── app.js             (Core coordinator: auth, session, modals, toasts, tab router)
│   ├── category.js
│   ├── dashboard.js
│   ├── product.js
│   ├── product_supplier.js
│   ├── purchase_order.js
│   ├── purchase_return.js
│   ├── role.js
│   ├── stock_receiving.js
│   ├── stock_releasing.js
│   ├── stocks_return.js
│   ├── supplier.js
│   └── users.js
├── views/                 # Modular HTML Views (Panel markup, Modals, Templates)
│   ├── adjustment.html
│   ├── auth.html
│   ├── category.html
│   ├── common_templates.html
│   ├── dashboard.html
│   ├── product.html
│   ├── product_supplier.html
│   ├── purchase_order.html
│   ├── purchase_return.html
│   ├── role.html
│   ├── sidebar.html
│   ├── stock_receiving.html
│   ├── stock_releasing.html
│   ├── stocks_return.html
│   ├── supplier.html
│   └── users.html
├── index.php              # Apache default entry: includes views/ and loads modular js/
└── index.html             # Static client fallback with modular js/ scripts
```

---

## 🗺️ 1-to-1 Component Mapping Matrix

| Feature | Backend Endpoint | Frontend Controller | HTML View / Templates | Panel ID | Modal ID |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Authentication** | `api/auth.php` | `js/app.js` | `views/auth.html` | `#authScreen` | — |
| **Dashboard** | `api/*.php` | `js/dashboard.js` | `views/dashboard.html` | `#panel-dashboard` | — |
| **Categories** | `api/category.php` | `js/category.js` | `views/category.html` | `#panel-category` | `#categoryModal` |
| **Products** | `api/product.php` | `js/product.js` | `views/product.html` | `#panel-product` | `#productModal` |
| **Suppliers** | `api/supplier.php` | `js/supplier.js` | `views/supplier.html` | `#panel-supplier` | `#supplierModal` |
| **Product ↔ Supplier** | `api/product_supplier.php` | `js/product_supplier.js` | `views/product_supplier.html` | `#panel-product_supplier` | `#linkModal` |
| **Roles (RBAC)** | `api/role.php` | `js/role.js` | `views/role.html` | `#panel-role` | `#roleModal` |
| **Users** | `api/users.php` | `js/users.js` | `views/users.html` | `#panel-users` | `#userModal`, `#resetPwdModal` |
| **Purchase Orders** | `api/purchase_order.php` | `js/purchase_order.js` | `views/purchase_order.html` | `#panel-purchase_order` | `#poModal` |
| **Stock Receiving** | `api/stock_receiving.php` | `js/stock_receiving.js` | `views/stock_receiving.html` | `#panel-stock_receiving` | `#receivingModal` |
| **Stock Releasing** | `api/stock_releasing.php` | `js/stock_releasing.js` | `views/stock_releasing.html` | `#panel-stock_releasing` | `#releasingModal` |
| **Purchase Returns** | `api/purchase_return.php` | `js/purchase_return.js` | `views/purchase_return.html` | `#panel-purchase_return` | `#preturnModal` |
| **Stocks Return** | `api/stocks_return.php` | `js/stocks_return.js` | `views/stocks_return.html` | `#panel-stocks_return` | `#sreturnModal` |
| **Stock Adjustments** | `api/adjustment.php` | `js/adjustment.js` | `views/adjustment.html` | `#panel-adjustment` | `#adjustmentModal` |
| **Common UI / Badges**| — | `js/app.js` | `views/common_templates.html` | `#appShell` | `#modalTrigger` |

---

## 🔒 Coding Rules & Method Invariants Preserved

1. **Strict HTML Templates**: Zero Bootstrap classes are injected or created via JavaScript strings. All badges, buttons, modals, and row structures reside in `<template>` tags inside `views/*.html`.
2. **Declarative Modal Management**: Modals are controlled exclusively via HTML data attributes and declarative `#modalTrigger` clicks—zero Bootstrap JavaScript API objects (`new bootstrap.Modal()`).
3. **Async / Await with Axios**: All HTTP queries use `async`/`await` (no `.then()` / `.catch()` Promise chains).
4. **Status-Code Driven Flow Control**: Responses are routed using `switch (res.status)` (no `try`/`catch` blocks in JS logic).
5. **Secure Database Access**: All PHP backend queries continue to use PDO prepared statements with `:param` and strict `bindParam` or typed execution.
