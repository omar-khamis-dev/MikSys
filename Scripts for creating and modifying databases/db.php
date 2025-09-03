<?php
try {
    $db = new PDO('sqlite:database.db');

    // إنشاء الجداول
    $db->exec("
    CREATE TABLE IF NOT EXISTS routers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        address TEXT NOT NULL,
        port INTEGER DEFAULT 8728,
        username TEXT NOT NULL,
        password TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        type TEXT CHECK(type IN ('hotspot','userman')),
        rate_limit TEXT,
        price REAL,
        validity_days INTEGER,
        data_quota_mb INTEGER
    );

    CREATE TABLE IF NOT EXISTS templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        html TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS sales_points (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        location TEXT
    );

    CREATE TABLE IF NOT EXISTS batches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL,
        profile_id INTEGER,
        sales_point_id INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS vouchers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        batch_id INTEGER,
        username TEXT NOT NULL,
        password TEXT,
        status TEXT DEFAULT 'new',
        expires_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    ");

    echo "تم إنشاء الجداول بنجاح ✅";
} catch (PDOException $e) {
    echo "خطأ: " . $e->getMessage();
}
?>
