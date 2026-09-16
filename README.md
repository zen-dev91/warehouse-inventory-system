# 📦 Warehouse Inventory Management System

A robust, enterprise-ready Warehouse Management System (WMS) built with **PHP (OOP, PDO)**, **MySQL**, **Bootstrap 5.3**, and **JavaScript (Axios)**. Designed for high efficiency in tracking inventory, supplier relationships, stock movements, and staff access control.

---

## ✨ Key Features

* **🛡️ Granular Role-Based Access Control (RBAC):** Modular permission system (`view` & `manage`) across all modules with real-time UI synchronization and soft role deactivation.
* **📱 Fully Responsive Mobile UI:** Built with Bootstrap 5.3 native utilities and offcanvas navigation drawers for full-height mobile usability.
* **🔗 Multi-Vendor Product Mapping:** Dynamically link products to multiple suppliers with custom unit costs and pagination.
* **📋 End-to-End Transaction Pipelines:**
  * **Purchase Orders:** Supplier ordering pipeline with live status tracking.
  * **Stock Receiving & Releasing:** Atomic delivery intake and internal department requisitions.
  * **Returns & Adjustments:** Purchase returns to vendors, customer/department returns, and inventory adjustments.
* **🔒 Enterprise Security:** 100% PDO prepared statements with parameter binding, CSRF token validation, and secure session management.
* **⚡ Modern Frontend UX:** Async/await API calls using Axios and interactive SweetAlert2 dialogs with zero custom CSS bloat.

---

## 🛠️ Tech Stack

* **Backend:** PHP 8 (OOP, PDO, Prepared Statements)
* **Database:** MySQL / MariaDB
* **Frontend:** HTML5, Bootstrap 5.3, Bootstrap Icons
* **Client-Side:** JavaScript (ES6+), Axios, SweetAlert2
* **Server:** Apache (XAMPP / LAMP)
