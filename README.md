# Warehouse Inventory System — Security-Hardened Build

This build keeps the existing PHP/PDO + MySQL warehouse modules and Bootstrap UI, while adding server-side authentication, RBAC, CSRF protection, validation, atomic stock controls, audit logging, and login rate limiting.

## Important deployment note
## Existing database migration

Do **not** import the supplied `database.sql` into an existing production database because the original project schema intentionally starts with `DROP DATABASE`.

For an existing database, apply the equivalent security changes only after taking a backup:

```sql
CREATE TABLE IF NOT EXISTS audit_log (
    audit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    role VARCHAR(50) NULL,
    action VARCHAR(50) NOT NULL,
    module VARCHAR(50) NOT NULL,
    record_id INT NULL,
    description VARCHAR(1000) NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_module_action (module, action),
    INDEX idx_audit_created (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
    username VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NOT NULL,
    locked_until DATETIME NULL,
    PRIMARY KEY (username, ip_address),
    INDEX idx_login_locked (locked_until)
) ENGINE=InnoDB;

ALTER TABLE stock_receiving
    ADD UNIQUE KEY uq_stock_receiving_po (po_id);
```

If `stock_receiving` already contains duplicate `po_id` values, stop before the `ALTER TABLE` and reconcile those records first.

After confirming the four documented demo usernames are test-only accounts, remove them from the live database using a controlled, backed-up migration. I did not connect to or modify a live MySQL server from this review.


The supplied project contained four explicitly documented demo accounts. They are removed from `database.sql`; no demo/default user accounts are seeded.

The supplied archive cannot connect to or modify your live MySQL server. If those four usernames exist in your live database, confirm they are test accounts before removing them. Do not reuse any known demo password.

For a fresh installation, create the first real Administrator through your controlled deployment/bootstrap process using `password_hash()` and a private password. Do not place a known/default password in source control.

## Modules and workflows retained

- Categories
- Products
- Suppliers
- Product ↔ Supplier
- Roles
- Users
- Purchase Orders
- Stock Receiving
- Stock Releasing
- Purchase Returns
- Stocks Returns
- Adjustments

The existing Bootstrap layout and transaction workflows remain in place. Transaction ownership is now taken from the authenticated session rather than from browser-supplied user IDs.

## Server-side security controls

### Authentication

- PHP sessions use strict mode, cookie-only sessions, HttpOnly, SameSite=Lax, and Secure cookies when HTTPS is detected.
- Session ID is regenerated after successful login.
- Sessions expire after 30 minutes of inactivity.
- Logout clears the session and session cookie.
- Passwords use `password_hash()` / `password_verify()`.
- Minimum password length is 12 characters for account creation and administrator password resets.
- Login failures use the same generic message for unknown, inactive, and incorrect credentials.
- Repeated failures are rate-limited with a 15-minute temporary lock after 5 failures for the username/IP pair.

### Registration and users

- Public registration is removed from the login page and `auth.php?action=register` is rejected.
- Public role enumeration through `auth.php?action=roles` is removed.
- Only Administrator-authorized `api/users.php` requests can create users.
- Browser-supplied `role_id` is validated against the four fixed RBAC roles.
- Administrator role assignment is therefore only possible through the Administrator Users module.
- Password hashes are never returned by the Users API.
- The last active Administrator cannot be deactivated or reassigned away from Administrator.

### RBAC

The authoritative matrix remains in `config/permissions.php`.

Every API request checks the authenticated user's current database-backed role. Frontend hiding is only a usability feature; it is not the security boundary.

Roles:

- Administrator
- Warehouse Staff
- Purchasing Officer
- Store Requester

The Dashboard is now Administrator-only, matching the requested visibility rule.

### CSRF

Every POST, PUT, and DELETE API request requires the session CSRF token in `X-CSRF-Token`.

### Input validation

Server-side validation covers:

- Positive IDs
- Positive quantities
- Non-negative prices
- Non-negative reorder levels
- Valid ISO dates
- Required strings and maximum lengths
- Username format
- Contact number format
- Email format
- Fixed adjustment types
- Fixed purchase-order status transition

### Transaction ownership

The following transaction APIs ignore any browser-supplied `user_id` and use `$_SESSION['user_id']` through the authenticated session:

- Purchase orders
- Stock receiving
- Stock releasing
- Purchase returns
- Stocks returns
- Adjustments

### Inventory consistency

There is no separate inventory table in the supplied schema, so this build does not invent one.

Current stock is calculated from the existing transaction tables:

`receiving + stocks returns + adjustment additions - releases - purchase returns - adjustment deductions`

Product rows are locked inside inventory-changing transactions so concurrent release/return/adjustment operations cannot both pass a stale stock check.

Stock receiving:

- Locks the PO.
- Requires `Pending` status.
- Rejects a PO that has already been received.
- Requires the submitted products and quantities to match the pending PO exactly.
- Inserts receiving and detail rows and changes PO status to `Received` in one transaction.
- The database now has a unique constraint preventing duplicate receiving for a PO.

Stock releasing:

- Locks each affected product.
- Calculates current stock server-side.
- Rejects quantities greater than available stock.
- Writes the header/detail transaction atomically.

Purchase returns:

- Lock affected products.
- Reject quantities greater than available stock.
- Record the deduction atomically.

Stocks returns:

- Validate products and record the stock increase atomically.

Adjustments:

- Accept only `Addition` or `Deduction`.
- Deduction cannot exceed current stock.
- Record the authenticated operator.
- Update the transaction atomically.

## Database protections

Existing prepared statements and foreign keys are retained.

Added:

- `audit_log` table
- `login_attempts` table
- Unique `stock_receiving.po_id` constraint

The existing unique username constraint and product/supplier junction primary key remain.

## Error handling

Technical PHP/PDO exceptions are logged server-side. API clients receive only safe messages such as:

- `Request failed.`
- `Invalid username or password.`
- `You are not authorized to perform this action.`

SQL errors, paths, stack traces, credentials, and password hashes are not returned.

## Audit logging

`audit_log` records:

- user ID
- role
- action
- module
- record ID
- description
- IP address
- timestamp

Security-sensitive events and inventory operations are logged, including authentication, logout, user/role changes, password resets, account activation/deactivation, purchase orders, receiving, releasing, returns, and adjustments.

## Frontend changes

- Removed public registration UI.
- Removed the four demo-account credentials from the login page.
- Dashboard is visible only to Administrators.
- Transaction user selectors are populated with the currently authenticated user instead of allowing another employee to be selected.
- State-changing Axios requests automatically include the CSRF token.
- Unauthorized tabs/buttons remain hidden according to server-provided permissions.
- Direct calls to APIs are still rejected server-side.

## Files changed

- `config/db.php`
- `config/permissions.php`
- `api/auth.php`
- `api/users.php`
- `api/role.php`
- `api/category.php`
- `api/product.php`
- `api/supplier.php`
- `api/product_supplier.php`
- `api/purchase_order.php`
- `api/stock_receiving.php`
- `api/stock_releasing.php`
- `api/purchase_return.php`
- `api/stocks_return.php`
- `api/adjustment.php`
- `database.sql`
- `index.html`
- `js/app.js`
- `README.md`

No new application endpoint or unrelated module was introduced.

## Security testing checklist

### A. Unauthenticated

- [ ] GET every protected API endpoint → 401.
- [ ] POST/PUT/DELETE every protected API endpoint → 401 or CSRF 403.
- [ ] `auth.php?action=register` → 403.
- [ ] `auth.php?action=roles` → 403.
- [ ] No warehouse data is returned before login.

### B. Warehouse Staff

- [ ] Dashboard is hidden.
- [ ] Users and Roles are hidden.
- [ ] Direct Users API → 403.
- [ ] Direct Roles API → 403.
- [ ] User creation → 403.
- [ ] Role changes → 403.
- [ ] Password reset → 403.
- [ ] Allowed warehouse modules continue to work.

### C. Purchasing Officer

- [ ] Dashboard is hidden.
- [ ] Users/roles API → 403.
- [ ] User creation/role changes/password reset → 403.
- [ ] Purchasing modules work according to the RBAC matrix.
- [ ] Warehouse release/adjustment APIs → 403.

### D. Store Requester

- [ ] Dashboard is hidden.
- [ ] Users/roles APIs → 403.
- [ ] Warehouse management APIs → 403.
- [ ] Only the existing requester view functions are accessible.

### E. API bypass

Using browser DevTools, Postman, or curl:

- [ ] Remove/hide frontend buttons and call the API directly.
- [ ] Change `role_id`.
- [ ] Change `user_id`.
- [ ] Change product IDs.
- [ ] Change quantities.
- [ ] Change prices.
- [ ] Change status values.
- [ ] Change transaction IDs.

The server must independently reject invalid or unauthorized values.

### F. Inventory/concurrency

- [ ] Receive the same PO twice → second attempt rejected.
- [ ] Release more than current stock → rejected.
- [ ] Submit two concurrent releases that together exceed stock → at most one valid combination succeeds.
- [ ] Deduction adjustment below zero stock → rejected.
- [ ] Purchase return above stock → rejected.
- [ ] Failed multi-line transaction leaves no partial header/detail rows.

### G. Authentication

- [ ] Wrong username and wrong password produce the same failure message.
- [ ] Inactive account produces the same generic login failure.
- [ ] Five failed attempts trigger temporary lockout.
- [ ] Successful login changes the session ID.
- [ ] Logout invalidates the session.
- [ ] Session expires after inactivity.
- [ ] Cookies show HttpOnly and SameSite; Secure is present when HTTPS is used.

### H. Database/error exposure

- [ ] Force a duplicate username and confirm only `Request failed.` is returned.
- [ ] Trigger invalid SQL input and confirm no SQL/PHP details reach the browser.
- [ ] Confirm no password column appears in Users API JSON.
- [ ] Confirm audit records contain the authenticated operator for security-sensitive actions.

## First Administrator setup

The project includes a one-time **CLI-only** bootstrap script:

```bash
php setup/create_admin.php
```

Run it from the project root after importing the database and configuring the database credentials in `config/db.php`.

The script:

- is CLI-only and cannot be used as a web endpoint;
- refuses to run if any Administrator account already exists;
- asks for the first Administrator's name, username, optional contact number, and password;
- requires a password of at least 12 characters;
- asks for the password interactively instead of accepting it as a command-line argument;
- uses `password_hash()` with `PASSWORD_DEFAULT`;
- uses PDO prepared statements;
- creates an active Administrator only after confirming the fixed Administrator role exists;
- performs the creation inside a database transaction;
- re-checks for an existing Administrator while the transaction is running;
- never prints the password or password hash;
- does not create any demo account or known/default password.

### Example

```text
$ php setup/create_admin.php
First name: Alex
Last name: Santos
Username: alex.admin
Contact number (optional): +63 9XX XXX XXXX
Password (minimum 12 characters):
Confirm password:
Administrator account created successfully.
You may now log in through the normal login page.
```

**After the first Administrator has been created, the script is permanently disabled by the database state.** If you need another Administrator later, use the protected Admin > Users module.

For production, run this script directly on the server/CLI environment and remove or restrict access to the `setup/` directory after successful initialization. The application does not expose this script through the browser.
