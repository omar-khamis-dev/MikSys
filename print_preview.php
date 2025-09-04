<?php
require('routeros-api-master/routeros_api.class.php');
$db = new PDO("sqlite:database.db");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// استقبال البيانات من create_vouchers.php
$template_id   = $_POST['template_id'] ?? null;
$profile_name  = $_POST['profile'] ?? null;
$pos           = $_POST['pos'] ?? null;
$voucher_count = intval($_POST['count'] ?? 0);
$password_mode = $_POST['password_mode'] ?? 'random';
$username_mode = $_POST['username_mode'] ?? 'random';
$username_prefix = $_POST['username_prefix'] ?? '';
$username_suffix = $_POST['username_suffix'] ?? '';
$password_length = intval($_POST['password_length'] ?? 6);

// جلب القالب
$stmt = $db->prepare("SELECT html FROM templates WHERE id = :id");
$stmt->execute([':id' => $template_id]);
$template_html = $stmt->fetchColumn();
if (!$template_html) {
    die($template_id . " ❌ لم يتم العثور على القالب");
}

// توليد batch_id جديد
$batch_id = time();

// إعداد الاتصال مع المايكروتك
$API = new RouterosAPI();
$API->debug = false;
$router_ip   = "1.1.1.1";
$router_user = "admin";
$router_pass = "Freedom2020";

$vouchers = [];

// توليد بيانات عشوائية للكروت
function generateRandomString($length = 6) {
    $chars = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    return substr(str_shuffle(str_repeat($chars, $length)), 0, $length);
}

for ($i = 0; $i < $voucher_count; $i++) {
    // توليد اسم المستخدم
    if ($username_mode === 'random') {
        $username = $username_prefix . generateRandomString(6) . $username_suffix;
    } else {
        $username = $username_prefix . ($i + 1) . $username_suffix;
    }

    // توليد كلمة المرور
    if ($password_mode === 'random') {
        $password = generateRandomString($password_length);
    } else {
        $password = $username; // إذا الوضع "مثل اسم المستخدم"
    }

    $vouchers[] = [
        'username' => $username,
        'password' => $password,
        'profile'  => $profile_name,
        'pos'      => $pos,
    ];
}

// رفع الكروت إلى المايكروتك
if ($API->connect($router_ip, $router_user, $router_pass)) {
    foreach ($vouchers as $voucher) {
        $API->comm("/tool/user-manager/user/add", [
            "customer" => "admin",
            "username" => $voucher['username'],
            "password" => $voucher['password'],
            "shared-users" => 1,
            "copy-from" => $voucher['profile']
        ]);
    }
    $API->disconnect();
}

// حفظ الكروت في SQLite
$insert = $db->prepare("
    INSERT INTO vouchers (batch_id, username, password, profile, pos, template_id, status, created_at) 
    VALUES (:batch_id, :username, :password, :profile, :pos, :template_id, 'new', datetime('now'))
");

foreach ($vouchers as $voucher) {
    $insert->execute([
        ':batch_id'    => $batch_id,
        ':username'    => $voucher['username'],
        ':password'    => $voucher['password'],
        ':profile'     => $voucher['profile'],
        ':pos'         => $voucher['pos'],
        ':template_id' => $template_id
    ]);
}
?>

<!DOCTYPE html>
<html lang="ar">
<head>
  <meta charset="UTF-8">
  <title>معاينة الطباعة</title>
  <style>
    .print-area {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 5px;
      width: 210mm;
      margin: auto;
    }
    .voucher {
      width: 50mm;
      height: 25mm;
      position: relative;
      border: 1px solid #ccc;
      overflow: hidden;
    }
  </style>
</head>
<body>
  <h2 style="text-align:center">📋 معاينة الكروت قبل الطباعة</h2>
  <div class="print-area">
    <?php foreach ($vouchers as $v): ?>
      <?php
      $html = str_replace(
          ["{username}", "{password}", "{profile}", "{pos}"],
          [htmlspecialchars($v['username']), htmlspecialchars($v['password']), htmlspecialchars($v['profile']), htmlspecialchars($v['pos'])],
          $template_html
      );
      ?>
      <div class="voucher"><?= $html ?></div>
    <?php endforeach; ?>
  </div>

  <div style="text-align:center; margin:20px;">
    <button onclick="window.print()">🖨️ طباعة</button>
  </div>
</body>
</html>
