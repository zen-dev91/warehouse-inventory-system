-- ============================================================
-- Gym Equipment & Supplements Inventory — Database
-- Milestone 1 (Master Files) + Transaction Modules
-- Table names are lowercase to match every PHP file exactly.
-- Import this whole file in phpMyAdmin (XAMPP) or via mysql CLI.
-- ============================================================

DROP DATABASE IF EXISTS warehouse_inventory_db;
CREATE DATABASE warehouse_inventory_db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE warehouse_inventory_db;

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- MASTER / LOOKUP TABLES
-- ============================================================

CREATE TABLE role (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    contact_number VARCHAR(20),
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES role(role_id)
) ENGINE=InnoDB;

CREATE TABLE category (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255),
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE product (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    product_name VARCHAR(150) NOT NULL,
    unit_measure VARCHAR(30) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    reorder_level INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_category FOREIGN KEY (category_id) REFERENCES category(category_id)
) ENGINE=InnoDB;

CREATE TABLE supplier (
    supplier_id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(100),
    phone VARCHAR(20),
    email VARCHAR(100),
    address VARCHAR(255),
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE product_supplier (
    product_id INT NOT NULL,
    supplier_id INT NOT NULL,
    supplier_unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (product_id, supplier_id),
    CONSTRAINT fk_ps_product FOREIGN KEY (product_id) REFERENCES product(product_id),
    CONSTRAINT fk_ps_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(supplier_id)
) ENGINE=InnoDB;

-- ============================================================
-- TRANSACTION TABLES
-- ============================================================

CREATE TABLE purchase_order (
    po_id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    user_id INT NOT NULL,
    order_date DATE NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Pending',
    CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(supplier_id),
    CONSTRAINT fk_po_user FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE purchase_order_detail (
    po_detail_id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity_ordered INT NOT NULL,
    unit_cost DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_pod_po FOREIGN KEY (po_id) REFERENCES purchase_order(po_id),
    CONSTRAINT fk_pod_product FOREIGN KEY (product_id) REFERENCES product(product_id)
) ENGINE=InnoDB;

CREATE TABLE stock_receiving (
    receiving_id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    user_id INT NOT NULL,
    receiving_date DATE NOT NULL,
    UNIQUE KEY uq_stock_receiving_po (po_id),
    CONSTRAINT fk_recv_po FOREIGN KEY (po_id) REFERENCES purchase_order(po_id),
    CONSTRAINT fk_recv_user FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE stock_receiving_detail (
    receiving_detail_id INT AUTO_INCREMENT PRIMARY KEY,
    receiving_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity_received INT NOT NULL,
    CONSTRAINT fk_recvd_receiving FOREIGN KEY (receiving_id) REFERENCES stock_receiving(receiving_id),
    CONSTRAINT fk_recvd_product FOREIGN KEY (product_id) REFERENCES product(product_id)
) ENGINE=InnoDB;

CREATE TABLE stock_releasing (
    releasing_id INT AUTO_INCREMENT PRIMARY KEY,
    requested_by VARCHAR(100),
    user_id INT NOT NULL,
    releasing_date DATE NOT NULL,
    CONSTRAINT fk_rel_user FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE stock_releasing_detail (
    releasing_detail_id INT AUTO_INCREMENT PRIMARY KEY,
    releasing_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity_released INT NOT NULL,
    CONSTRAINT fk_reld_releasing FOREIGN KEY (releasing_id) REFERENCES stock_releasing(releasing_id),
    CONSTRAINT fk_reld_product FOREIGN KEY (product_id) REFERENCES product(product_id)
) ENGINE=InnoDB;

CREATE TABLE purchase_return (
    purchase_return_id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    user_id INT NOT NULL,
    return_date DATE NOT NULL,
    reason VARCHAR(255),
    CONSTRAINT fk_pret_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(supplier_id),
    CONSTRAINT fk_pret_user FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE purchase_return_detail (
    purchase_return_detail_id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_return_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity_returned INT NOT NULL,
    CONSTRAINT fk_pretd_return FOREIGN KEY (purchase_return_id) REFERENCES purchase_return(purchase_return_id),
    CONSTRAINT fk_pretd_product FOREIGN KEY (product_id) REFERENCES product(product_id)
) ENGINE=InnoDB;

CREATE TABLE stocks_return (
    stocks_return_id INT AUTO_INCREMENT PRIMARY KEY,
    requested_by VARCHAR(100),
    user_id INT NOT NULL,
    return_date DATE NOT NULL,
    reason VARCHAR(255),
    CONSTRAINT fk_sret_user FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE stocks_return_detail (
    stocks_return_detail_id INT AUTO_INCREMENT PRIMARY KEY,
    stocks_return_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity_returned INT NOT NULL,
    CONSTRAINT fk_sretd_return FOREIGN KEY (stocks_return_id) REFERENCES stocks_return(stocks_return_id),
    CONSTRAINT fk_sretd_product FOREIGN KEY (product_id) REFERENCES product(product_id)
) ENGINE=InnoDB;

CREATE TABLE adjustment_detail (
    adjustment_detail_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    adjustment_type VARCHAR(20) NOT NULL,
    CONSTRAINT fk_adjd_product FOREIGN KEY (product_id) REFERENCES product(product_id)
) ENGINE=InnoDB;


-- ============================================================
-- SECURITY TABLES
-- ============================================================

CREATE TABLE audit_log (
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

CREATE TABLE login_attempts (
    username VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NOT NULL,
    locked_until DATETIME NULL,
    PRIMARY KEY (username, ip_address),
    INDEX idx_login_locked (locked_until)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SAMPLE DATA — Master tables only
-- Theme: Gym Equipment & Supplements Inventory
-- ============================================================

INSERT INTO role (role_name) VALUES
('Administrator'),
('Warehouse Staff'),
('Purchasing Officer'),
('Store Requester');

-- No demo or default user accounts are seeded. Create the first real Administrator
-- through a controlled deployment/bootstrap process using password_hash().

INSERT INTO category (category_name, description) VALUES
('Free Weights', 'Dumbbells, barbells, plates, and kettlebells'),
('Cardio Equipment', 'Treadmills, exercise bikes, and rowing machines'),
('Strength Machines', 'Cable towers, benches, and resistance machines'),
('Protein Supplements', 'Whey, casein, and plant-based protein powders'),
('Pre/Post Workout Supplements', 'Pre-workout boosters, BCAAs, and recovery formulas');

INSERT INTO product (category_id, product_name, unit_measure, unit_price, reorder_level) VALUES
(1, 'Rubber Hex Dumbbell 10kg', 'Pair', 2800.00, 15),
(1, 'Olympic Barbell 20kg', 'Piece', 6500.00, 8),
(2, 'Folding Treadmill', 'Unit', 24000.00, 5),
(2, 'Stationary Exercise Bike', 'Unit', 15500.00, 5),
(3, 'Adjustable Weight Bench', 'Piece', 4200.00, 10),
(3, 'Cable Crossover Machine', 'Unit', 55000.00, 3),
(4, 'Whey Protein Isolate 2lbs', 'Tub', 1850.00, 40),
(4, 'Plant-Based Protein 2lbs', 'Tub', 1950.00, 30),
(5, 'Pre-Workout Powder 300g', 'Jar', 1200.00, 35);

INSERT INTO supplier (supplier_name, contact_person, phone, email, address) VALUES
('IronCore Fitness Equipment', 'Liza Cortez', '088-123-4567', 'liza@ironcorefit.com', 'Corrales Ave, Cagayan de Oro City'),
('PowerFlex Gym Supplies', 'Mark Uy', '088-234-5678', 'mark@powerflexgym.com', 'Divisoria, Cagayan de Oro City'),
('Northmin Cardio Systems', 'Grace Tan', '088-345-6789', 'grace@northmincardio.com', 'Limketkai Center, Cagayan de Oro City'),
('PeakForm Nutrition Trading', 'Ramon Uy Jr.', '088-456-7890', 'ramon@peakformnutrition.com', 'Bulua, Cagayan de Oro City'),
('MaxGains Supplements Co.', 'Nena Villamor', '088-567-8901', 'nena@maxgainssupps.com', 'Carmen, Cagayan de Oro City');

INSERT INTO product_supplier (product_id, supplier_id, supplier_unit_cost) VALUES
(1, 1, 2300.00),
(2, 1, 5400.00),
(3, 3, 20000.00),
(4, 3, 12800.00),
(5, 2, 3500.00),
(6, 2, 47000.00),
(7, 4, 1450.00),
(8, 4, 1550.00),
(9, 5, 900.00);
