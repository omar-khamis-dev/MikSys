<?php
require('routeros-api-master/routeros_api.class.php');

// إعداد الاتصال بالمايكروتك
$RouterOS = new RouterosAPI();
$RouterOS->debug = false;

if ($RouterOS->connect('1.1.1.1', 'admin', 'Freedom2020', 8728)) {

    // الاتصال بقاعدة SQLite
    $db = new PDO('sqlite:database.db');

    // جلب الباقات من User Manager
    $RouterOS->write('/tool/user-manager/profile/print');
    $profiles = $RouterOS->read();

    foreach ($profiles as $profile) {
        $name = isset($profile['name']) ? $profile['name'] : '';
        $validity = isset($profile['validity']) ? $profile['validity'] : '';
        $price = isset($profile['price']) ? floatval($profile['price']) : 0;
        $rate_limit = isset($profile['rate-limit']) ? $profile['rate-limit'] : '';
        $data_quota = isset($profile['data-limit']) ? intval($profile['data-limit']) : 0;

        // تحويل مدة الصلاحية إلى أيام (مثال: "30d" -> 30)
        $validity_days = null;
        if (strpos($validity, 'd') !== false) {
            $validity_days = intval(str_replace('d', '', $validity));
        }

        // إدخال البيانات في جدول profiles
        $stmt = $db->prepare("
            INSERT INTO profiles (name, type, rate_limit, price, validity_days, data_quota_mb)
            VALUES (:name, :type, :rate_limit, :price, :validity_days, :data_quota)
        ");

        $stmt->execute([
            ':name' => $name,
            ':type' => 'userman', // أو 'hotspot' حسب نوع الباقة
            ':rate_limit' => $rate_limit,
            ':price' => $price,
            ':validity_days' => $validity_days,
            ':data_quota' => $data_quota,
        ]);
    }

    $RouterOS->disconnect();
    echo "✅ تم استيراد الباقات بنجاح من User Manager إلى قاعدة البيانات SQLite";

} else {
    echo "❌ فشل الاتصال بالمايكروتك";
}
