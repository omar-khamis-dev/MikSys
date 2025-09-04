<?php
// create_vouchers.php
// صفحة إنشاء دفعات الكروت (vouchers) — توليد معاينة وطباعة وحفظ في قاعدة SQLite.
// متطلبات: database.db موجود، جدول templates يحتوي على html و bg_filename (حسب create_template.php)
//            جداول profiles, batches, vouchers, sales_points موجودة كما ذكرت سابقًا.

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$DB_PATH = __DIR__ . '/database.db';

// --- اتصال بقاعدة البيانات ---
try {
    $db = new PDO("sqlite:$DB_PATH");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo 'خطأ في الاتصال بقاعدة البيانات: ' . htmlspecialchars($e->getMessage());
    exit;
}
require('routeros-api-master/routeros_api.class.php');
$API = new RouterosAPI();
$API->debug = false;

$router_ip   = "1.1.1.1";
$router_user = "admin";
$router_pass = "Freedom2020";

$profiles_from_router = [];

if ($API->connect($router_ip, $router_user, $router_pass)) {
    // جلب بروفايلات الهوتسبوت من اليوزر منجر
    $profiless = $API->comm("/tool/user-manager/profile/print");

    foreach ($profiless as $profile) {
        if (!empty($profile['name'])) {
            $name = $profile['name'];
            $profiles_from_router[] = $name;

            // إدخال البروفايل في قاعدة البيانات إذا لم يكن موجود
            $stmt = $db->prepare("INSERT OR IGNORE INTO profiles (name, type) VALUES (:name, 'userman')");
            $stmt->execute([':name' => $name]);
        }
    }

    $API->disconnect();
} else {
    // إذا ما قدر يتصل بالمايكروتك، نستخدم النسخة المحلية
    $profiles_local = $db->query("SELECT name FROM profiles")->fetchAll(PDO::FETCH_COLUMN);
    $profiles_from_router = $profiles_local;
}

// --- تأكد من أن الحقول المطلوبة موجودة / ترقية الجداول إن لزم ---
try {
    $cols = $db->query("PRAGMA table_info(vouchers)")->fetchAll(PDO::FETCH_ASSOC);
    $vcols = array_column($cols, 'name');
    if (!in_array('bind_first', $vcols, true)) {
        // إضافة عمود bind_first لتخزين ما إذا تم ربط الكرت بأول جهاز يستخدمه (0/1)
        $db->exec("ALTER TABLE vouchers ADD COLUMN bind_first INTEGER DEFAULT 0");
    }
} catch (Exception $e) {
    // إذا فشل الحصول على معلومات الجدول لا نكمل - لكن لا نهدم الصفحة تماما
}

// --- مسح وإعداد المتغيرات والبيانات المرجعية ---
$error = '';
$successMsg = '';

// جلب القوالب
$templatesStmt = $db->query("SELECT id, name, html, COALESCE(bg_filename, '') AS bg_filename FROM templates ORDER BY id DESC");
$templates = $templatesStmt->fetchAll(PDO::FETCH_ASSOC);

// جلب الباقات
$profilesStmt = $db->query("SELECT id, name, type, validity_days FROM profiles ORDER BY name");
$profiles = $profilesStmt->fetchAll(PDO::FETCH_ASSOC);

// جلب نقاط البيع
$salesStmt = $db->query("SELECT id, name FROM sales_points ORDER BY name");
$sales_points = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

// مساعدة: فحص صلاحية اسم مستخدم موجود
function usernameExists(PDO $db, string $username): bool {
    $st = $db->prepare("SELECT COUNT(1) FROM vouchers WHERE username = :u");
    $st->execute([':u' => $username]);
    return (bool)$st->fetchColumn();
}

// مساعدة: توليد باسورد عشوائي
function generateRandomPassword(int $len): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'; // بدون حروف شبيهه
    $out = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

// مساعدة: توليد اسم مستخدم عشوائي (أرقام أو أحرف)
function generateRandomUsername(int $len, string $type = 'alnum'): string {
    if ($len <= 0) return '';
    $numbers = '0123456789';
    $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $out = '';
    if ($type === 'numeric') {
        $pool = $numbers;
    } else {
        $pool = $letters . $numbers;
    }
    $max = strlen($pool) - 1;
    for ($i = 0; $i < $len; $i++) $out .= $pool[random_int(0, $max)];
    return $out;
}

// مساعدة: توليد sequential username
function generateSequentialUsername(PDO $db, string $prefix, int $start, int $lenNumber, string $suffix): string {
    // حاول إضافة رقم متزايد متأكد أنه غير مستخدم
    $attempt = $start;
    while (true) {
        $numStr = str_pad((string)$attempt, $lenNumber, '0', STR_PAD_LEFT);
        $username = $prefix . $numStr . $suffix;
        $st = $db->prepare("SELECT COUNT(1) FROM vouchers WHERE username = :u");
        $st->execute([':u' => $username]);
        if ((int)$st->fetchColumn() === 0) return $username;
        $attempt++;
        // للحماية: لو وصلنا لحد كبير نكسر
        if ($attempt > $start + 1000000) {
            // fallback: random
            return generateRandomUsername($lenNumber, 'alnum');
        }
    }
}

// --- معالجة طلب الإنشاء الفعلي (Generate & Print) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate') {
    // قراءة المدخلات وتطهيرها
    $template_id = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;
    $profile_id  = isset($_POST['profile_id']) ? (int)$_POST['profile_id'] : 0;
    $sales_point_id = isset($_POST['sales_point_id']) ? (int)$_POST['sales_point_id'] : null;
    $count = isset($_POST['count']) ? (int)$_POST['count'] : 0;
    $user_pattern = $_POST['user_pattern'] ?? 'random'; // 'random' or 'sequential'
    $user_len = isset($_POST['user_len']) ? max(1, (int)$_POST['user_len']) : 8;
    $pass_len = isset($_POST['pass_len']) ? max(4, (int)$_POST['pass_len']) : 8;
    $prefix = trim($_POST['prefix'] ?? '');
    $suffix = trim($_POST['suffix'] ?? '');
    $sequential_start = isset($_POST['seq_start']) ? max(0, (int)$_POST['seq_start']) : 1;
    $bind_first = isset($_POST['bind_first']) ? 1 : 0;
    $batch_code = trim($_POST['batch_code'] ?? '');
    if ($batch_code === '') {
        $batch_code = 'BATCH_' . date('Ymd_His');
    }

    // validate
    if ($template_id <= 0) $error = 'اختر قالبًا صالحًا.';
    elseif ($profile_id <= 0) $error = 'اختر باقة (profile).';
    elseif ($count <= 0 || $count > 10000) $error = 'الكمية (count) يجب أن تكون بين 1 و 10000.';
    if ($error === '') {
        // جلب معلومات القالب و profile
        $st = $db->prepare("SELECT id, name, html, bg_filename FROM templates WHERE id = ?");
        $st->execute([$template_id]);
        $tpl = $st->fetch(PDO::FETCH_ASSOC);
        if (!$tpl) $error = 'القالب المختار غير موجود.';
        $st = $db->prepare("SELECT * FROM profiles WHERE id = ?");
        $st->execute([$profile_id]);
        $profile = $st->fetch(PDO::FETCH_ASSOC);
        if (!$profile) $error = 'الباقة المختارة غير موجودة.';
    }

    if ($error === '') {
        // ابدأ معاملة إدخال
        try {
            $db->beginTransaction();

            // إنشاء دفعة جديدة
            $stmt = $db->prepare("INSERT INTO batches (code, profile_id, sales_point_id, created_at) VALUES (:code, :profile_id, :sales_point_id, datetime('now'))");
            $stmt->execute([
                ':code' => $batch_code,
                ':profile_id' => $profile_id,
                ':sales_point_id' => $sales_point_id
            ]);
            $batch_id = (int)$db->lastInsertId();

            // تجهيز إدخال القسائم
            $insertStmt = $db->prepare("INSERT INTO vouchers (batch_id, username, password, status, expires_at, created_at, bind_first) VALUES (:batch_id, :username, :password, :status, :expires_at, datetime('now'), :bind_first)");

            $triesLimit = 10;

            // حساب تاريخ الانتهاء إن كانت الباقة تحتوي validity_days
            $validity_days = isset($profile['validity_days']) && $profile['validity_days'] !== null ? (int)$profile['validity_days'] : null;
            $expires_at_template = null;
            if ($validity_days !== null && $validity_days > 0) {
                $expires_at_template = (new DateTime())->add(new DateInterval('P' . (int)$validity_days . 'D'))->format('Y-m-d H:i:s');
            }

            // توليد الكروت
            for ($i = 0; $i < $count; $i++) {
                // توليد اسم المستخدم
                $username = '';
                if ($user_pattern === 'sequential') {
                    $username = generateSequentialUsername($db, $prefix, $sequential_start + $i, $user_len, $suffix);
                } else {
                    // random
                    $attempt = 0;
                    do {
                        $usernameCore = generateRandomUsername($user_len, 'alnum');
                        $username = $prefix . $usernameCore . $suffix;
                        $attempt++;
                        if ($attempt > 100) {
                            // fallback: append microtime
                            $username = $prefix . $usernameCore . time() . $suffix;
                            break;
                        }
                    } while (usernameExists($db, $username));
                }

                // توليد كلمة المرور
                $password = generateRandomPassword($pass_len);

                // expire: إن لم تكن توجد باقة محددة بالمدة، نترك NULL أو يمكن قبول user input لاحقاً
                $expires_at = $expires_at_template;

                // حالة المبدئية 'new'
                $status = 'new';

                // تنفيذ الإدخال
                $insertStmt->execute([
                    ':batch_id' => $batch_id,
                    ':username' => $username,
                    ':password' => $password,
                    ':status' => $status,
                    ':expires_at' => $expires_at,
                    ':bind_first' => $bind_first
                ]);
            }

            $db->commit();

            // نجاح: إعادة التوجيه إلى معاينة الطباعة للدفعة المنشأة
            header("Location: print_preview.php?batch_id=" . $batch_id);
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'خطأ أثناء إنشاء القسائم: ' . $e->getMessage();
        }
    }
}

// ------------------------- HTML الواجهة -------------------------
?><!DOCTYPE html>
<html lang="ar">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إنشاء الكروت - MikSys</title>
<style>
  body { font-family: Arial, sans-serif; direction: rtl; padding:16px; display:flex; gap:20px; }
  .left { width:380px; border:1px solid #e6e6e6; padding:12px; border-radius:8px; background:#fafafa; }
  .right { flex:1; }
  label { display:block; font-weight:600; margin-top:8px; }
  input[type="text"], input[type="number"], select { width:100%; padding:6px; box-sizing:border-box; margin-top:6px; }
  .row { display:flex; gap:8px; }
  .small { font-size:13px; color:#666; margin-top:6px; }
  .preview-grid { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
  .card-sample { width:200px; height:54px; border:1px solid #111; border-radius:6px; background-size:cover; background-position:center; position:relative; overflow:hidden; }
  .card-sample .field { position:absolute; font-size:10px; background:rgba(255,255,255,0.85); padding:2px 4px; border-radius:3px; }
  .error { color:#b00020; margin-bottom:8px; }
  button { padding:8px 12px; margin-top:10px; cursor:pointer; }
</style>
</head>
<body>

<div class="left">
  <h3>إنشاء دفعة كروت</h3>

  <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <form method="POST" id="genForm">
    <label>القالب (Template)</label>
    <select name="template_id" id="templateSelect" required>
      <option value="">-- اختر قالبًا --</option>
      <?php foreach ($templates as $t): ?>
        <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
      <?php endforeach; ?>
    </select>



    <label>اختيار البروفايل:</label>
    <select name="profile_id" id="profileSelect" required>
        <?php foreach (array_unique($profiles_from_router) as $profile): ?>
            <option value="<?= htmlspecialchars($profile) ?>"><?= htmlspecialchars($profile) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label>نقطة البيع (Sales point)</label>
    <select name="sales_point_id" id="salesPointSelect">
      <option value="">(اختياري)</option>
      <?php foreach ($sales_points as $sp): ?>
        <option value="<?= (int)$sp['id'] ?>"><?= htmlspecialchars($sp['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <label>نمط اسم المستخدم</label>
    <select name="user_pattern" id="userPattern">
      <option value="random">عشوائي</option>
      <option value="sequential">تسلسلي</option>
    </select>

    <label>طول اسم المستخدم (أرقام/أحرف)</label>
    <input type="number" name="user_len" id="userLen" value="8" min="1">

    <label>بادئة (prefix)</label>
    <input type="text" name="prefix" id="prefix" placeholder="مثال: NET-">

    <label>لاحقة (suffix)</label>
    <input type="text" name="suffix" id="suffix" placeholder="مثال: -2025">

    <label>بداية التسلسل (عند اختيار نمط تسلسلي)</label>
    <input type="number" name="seq_start" id="seqStart" value="1" min="0">

    <label>طول كلمة المرور</label>
    <input type="number" name="pass_len" id="passLen" value="8" min="4">

    <label>عدد الكروت</label>
    <input type="number" name="count" id="count" value="10" min="1" max="10000">

    <label>كود الدفعة (Batch code)</label>
    <input type="text" name="batch_code" id="batchCode" placeholder="سيتم إنشاؤه تلقائياً إن تركته فارغاً">

    <label>ربط بالـ first device</label>
    <div class="row">
      <label style="font-weight:400;"><input type="checkbox" name="bind_first" id="bindFirst"> ربط الكرت بأول جهاز يستخدمه</label>
    </div>

    <div style="display:flex; gap:8px;">
      <button type="button" id="previewBtn">معاينة</button>
      <button type="submit" name="action" value="generate">إنشاء & طباعة</button>
    </div>
  </form>
</div>

<div class="right">
  <h3>معاينة (عرض تجريبي)</h3>
  <div id="previewArea" class="preview-grid">
    <!-- سيتم إنشاء بطاقات معاينة هنا -->
  </div>
  <div class="small">زر المعاينة لا يحفظ الكروت — يعرض أمثلة وهمية. زر "إنشاء & طباعة" سيحفظ ويحوّل إلى صفحة الطباعة.</div>
</div>

<script>
/* ---------- تجهيز بيانات القوالب في الجافاسكربت ---------- */
const TEMPLATES = <?= json_encode(array_map(function($t){ return ['id'=>(int)$t['id'],'name'=>$t['name'],'html'=>$t['html'],'bg'=>$t['bg_filename']]; }, $templates), JSON_UNESCAPED_UNICODE) ?>;

/* توليد أرقام وهمية للمعاينة */
function randUsername(len, type='alnum') {
  const nums = '0123456789';
//   const letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
// const pool = (type === 'numeric') ? nums : (letters + nums);
const pool = (type === 'numeric') ? nums : nums;
let out='';
  for (let i=0;i<len;i++) out += pool.charAt(Math.floor(Math.random()*pool.length));
  return out;
}
function randPassword(len) {
  const pool = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
  let out='';
  for (let i=0;i<len;i++) out += pool.charAt(Math.floor(Math.random()*pool.length));
  return out;
}

/* بناء بطاقة معاينة من قالب (template) واستبدال placeholders بقيم وهمية */
function buildCardHtmlFromTemplate(template, replacements) {
  // template.html is innerHTML (fields), template.bg is filename or empty
  let wrapperBg = '';
  if (template.bg && template.bg.length) {
    wrapperBg = `style="background-image:url('uploads/${template.bg}'); background-size:cover; background-position:center;"`;
  }
  // inner = template.html (contains fields like {username})
  let inner = template.html || '';
  // Replace placeholders in inner
  Object.keys(replacements).forEach(k => {
    const r = replacements[k];
    inner = inner.split(`{${k}}`).join(escapeHtml(r));
  });
  // return wrapper HTML to inject in preview card element
  return { innerHtml: inner, bgStyle: wrapperBg };
}

/* html escape */
function escapeHtml(s) {
  if (s === null || s === undefined) return '';
  return String(s).replace(/[&<>"']/g, function(m){
    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
  });
}

/* Preview button handler */
document.getElementById('previewBtn').addEventListener('click', function(){
  const tplId = parseInt(document.getElementById('templateSelect').value || 0, 10);
  const cnt = Math.min(50, Math.max(1, parseInt(document.getElementById('count').value || 10, 10))); // limit preview to 50
  const userPattern = document.getElementById('userPattern').value;
  const userLen = parseInt(document.getElementById('userLen').value || 8, 10);
  const passLen = parseInt(document.getElementById('passLen').value || 8, 10);
  const prefix = document.getElementById('prefix').value || '';
  const suffix = document.getElementById('suffix').value || '';

  if (!tplId) { alert('اختر قالباً للمعاينة'); return; }

  const tpl = TEMPLATES.find(t => t.id === tplId);
  if (!tpl) { alert('القالب غير موجود'); return; }

  const previewArea = document.getElementById('previewArea');
  previewArea.innerHTML = '';

  for (let i=0;i<Math.min(12,cnt);i++) { // show up to 12 samples
    // generate fake data
    let username;
    if (userPattern === 'sequential') {
      username = prefix + String( (i+1) ).padStart(userLen, '0') + suffix;
    } else {
      username = prefix + randUsername(userLen,'alnum') + suffix;
    }
    const password = randPassword(passLen);
    const profileName = document.getElementById('profileSelect').selectedOptions[0] ? document.getElementById('profileSelect').selectedOptions[0].text : '';
    const replacements = { username: username, password: password, profile: profileName, id: i+1, duration: '' };

    const built = buildCardHtmlFromTemplate(tpl, replacements);

    const wrapper = document.createElement('div');
    wrapper.className = 'card-sample';
    if (tpl.bg && tpl.bg.length) {
      wrapper.style.backgroundImage = `url('uploads/${tpl.bg}')`;
    } else {
      wrapper.style.background = '#fff';
    }
    // innerHtml contains the fields - put them inside as HTML
    wrapper.innerHTML = built.innerHtml;

    previewArea.appendChild(wrapper);
  }
});

/* ---------- on submit: validate light client-side ---------- */
document.getElementById('genForm').addEventListener('submit', function(e){
  // ensure template and profile selected
  const tplId = parseInt(document.getElementById('templateSelect').value || 0, 10);
  const profId = parseInt(document.getElementById('profileSelect').value || 0, 10);
  const cnt = parseInt(document.getElementById('count').value || 0, 10);
  if (!tplId) { alert('اختر قالباً'); e.preventDefault(); return false; }
  if (!profId) { alert('اختر باقة'); e.preventDefault(); return false; }
  if (cnt <= 0 || cnt > 10000) { alert('عدد الكروت يجب أن يكون بين 1 و 10000'); e.preventDefault(); return false; }

  // قبل الإرسال: املأ الحقول الخفية المطلوبة (html & bg_filename)
  // سنأخذ innerHTML للـ #cardPreview عند المعاينة — ولكن هنا قد لا يكون هناك عناصر؛ لذلك نبني من قالب المختار:
  const tplIdSel = document.getElementById('templateSelect').value;
  const tpl = TEMPLATES.find(t => String(t.id) === String(tplIdSel));
  // We store inner HTML from the template's html stored server-side (which contains placeholders)
  // The create_template.php stores template.html as innerHTML of the preview. We will re-use that.
  // لذا نضع حقل html = tpl.html (محتوى الحقول) — وستتم ربط الخلفية عبر bg_filename field (موجود في DB).
  if (tpl) {
    // وضع القيم في الحقول المخفية في الفورم عن طريق إضافتها إذا لم تكن موجودة
    // create hidden inputs:
    let htmlField = document.getElementById('templateHtml');
    let bgField = document.getElementById('templateBgFilename');
    if (!htmlField) {
      htmlField = document.createElement('input');
      htmlField.type = 'hidden';
      htmlField.name = 'html';
      htmlField.id = 'templateHtml';
      document.getElementById('genForm').appendChild(htmlField);
    }
    if (!bgField) {
      bgField = document.createElement('input');
      bgField.type = 'hidden';
      bgField.name = 'bg_filename';
      bgField.id = 'templateBgFilename';
      document.getElementById('genForm').appendChild(bgField);
    }
    htmlField.value = tpl.html || '';
    bgField.value = tpl.bg || ''; // اسم الملف فقط
  }
  // let form submit and server will generate and redirect to print_preview
});

/* ---------- END ---------- */
</script>

</body>
</html>
