<?php
try {
    $db = new PDO('sqlite:database.db');

    // إضافة العمود html_code
    $db->exec("ALTER TABLE templates ADD COLUMN html_code TEXT;");

    echo "✅ تم إضافة العمود html_code إلى جدول templates بنجاح";
} catch (PDOException $e) {
    echo "خطأ: " . $e->getMessage();
}
?>
