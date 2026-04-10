<?php
require_once __DIR__ . '/config.php';

function getDB() {
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');
    }
    return $db;
}

function initDB() {
    $db = getDB();

    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            full_name TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('admin','assistant')),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS service_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            icon TEXT DEFAULT '',
            color TEXT DEFAULT '#F38E21'
        );

        CREATE TABLE IF NOT EXISTS clients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            phone TEXT DEFAULT '',
            address TEXT DEFAULT '',
            notes TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS meters (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            service_type_id INTEGER NOT NULL,
            meter_number TEXT DEFAULT '',
            label TEXT NOT NULL,
            nopolice TEXT DEFAULT '',
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
            FOREIGN KEY (service_type_id) REFERENCES service_types(id)
        );

        CREATE TABLE IF NOT EXISTS invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            meter_id INTEGER NOT NULL,
            month INTEGER NOT NULL CHECK(month BETWEEN 1 AND 12),
            year INTEGER NOT NULL,
            amount REAL NOT NULL DEFAULT 0,
            is_paid INTEGER DEFAULT 0,
            paid_at DATETIME,
            notes TEXT DEFAULT '',
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (meter_id) REFERENCES meters(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id),
            UNIQUE(meter_id, month, year)
        );

        CREATE INDEX IF NOT EXISTS idx_meters_client ON meters(client_id);
        CREATE INDEX IF NOT EXISTS idx_invoices_meter ON invoices(meter_id);
        CREATE INDEX IF NOT EXISTS idx_invoices_period ON invoices(year, month);
    ");

    // Insert default service types if empty
    $count = $db->query("SELECT COUNT(*) FROM service_types")->fetchColumn();
    if ($count == 0) {
        $services = [
            ['Electricite', 'fa-bolt', '#f59e0b'],
            ['Eau', 'fa-droplet', '#3b82f6'],
            ['Internet', 'fa-wifi', '#8b5cf6'],
            ['Telephone Mobile', 'fa-mobile-screen', '#10b981'],
            ['Telephone Fixe', 'fa-phone', '#6b7280'],
            ['Autre', 'fa-file-invoice', '#F38E21']
        ];
        $stmt = $db->prepare("INSERT INTO service_types (name, icon, color) VALUES (?, ?, ?)");
        foreach ($services as $s) {
            $stmt->execute($s);
        }
    }

    // Create default admin if no users exist
    $count = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count == 0) {
        $stmt = $db->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)");
        $stmt->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'Administrateur', 'admin']);
    }
}

initDB();
