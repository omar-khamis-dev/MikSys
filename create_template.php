<?php
// create_template.php
// واجهة إنشاء/تعديل قوالب الكروت
// - يرفع صورة الخلفية إلى uploads/
// - يخزن اسم الملف فقط في العمود bg_filename
// - يخزن HTML القالب في العمود html

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$DB_FILE = __DIR__ . '/database.db';
if (!file_exists($DB_FILE)) {
    // إن لم توجد قاعدة البيانات - تجربة بسيطة: الملف يجب أن يتواجد فعلاً في مشروعك
    // لا ننشئ قاعدة هنا لأن مشروعك يتضمن database.db بالفعل.
}

try {
    $db = new PDO("sqlite:$DB_FILE");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // فشل الاتصال
    http_response_code(500);
    echo "خطأ اتصال بقاعدة البيانات: " . htmlspecialchars($e->getMessage());
    exit;
}

/* ----------------- ضمان وجود الأعمدة المطلوبة ----------------- */
/* - عمود html (لتخزين كود القالب) */
/* - عمود bg_filename (لتخزين اسم ملف الخلفية فقط) */
try {
    $colsStmt = $db->query("PRAGMA table_info(templates)");
    $cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');

    if (!in_array('html', $colNames, true)) {
        // إذا لم يوجد عمود html فأنشئه
        $db->exec("ALTER TABLE templates ADD COLUMN html TEXT");
    }
    if (!in_array('bg_filename', $colNames, true)) {
        // نضيف عمود bg_filename لتخزين اسم الملف فقط
        $db->exec("ALTER TABLE templates ADD COLUMN bg_filename TEXT");
    }
} catch (Exception $e) {
    // نتابع بدون إنهاء؛ لكن نعرض تحذيراً بسيطاً (لن يحدث غالباً لأن الجدول مفترض أنه موجود)
    // إذا لم يكن جدول templates موجوداً فهذه خطوة لاحقة منطقية، لكن المشروع يحتوي عليه.
}

/* ----------------- نقطة رفع الصورة عبر AJAX ----------------- */
/* طلب POST مع ?action=upload_bg يتوقع ملف في field name 'bgfile' */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'upload_bg') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_FILES['bgfile']) || $_FILES['bgfile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'لم يتم استلام ملف الخلفية أو حدث خطأ أثناء الرفع.']);
        exit;
    }

    $file = $_FILES['bgfile'];

    // حد حجم الملف: 6MB
    $maxBytes = 6 * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        echo json_encode(['success' => false, 'error' => 'حجم الملف أكبر من المسموح (6MB).']);
        exit;
    }

    // التحقق من نوع MIME الحقيقي
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/pjpeg'=> 'jpg',
        'image/gif'  => 'gif',
        'image/webp' => 'webp'
    ];
    if (!isset($allowed[$mime])) {
        echo json_encode(['success' => false, 'error' => 'نوع الملف غير مدعوم. استخدم PNG أو JPG أو GIF أو WEBP.']);
        exit;
    }

    // تأكد من وجود مجلد uploads
    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            echo json_encode(['success' => false, 'error' => 'فشل إنشاء مجلد uploads على الخادم.']);
            exit;
        }
    }

    // إنشاء اسم ملف آمن وفريد
    try {
        $rnd = bin2hex(random_bytes(6));
    } catch (Exception $e) {
        $rnd = uniqid();
    }
    $ext = $allowed[$mime];
    $basename = time() . '_' . $rnd . '.' . $ext;
    $dest = $uploadDir . DIRECTORY_SEPARATOR . $basename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['success' => false, 'error' => 'فشل حفظ الملف على الخادم.']);
        exit;
    }

    // نجاح
    echo json_encode(['success' => true, 'filename' => $basename]);
    exit;
}

/* ----------------- حفظ أو تحديث القالب (POST من النموذج) ----------------- */
/*
  نتوقع الحقول:
  - name (اسم القالب)
  - html (نص HTML الداخلي للبطاقة: نستخدم innerHTML)
  - bg_filename (اختياري: اسم الملف في uploads/)
  - id (اختياري: للتحديث)
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['action'])) {
    // المعالجة الأساسية للحفظ
    $name = trim((string)($_POST['name'] ?? ''));
    $html = trim((string)($_POST['html'] ?? ''));
    $bgFilename = isset($_POST['bg_filename']) ? trim((string)$_POST['bg_filename']) : null;
    $id = isset($_POST['id']) && (int)$_POST['id'] > 0 ? (int)$_POST['id'] : 0;

    // التحقق البسيط
    if ($name === '') {
        $errorMsg = 'اسم القالب مطلوب.';
    } elseif ($html === '') {
        $errorMsg = 'محتوى HTML للقالب فارغ — تأكد من إنشاء عناصر القالب قبل الحفظ.';
    } else {
        try {
            if ($id > 0) {
                // تحديث
                $stmt = $db->prepare("UPDATE templates SET name = :name, html = :html, bg_filename = :bg WHERE id = :id");
                $stmt->execute([':name' => $name, ':html' => $html, ':bg' => $bgFilename, ':id' => $id]);
                $savedId = $id;
            } else {
                // إدخال جديد
                $stmt = $db->prepare("INSERT INTO templates (name, html, bg_filename) VALUES (:name, :html, :bg)");
                $stmt->execute([':name' => $name, ':html' => $html, ':bg' => $bgFilename]);
                $savedId = (int)$db->lastInsertId();
            }

            // توجيه لتفادي إعادة الإرسال ولعرض القالب المحفوظ
            header("Location: create_template.php?id=" . $savedId . "&saved=1");
            exit;
        } catch (PDOException $e) {
            $errorMsg = 'خطأ عند حفظ القالب: ' . $e->getMessage();
        }
    }
}

/* ----------------- تحميل قالب محدد (GET ?id=) ----------------- */
$template_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$current_template = null;
if ($template_id > 0) {
    $stmt = $db->prepare("SELECT id, name, html, bg_filename FROM templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $current_template = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* ----------------- جلب قائمة القوالب ----------------- */
$tpls = $db->query("SELECT id, name FROM templates ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

/* ----------------- واجهة HTML/JS ----------------- */
?><!doctype html>
<html lang="ar">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إنشاء / تعديل قوالب الكروت</title>
<style>
  body { font-family: Arial, sans-serif; direction: rtl; padding:16px; display:flex; gap:20px; }
  .controls { width:360px; padding:12px; border:1px solid #e0e0e0; border-radius:8px; background:#fbfbfb; }
  .templates-list { max-height:260px; overflow:auto; border:1px solid #f0f0f0; padding:6px; border-radius:6px; margin-bottom:12px; }
  .templates-list a { display:block; padding:8px; text-decoration:none; color:#222; border-radius:4px; }
  .templates-list a:hover { background:#f4f8ff; }
  .preview-area { flex:1; display:flex; flex-direction:column; gap:12px; align-items:flex-start; }
  .preview { width:200px; height:54px; border:1px solid #000; border-radius:6px; position:relative; background-size:cover; background-position:center; overflow:hidden; background-repeat:no-repeat; }
  .field { position:absolute; font-size:10px; color:#000; background:rgba(255,255,255,0.85); padding:2px 4px; border-radius:4px; cursor:move; }
  .field.selected { outline:1px dashed #c00; }
  .notice { color:green; margin-bottom:8px; }
  label { display:block; font-weight:600; margin-top:8px; }
  input[type="text"], input[type="number"] { padding:6px; width:100%; box-sizing:border-box; margin-top:6px; }
  button, input[type="submit"] { padding:8px 10px; margin-top:8px; }
  .small { font-size:12px; color:#666; margin-top:6px; }
  .error { color: #b00020; margin-bottom:8px; }
</style>
</head>
<body>

  <div class="controls">
    <?php if (!empty($errorMsg)): ?>
      <div class="error"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['saved'])): ?>
      <div class="notice">✅ تم حفظ القالب بنجاح</div>
    <?php endif; ?>

    <h3>📂 القوالب المحفوظة</h3>
    <div class="templates-list" id="templatesList">
      <?php if (empty($tpls)): ?>
        <div class="small">لا توجد قوالب محفوظة حتى الآن.</div>
      <?php else: foreach ($tpls as $t): ?>
        <a href="create_template.php?id=<?= (int)$t['id'] ?>" <?= ($template_id === (int)$t['id']) ? 'style="font-weight:700;background:#eef6ff;"' : '' ?>>
          <?= htmlspecialchars($t['name']) ?>
        </a>
      <?php endforeach; endif; ?>
    </div>

    <h3>خيارات التصميم</h3>

    <label>خلفية الكرت (رفع صورة)</label>
    <input type="file" id="bgInput" accept="image/*">
    <div class="small">سيتم رفع الصورة إلى <code>uploads/</code> وتخزين اسم الملف فقط.</div>

    <label>عناصر الكرت</label>
    <div style="display:flex;flex-direction:column;gap:6px;margin-top:6px;">
      <button type="button" onclick="toggleField('username')">إظهار/إخفاء اسم المستخدم</button>
      <button type="button" onclick="toggleField('password')">إظهار/إخفاء كلمة المرور</button>
      <button type="button" onclick="toggleField('id')">إظهار/إخفاء ID</button>
      <button type="button" onclick="toggleField('duration')">إظهار/إخفاء المدة</button>
      <button type="button" onclick="toggleField('profile')">إظهار/إخفاء البروفايل</button>
    </div>

    <hr>

    <h4>تعديل العنصر المحدد</h4>
    <label>الحجم (px)</label>
    <input type="number" id="fontSize" min="6" max="40" value="10">
    <label>اللون</label>
    <input type="color" id="fontColor" value="#000000">
    <div style="margin-top:8px;">
      <button type="button" onclick="deleteSelected()">🗑️ حذف العنصر</button>
    </div>

    <hr>

    <h4>حفظ / تحديث القالب</h4>
    <form method="POST" onsubmit="return saveTemplate();" id="saveForm">
      <input type="hidden" name="id" id="templateId" value="<?= htmlspecialchars($current_template['id'] ?? '') ?>">
      <label>اسم القالب:</label>
      <input type="text" name="name" id="templateName" required value="<?= htmlspecialchars($current_template['name'] ?? '') ?>">
      <input type="hidden" name="html" id="templateHtml">
      <input type="hidden" name="bg_filename" id="templateBgFilename" value="<?= htmlspecialchars($current_template['bg_filename'] ?? '') ?>">
      <input type="submit" value="💾 حفظ القالب">
    </form>
  </div>

  <div class="preview-area">
    <h3>معاينة الكرت</h3>
    <div id="cardPreview" class="preview" data-bg="<?= htmlspecialchars($current_template['bg_filename'] ?? '') ?>">
      <!-- سيتم تعبئة الحقول داخل هذه العنصر -->
    </div>
    <div class="small">اسحب العناصر لتغيير مواضعها. الخلفية تُرفع وتخزن في uploads/.</div>
  </div>

<script>
/* ---------- إعدادات عامة ---------- */
const UPLOAD_ENDPOINT = 'create_template.php?action=upload_bg';
const UPLOADS_WEB = 'uploads'; // المسار الظاهر في HTML عندما نحفظ القالب
let selected = null;

/* ---------- مساعدة تحويل rgb إلى hex ---------- */
function toHexRgb(rgb) {
  if (!rgb) return '#000000';
  const m = rgb.match(/\d+/g);
  if (!m) return '#000000';
  return '#' + m.slice(0,3).map(x => parseInt(x,10).toString(16).padStart(2,'0')).join('');
}

/* ---------- تهيئة قابلية السحب والاختيار لعناصر الحقول ---------- */
function initDraggable(el, card) {
  el.onclick = function(e) {
    e.stopPropagation();
    if (selected) selected.classList.remove('selected');
    selected = el;
    selected.classList.add('selected');
    document.getElementById('fontSize').value = parseInt(getComputedStyle(el).fontSize, 10) || 10;
    document.getElementById('fontColor').value = toHexRgb(getComputedStyle(el).color);
  };

  el.onmousedown = function(e) {
    if (e.target !== el) return;
    const rect = card.getBoundingClientRect();
    let shiftX = e.clientX - el.getBoundingClientRect().left;
    let shiftY = e.clientY - el.getBoundingClientRect().top;

    function moveAt(pageX, pageY) {
      let left = pageX - rect.left - shiftX;
      let top  = pageY - rect.top  - shiftY;
      // قيود بسيطة داخل حدود الكرت
      if (left < 0) left = 0;
      if (top < 0) top = 0;
      if (left > rect.width - el.offsetWidth) left = rect.width - el.offsetWidth;
      if (top > rect.height - el.offsetHeight) top = rect.height - el.offsetHeight;
      el.style.left = left + 'px';
      el.style.top  = top  + 'px';
    }

    function onMouseMove(ev) { moveAt(ev.pageX, ev.pageY); }
    document.addEventListener('mousemove', onMouseMove);

    document.addEventListener('mouseup', function up() {
      document.removeEventListener('mousemove', onMouseMove);
      document.removeEventListener('mouseup', up);
    });
  };

  el.ondragstart = () => false;
}

/* تهيئة كل الحقول داخل الكرت */
function initFields(card) {
  card.querySelectorAll('.field').forEach(f => initDraggable(f, card));
}

/* ---------- رفع الخلفية (AJAX) ---------- */
document.getElementById('bgInput').addEventListener('change', async function(e) {
  const file = e.target.files[0];
  if (!file) return alert('لم يتم اختيار ملف.');

  if (file.size > 6 * 1024 * 1024) {
    return alert('حجم الملف أكبر من 6MB.');
  }

  const fd = new FormData();
  fd.append('bgfile', file);

  try {
    const res = await fetch(UPLOAD_ENDPOINT, { method: 'POST', body: fd });
    const json = await res.json();
    if (!json.success) {
      alert('فشل رفع الصورة: ' + (json.error || 'خطأ غير معروف'));
      return;
    }
    // اسم الملف فقط
    const filename = json.filename;
    const card = document.getElementById('cardPreview');
    card.style.backgroundImage = `url('${UPLOADS_WEB}/${filename}')`;
    card.dataset.bg = filename;
    // ضع اسم الملف أيضاً في الحقل المخفي للحفظ
    document.getElementById('templateBgFilename').value = filename;
  } catch (err) {
    console.error(err);
    alert('حدث خطأ أثناء رفع الملف.');
  }
});

/* ---------- إضافة/إخفاء الحقول ---------- */
function toggleField(type) {
  const card = document.getElementById('cardPreview');
  let el = document.getElementById('field_' + type);
  if (el) {
    el.remove();
    if (selected === el) selected = null;
  } else {
    const div = document.createElement('div');
    div.className = 'field';
    div.id = 'field_' + type;
    div.textContent = type.toUpperCase();
    div.style.top = '6px';
    div.style.left = '6px';
    card.appendChild(div);
    initDraggable(div, card);
  }
}

/* ---------- تعديل الحجم واللون للعنصر المحدد ---------- */
document.getElementById('fontSize').addEventListener('input', function() {
  if (selected) selected.style.fontSize = this.value + 'px';
});
document.getElementById('fontColor').addEventListener('input', function() {
  if (selected) selected.style.color = this.value;
});

/* ---------- حذف العنصر المحدد ---------- */
function deleteSelected() {
  if (selected) { selected.remove(); selected = null; }
}

/* إلغاء التحديد بالنقر على الخلفية */
document.getElementById('cardPreview').addEventListener('click', function() {
  if (selected) selected.classList.remove('selected');
  selected = null;
});

/* ---------- تحميل القالب المحفوظ وإظهار الخلفية بشكل صحيح ---------- */
const SAVED_HTML = <?= json_encode($current_template['html'] ?? '') ?>;
const SAVED_BG   = <?= json_encode($current_template['bg_filename'] ?? '') ?>;

function hydrateFromSavedHtml(savedHtml, savedBg) {
  const card = document.getElementById('cardPreview');

  if (!savedHtml) {
    // إذا لا يوجد قالب محفوظ: وضع عناصر مبدئية مع placeholders
    if (!card.querySelector('#field_username')) {
      const u = document.createElement('div'); u.className='field'; u.id='field_username'; u.textContent='{username}'; u.style.top='6px'; u.style.left='6px'; card.appendChild(u);
    }
    if (!card.querySelector('#field_password')) {
      const p = document.createElement('div'); p.className='field'; p.id='field_password'; p.textContent='{password}'; p.style.top='26px'; p.style.left='6px'; card.appendChild(p);
    }
    if (!card.querySelector('#field_profile')) {
      const pr = document.createElement('div'); pr.className='field'; pr.id='field_profile'; pr.textContent='{profile}'; pr.style.top='42px'; pr.style.left='6px'; card.appendChild(pr);
    }
    if (savedBg) {
      card.style.backgroundImage = `url('${UPLOADS_WEB}/${savedBg}')`;
      card.dataset.bg = savedBg;
      document.getElementById('templateBgFilename').value = savedBg;
    }
    initFields(card);
    return;
  }

  // استخدم عنصر مؤقت لتحليل HTML المحفوظ (الذي يخزن الحقول الداخلية فقط)
  const tmp = document.createElement('div');
  tmp.innerHTML = savedHtml.trim();

  // إذا كانت القيمة المحفوظة عبارة عن كامل العنصر <div class="preview"> ... نسانده أيضاً
  // لكن بحسب تصميمنا نحفظ فقط innerHTML لذلك نضعه مباشرة
  card.innerHTML = tmp.innerHTML || savedHtml;

  // إذا كان هناك اسم ملف محفوظ نعرضه
  if (savedBg) {
    card.style.backgroundImage = `url('${UPLOADS_WEB}/${savedBg}')`;
    card.dataset.bg = savedBg;
    document.getElementById('templateBgFilename').value = savedBg;
  } else {
    // محاولة لاستخراج أي background-image مضمن في savedHtml (fallback)
    const m = (savedHtml.match(/background-image\s*:\s*url\((?:'|")?(.*?)(?:'|")?\)/i) || [])[1] || null;
    if (m) {
      card.style.backgroundImage = `url('${m}')`;
    }
  }
  initFields(card);
}

/* ---------- حفظ القالب: نضع محتوى innerHTML في الحقل المخفي، وننسخ bg filename أيضاً ---------- */
function saveTemplate() {
  const card = document.getElementById('cardPreview');

  // استبدال أي قيم فعلية بقيم placeholders قبل الحفظ
  card.querySelectorAll('.field').forEach(f => {
    switch (f.id) {
      case 'field_username': f.textContent = '{username}'; break;
      case 'field_password': f.textContent = '{password}'; break;
      case 'field_id':       f.textContent = '{id}'; break;
      case 'field_duration': f.textContent = '{duration}'; break;
      case 'field_profile':  f.textContent = '{profile}'; break;
      default: break;
    }
  });

  const inner = card.innerHTML;
  document.getElementById('templateHtml').value = inner;

  // bg filename
  const fn = card.dataset.bg || document.getElementById('templateBgFilename').value || '';
  document.getElementById('templateBgFilename').value = fn;

  if (!document.getElementById('templateName').value.trim()) {
    alert('أدخل اسم القالب.');
    return false;
  }
  if (!inner.trim()) {
    alert('القالب فارغ — أضف عناصر قبل الحفظ.');
    return false;
  }
  // السماح بالإرسال
  return true;
}

/* ---------- تهيئة عند فتح الصفحة ---------- */
hydrateFromSavedHtml(SAVED_HTML, SAVED_BG);
initFields(document.getElementById('cardPreview'));
</script>

</body>
</html>
