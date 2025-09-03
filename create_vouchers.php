<?php
require('routeros-api-master/routeros_api.class.php');

$API = new RouterosAPI();
$API->debug = false;

$router_ip   = "1.1.1.1";
$router_user = "admin";
$router_pass = "Freedom2020";

// الاتصال بقاعدة SQLite
$db = new PDO('sqlite:database.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// إنشاء جدول البروفايلات إذا ما كان موجود
$db->exec("CREATE TABLE IF NOT EXISTS profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE
)");

$profiles_from_router = [];

if ($API->connect($router_ip, $router_user, $router_pass)) {
    // جلب بروفايلات الهوتسبوت
    $profiless = $API->comm("/tool/user-manager/profile/print");

    foreach ($profiless as $profile) {
        $name = $profile['name'];
        $profiles_from_router[] = $name;

        // حفظ البروفايل في SQLite إذا مش موجود
        $stmt = $db->prepare("INSERT OR IGNORE INTO profiles (name) VALUES (:name)");
        $stmt->execute([':name' => $name]);
    }

    $API->disconnect();
} else {
    // إذا المايكروتك مش متصل، نستخدم النسخة المحلية
    $profiles_local = $db->query("SELECT name FROM profiles")->fetchAll(PDO::FETCH_COLUMN);
    $profiles_from_router = $profiles_local;
}

// جلب البيانات من الجداول لعرضها في القوائم المنسدلة
// $profiles = $db->query("SELECT * FROM profiles")->fetchAll(PDO::FETCH_ASSOC) ?? $ff;
$templates = $db->query("SELECT * FROM templates")->fetchAll(PDO::FETCH_ASSOC);
$salesPoints = $db->query("SELECT * FROM sales_points")->fetchAll(PDO::FETCH_ASSOC);

// دالة توليد أسماء عشوائية


function randomString($mode = 'alnum', $length = 6)
{
    $chars = '';
    if ($mode == 'numeric')
        $chars = '0123456789';
    elseif ($mode == 'alpha')
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    else
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    return substr(str_shuffle(str_repeat($chars, ceil($length / strlen($chars)))), 0, $length);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $profileId = $_POST['profile'];
    $templateId = $_POST['template'];
    $salesPointId = $_POST['sales_point'] ?? 1;
    $cardMode = $_POST['card_mode']; // username أو userpass
    $userMode = $_POST['user_mode'];
    $passMode = $_POST['pass_mode'];
    $count = (int) $_POST['count'];
    $prefix = $_POST['prefix'];
    $suffix = $_POST['suffix'];
    $bindFirst = isset($_POST['bind_first']) ? 1 : 0;
    $length = (int) $_POST['length']; // ✅ طول الكرت

    // إنشاء دفعة جديدة
    $batchCode = strtoupper(substr(md5(time()), 0, 6));
    $stmt = $db->prepare("INSERT INTO batches (code, profile_id, template_id, sales_point_id) VALUES (?, ?, ?, ?)");
    $stmt->execute([$batchCode, $profileId, $templateId, $salesPointId]);
    $batchId = $db->lastInsertId();

    // إنشاء الكروت
    for ($i = 0; $i < $count; $i++) {
        $username = $prefix . randomString($userMode, $length) . $suffix;
        $password = ($cardMode == 'userpass') ? randomString($passMode, $length) : '';
        $stmt = $db->prepare("INSERT INTO vouchers (batch_id, profile_id, username, password) VALUES (?, ?, ?, ?)");
        $stmt->execute([$batchId, $profileId, $username, $password]);
    }

    if (isset($_POST['preview_design'])) {
    // بيانات الكروت المؤقتة
    $count  = $_POST['count'] ?? 5;
    $prefix = $_POST['prefix'] ?? '';
    $suffix = $_POST['suffix'] ?? '';
    $length = $_POST['length'] ?? 6;
    $cardMode = $_POST['card_mode'] ?? 'username';
    $userMode = $_POST['user_mode'] ?? 'alnum';
    $passMode = $_POST['pass_mode'] ?? 'alnum';
    $profileId = $_POST['profile'] ?? 0;
    $templateId = $_POST['template'] ?? 0;

    // جلب اسم الباقة والقالب
    $profileName = $profiles_from_router;
    $template = $db->query("SELECT html FROM templates WHERE id=$templateId")->fetchColumn() ?? "<div class='card'><h3>{username}</h3><p>{password}</p><p>{profile}</p></div>";

    $vouchers = [];
    for ($i=0; $i < $count; $i++) {
        $username = $prefix . randomString($userMode, $length) . $suffix;
        $password = ($cardMode == 'userpass') ? randomString($passMode, $length) : '';
        $vouchers[] = [
            'username' => $username,
            'password' => $password,
            'profile_name' => $profileName
        ];
    }

    // تخزين مؤقت في session للمعاينة
    session_start();
    $_SESSION['preview_vouchers'] = $vouchers;
    $_SESSION['preview_template'] = $template;

    // فتح صفحة معاينة الطباعة
    header("Location: print_preview.php?preview=1");
    exit;
}

    // ✅ تحويل مباشر لصفحة معاينة الطباعة
    header("Location: print_preview.php?batch=$batchId");

    require('routeros_api.class.php'); // ملف مكتبة RouterOS API

    $API = new RouterosAPI();
    $API->debug = false;

    $router_ip = "1.1.1.1";
    $router_user = "admin";
    $router_pass = "Freedom2020";

    // محاولة الاتصال بالمايكروتك
    if ($API->connect($router_ip, $router_user, $router_pass)) {

        // جلب الكروت التي أنشئت للتو من جدول vouchers
        $stmt = $db->prepare("SELECT * FROM vouchers WHERE batch_id = ?");
        $stmt->execute([$batchId]);
        $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($vouchers as $v) {
            $params = [
                "username" => $v['username'],
            ];

            if (!empty($v['password'])) {
                $params["password"] = $v['password'];
            }

            // ربط الباقة (Profile) مع المستخدم
            $params["profile"] = $profileId;

            // خيار الربط بأول جهاز
            if ($bindFirst) {
                $params["shared-users"] = 1;
            }

            // إضافة المستخدم للـ User Manager
            $API->comm("/tool/user-manager/user/add", $params);
        }

        $API->disconnect();
        echo "<p style='color:blue;'>✅ تم رفع الكروت إلى المايكروتك User Manager</p>";

    } else {
        echo "<p style='color:red;'>❌ فشل الاتصال بالمايكروتك، تم حفظ الكروت محليًا فقط</p>";
    }
    exit;

}

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title>إنشاء كروت اليوزر منجر</title>
    <style>
        body {
            font-family: Tahoma, sans-serif;
            padding: 20px;
            background: #f8f9fa;
        }

        form {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            width: 600px;
            margin: auto;
        }

        label {
            display: block;
            margin-top: 10px;
            font-weight: bold;
        }

        select,
        input {
            width: 100%;
            padding: 8px;
            margin-top: 5px;
        }

        button {
            margin-top: 15px;
            padding: 10px 15px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }

        .btn-main {
            background: #007bff;
            color: #fff;
        }

        .btn-alt {
            background: #6c757d;
            color: #fff;
        }

        .btns {
            display: flex;
            gap: 10px;
        }
    </style>
</head>

<body>

    <h2 style="text-align:center;">إنشاء كروت (User Manager)</h2>

    <form method="post">
<label>اختيار الباقة:</label>
<select name="profile" required>
    <?php foreach ($profiles_from_router as $p): ?>
        <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
    <?php endforeach; ?>
</select>



        <label>اختيار القالب:</label>
        <select name="template" required>
            <?php foreach ($templates as $t): ?>
                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label>اختيار نقطة البيع:</label>
        <select name="sales_point">
            <?php foreach ($salesPoints as $sp): ?>
                <option value="<?= $sp['id'] ?>"><?= htmlspecialchars($sp['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label>نمط الكروت:</label>
        <select name="card_mode">
            <option value="username">اسم مستخدم فقط</option>
            <option value="userpass">اسم مستخدم + كلمة مرور</option>
        </select>

        <label>نمط اسم المستخدم:</label>
        <select name="user_mode">
            <option value="numeric">أرقام</option>
            <option value="alpha">حروف</option>
            <option value="alnum">أرقام وحروف</option>
        </select>

        <label>نمط كلمة المرور:</label>
        <select name="pass_mode">
            <option value="numeric">أرقام</option>
            <option value="alpha">حروف</option>
            <option value="alnum" selected>أرقام وحروف</option>
        </select>

        <label>عدد الكروت:</label>
        <input type="number" name="count" min="1" max="500" required>

        <label>طول الكرت:</label>
        <input type="number" name="length" min="4" max="16" value="6" required> <!-- ✅ طول الكرت -->

        <label>بادئة:</label>
        <input type="text" name="prefix">

        <label>لاحقة:</label>
        <input type="text" name="suffix">

        <label>
            <input type="checkbox" name="bind_first"> ربط الكرت بأول جهاز يستخدمه
        </label>

        <div class="btns">
            <button type="submit" name="preview_design" value="1" class="btn-alt">معاينة التصميم فقط</button>
    
            <button type="submit" class="btn-main">إنشاء ومعاينة الطباعة</button>
        </div>
    </form>

</body>

</html>