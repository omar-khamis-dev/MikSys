<?php
// create_template.php
// واجهة إنشاء / تعديل قوالب الكروت
// يستخدم مجلد uploads/ لحفظ صور الخلفية كملفات (يخزن اسم الملف فقط في قالب HTML)

/* ========== إعداد قاعدة البيانات ========== */
$db = new PDO("sqlite:database.db");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ======= تحديد اسم العمود المستخدم لحفظ HTML (html_code أو html) ======= */
$htmlColumn = null;
$colsStmt = $db->query("PRAGMA table_info(templates)");
$cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    if ($c['name'] === 'html_code') $htmlColumn = 'html_code';
    if ($c['name'] === 'html' && !$htmlColumn) $htmlColumn = 'html';
}
// إن لم يوجد أي من العمودين، نضيف عمود html (احتياطي)
if (!$htmlColumn) {
    $db->exec("ALTER TABLE templates ADD COLUMN html TEXT");
    $htmlColumn = 'html';
}

/* ========== 1) Upload background via AJAX (POST ?action=upload_bg) ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'upload_bg') {
    header('Content-Type: application/json; charset=utf-8');

    // تحقق وجود الملف
    if (!isset($_FILES['bgfile']) || $_FILES['bgfile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'لم يتم تحميل ملف الخلفية أو حدث خطأ أثناء التحميل.']);
        exit;
    }

    $file = $_FILES['bgfile'];

    // تحقق الحجم (مثال: 6 ميجابايت كحد أعلى)
    $maxBytes = 6 * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        echo json_encode(['success' => false, 'error' => 'حجم الملف أكبر من المسموح (6MB).']);
        exit;
    }

    // نوع الملف - استخدام finfo للحصول على نوع MIME حقيقي
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/pjpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        echo json_encode(['success' => false, 'error' => 'نوع الملف غير مدعوم. استخدم PNG, JPG, GIF أو WEBP.']);
        exit;
    }

    // إنشـاء مجلد uploads إن لم يكن موجودًا
    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            echo json_encode(['success' => false, 'error' => 'فشل في إنشاء مجلد uploads على السيرفر.']);
            exit;
        }
    }

    // إنشـاء اسم ملف فريد
    $ext = $allowed[$mime];
    try {
        $unique = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    } catch (Exception $e) {
        $unique = time() . '_' . rand(1000,9999) . '.' . $ext;
    }

    $destination = $uploadDir . DIRECTORY_SEPARATOR . $unique;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        echo json_encode(['success' => false, 'error' => 'فشل نقل الملف إلى المجلد uploads.']);
        exit;
    }

    // تحقق من أذونات الملف ووجوده
    if (!file_exists($destination)) {
        echo json_encode(['success' => false, 'error' => 'الملف لم يتم حفظه بشكل صحيح.']);
        exit;
    }

    // إرجاع اسم الملف فقط (المستخدم سيبني المسار 'uploads/اسم_الملف')
    echo json_encode(['success' => true, 'filename' => $unique]);
    exit;
}

/* ========== 2) حفظ / تحديث قالب (POST من النموذج) ========== */
$template_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$current_template = null;
if ($template_id > 0) {
    $st = $db->prepare("SELECT id, name, {$htmlColumn} FROM templates WHERE id = ?");
    $st->execute([$template_id]);
    $current_template = $st->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'], $_POST['html'])) {
    $name = trim($_POST['name']);
    $html = $_POST['html']; // HTML المرسل من الواجهة (يحتوي <div class="preview" style="background-image:url('uploads/xxx.png')">...</div>)
    $id   = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($id > 0) {
        $st = $db->prepare("UPDATE templates SET name = :name, {$htmlColumn} = :html WHERE id = :id");
        $st->execute([':name' => $name, ':html' => $html, ':id' => $id]);
        $savedId = $id;
    } else {
        $st = $db->prepare("INSERT INTO templates (name, {$htmlColumn}) VALUES (:name, :html)");
        $st->execute([':name' => $name, ':html' => $html]);
        $savedId = (int)$db->lastInsertId();
    }

    // إعادة توجيه لتجنّب إعادة إرسال النموذج (وباستطاعتك رؤية القالب فورًا)
    header("Location: create_template.php?id=" . $savedId . "&saved=1");
    exit;
}

/* جلب قائمة القوالب */
$tplsStmt = $db->query("SELECT id, name FROM templates ORDER BY id DESC");
$templates = $tplsStmt->fetchAll(PDO::FETCH_ASSOC);

/* HTML للواجهة */
?>
<!DOCTYPE html>
<html lang="ar">
<head>
<meta charset="utf-8" />
<title>إنشاء / تحرير قوالب الكروت</title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<style>
  body { font-family: Arial, sans-serif; direction: rtl; display:flex; gap:20px; padding:16px; }
  .controls { width:320px; padding:12px; border:1px solid #ddd; border-radius:8px; background:#fafafa; }
  .templates-list { max-height:260px; overflow:auto; border:1px solid #eee; padding:6px; border-radius:6px; margin-bottom:12px; }
  .templates-list a { display:block; padding:8px; text-decoration:none; color:#333; border-radius:4px; }
  .templates-list a:hover { background:#f2f2f2; }
  .preview-wrapper { flex:1; display:flex; flex-direction:column; gap:12px; align-items:flex-start; }
  .preview { width:200px; height:54px; border:1px solid #000; border-radius:6px; position:relative; background-size:cover; background-position:center; overflow:hidden; }
  .field { position:absolute; font-size:10px; color:#000; background:rgba(255,255,255,0.7); padding:2px 4px; border-radius:4px; cursor:move; }
  .field.selected { outline:1px dashed #d00; }
  .notice { color:green; margin-bottom:8px; }
  .controls label { font-weight:600; display:block; margin-top:8px; }
  .small { font-size:12px; color:#666; }
  button, input[type="submit"] { cursor:pointer; }
</style>
</head>
<body>

  <div class="controls">
    <?php if (isset($_GET['saved'])): ?>
      <div class="notice">✅ تم حفظ القالب بنجاح</div>
    <?php endif; ?>

    <h3>📂 القوالب المحفوظة</h3>
    <div class="templates-list" id="templatesList">
      <?php if (count($templates) === 0): ?>
        <div class="small">لا توجد قوالب محفوظة حتى الآن.</div>
      <?php else: ?>
        <?php foreach ($templates as $t): ?>
          <a href="create_template.php?id=<?= (int)$t['id'] ?>" <?= ($template_id === (int)$t['id']) ? 'style="font-weight:700;background:#eef6ff;"' : '' ?>>
            <?= htmlspecialchars($t['name']) ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <h3>خيارات التصميم</h3>

    <label>خلفية الكرت (رفع صورة)</label>
    <input type="file" id="bgInput" accept="image/*">
    <div class="small">اختر صورة من جهازك. سيتم رفعها إلى المجلد <code>uploads/</code> وتخزين اسم الملف فقط.</div>

    <label style="margin-top:10px;">عناصر الكرت</label>
    <div style="display:flex;flex-direction:column;gap:8px;margin-top:6px;">
      <button type="button" onclick="toggleField('username')">إظهار/إخفاء اسم المستخدم</button>
      <button type="button" onclick="toggleField('password')">إظهار/إخفاء كلمة المرور</button>
      <button type="button" onclick="toggleField('id')">إظهار/إخفاء ID</button>
      <button type="button" onclick="toggleField('duration')">إظهار/إخفاء المدة</button>
      <button type="button" onclick="toggleField('profile')">إظهار/إخفاء البروفايل</button>
    </div>

    <hr />

    <h4>تعديل العنصر المحدد</h4>
    <label>الحجم:</label>
    <input type="number" id="fontSize" min="6" max="40" value="10">
    <label>اللون:</label>
    <input type="color" id="fontColor" value="#000000">
    <div style="margin-top:8px;">
      <button type="button" onclick="deleteSelected()">🗑️ حذف العنصر</button>
    </div>

    <hr />

    <h4>حفظ / تحديث القالب</h4>
    <form method="POST" onsubmit="return saveTemplate();" id="saveForm">
      <input type="hidden" name="id" id="templateId" value="<?= htmlspecialchars($current_template['id'] ?? '') ?>">
      <label>اسم القالب:</label>
      <input type="text" name="name" id="templateName" required value="<?= htmlspecialchars($current_template['name'] ?? '') ?>" style="width:100%;margin-bottom:8px;">
      <input type="hidden" name="html" id="templateHtml">
      <input type="submit" value="💾 حفظ القالب" style="padding:8px 12px;">
    </form>
  </div>

  <div class="preview-wrapper">
    <h3>معاينة الكرت</h3>
    <div id="cardPreview" class="preview" data-bg="<?= '' /* نُملأ عبر JS عند تحميل قالب */ ?>"></div>

    <div class="small">اسحب العناصر داخل الكرت لتغيير موضعها. الخلفية تُرفع إلى مجلد <code>uploads/</code>.</div>
  </div>

<script>
/* ========== إعدادات JS العامة ========== */
const UPLOAD_ENDPOINT = 'create_template.php?action=upload_bg';
const uploadDirWeb = 'uploads'; // المسار الظاهري المستخدم في HTML عند الحفظ (relative)
let selected = null;

/* ================= دوال مساعدة ================= */
function toHexRgb(rgb) {
  if (!rgb) return '#000000';
  const m = rgb.match(/\d+/g);
  if (!m) return rgb;
  return '#' + m.slice(0,3).map(x => parseInt(x,10).toString(16).padStart(2,'0')).join('');
}

/* --- تهيئة عناصر قابلة للسحب --- */
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

      // قيود بسيطة داخل الكرت
      if (left < 0) left = 0;
      if (top < 0) top = 0;
      if (left > rect.width - el.offsetWidth) left = rect.width - el.offsetWidth;
      if (top > rect.height - el.offsetHeight) top = rect.height - el.offsetHeight;

      el.style.left = left + 'px';
      el.style.top  = top + 'px';
    }

    function onMouseMove(ev) { moveAt(ev.pageX, ev.pageY); }
    document.addEventListener('mousemove', onMouseMove);

    document.addEventListener('mouseup', function upHandler() {
      document.removeEventListener('mousemove', onMouseMove);
      document.removeEventListener('mouseup', upHandler);
    });
  };
  el.ondragstart = () => false;
}

/* تهيئة جميع الحقول داخل الكرت */
function initFields(card) {
  card.querySelectorAll('.field').forEach(f => initDraggable(f, card));
}

/* ========== رفع الخلفية إلى uploads عبر AJAX ========== */
document.getElementById('bgInput').addEventListener('change', async function(e) {
  const file = e.target.files[0];
  if (!file) return alert('لم يتم اختيار ملف.');

  // حد أقصى 6MB (يتحقق أيضًا في السيرفر)
  if (file.size > 6 * 1024 * 1024) {
    return alert('حجم الملف أكبر من 6MB.');
  }

  const fd = new FormData();
  fd.append('bgfile', file);
  try {
    const resp = await fetch(UPLOAD_ENDPOINT, { method: 'POST', body: fd });
    const json = await resp.json();
    if (!json.success) {
      alert('خطأ في رفع الصورة: ' + (json.error || 'خطأ غير معروف'));
      return;
    }
    // اسم الملف فقط
    const filename = json.filename;
    // قم بتعيين الخلفية من uploads/filename
    const card = document.getElementById('cardPreview');
    card.style.backgroundImage = `url('${uploadDirWeb}/${filename}')`;
    card.dataset.bg = filename; // خزن اسم الملف للاستخدام عند الحفظ
  } catch (err) {
    console.error(err);
    alert('حدث خطأ أثناء رفع الصورة.');
  }
});

/* ========== إدارة الحقول (إظهار/إخفاء وإضافة) ========== */
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

/* تعديل الحجم واللون */
document.getElementById('fontSize').addEventListener('input', function() {
  if (selected) selected.style.fontSize = this.value + 'px';
});
document.getElementById('fontColor').addEventListener('input', function() {
  if (selected) selected.style.color = this.value;
});

/* حذف العنصر المحدد */
function deleteSelected() {
  if (selected) { selected.remove(); selected = null; }
}

/* إلغاء التحديد بالنقر على الخلفية */
document.getElementById('cardPreview').onclick = function() {
  if (selected) selected.classList.remove('selected');
  selected = null;
};

/* ========== تحميل قالب محفوظ وتطبيعه على المعاينة ========== */
const SAVED_HTML = <?= json_encode($current_template[$htmlColumn] ?? '') ?>;
function hydrateFromSavedHtml(savedHtml) {
  if (!savedHtml) {
    // إذا لم يكن هناك قالب محفوظ، أعرض عناصر مبدئية (placeholders) جاهزة للتعديل
    const card = document.getElementById('cardPreview');
    if (!card.querySelector('#field_username')) {
      const u = document.createElement('div'); u.className='field'; u.id='field_username'; u.textContent='{username}'; u.style.top='6px'; u.style.left='6px'; card.appendChild(u);
    }
    if (!card.querySelector('#field_password')) {
      const p = document.createElement('div'); p.className='field'; p.id='field_password'; p.textContent='{password}'; p.style.top='26px'; p.style.left='6px'; card.appendChild(p);
    }
    if (!card.querySelector('#field_profile')) {
      const pr = document.createElement('div'); pr.className='field'; pr.id='field_profile'; pr.textContent='{profile}'; pr.style.top='42px'; pr.style.left='6px'; card.appendChild(pr);
    }
    initFields(document.getElementById('cardPreview'));
    return;
  }

  // نستخدم عنصر مؤقت لتحليل HTML المحفوظ
  const tmp = document.createElement('div');
  tmp.innerHTML = savedHtml.trim();
  // نحاول إيجاد العنصر الجذري .preview
  const root = tmp.querySelector('.preview') || tmp.firstElementChild;
  if (!root) return;

  const card = document.getElementById('cardPreview');

  // استخرج الخلفية من style inline
  const inlineBg = root.style && root.style.backgroundImage ? root.style.backgroundImage : null;

  // إن لم تكن inline، حاول استخراج عبر regex من الـ HTML المحفوظ (لحالات المسار الموضوع بين علامات اقتباس)
  let matchedFilename = null;
  if (inlineBg && inlineBg !== 'none') {
    // مثال الشكل: url('uploads/abc.png') أو url("uploads/abc.png")
    const m = inlineBg.match(/url\((?:'|")?(.*?)(?:'|")?\)/);
    if (m && m[1]) {
      // ضع الخلفية مباشرة (نستخدم المسار كما هو؛ من المفترض أن يكون uploads/filename)
      card.style.backgroundImage = `url('${m[1]}')`;
      // إذا كان المسار يحتوي uploads/ نأخذ اسم الملف
      const parts = m[1].split('/');
      matchedFilename = parts[parts.length - 1];
      if (matchedFilename) card.dataset.bg = matchedFilename;
    } else {
      // وضع الخلفية كما هي
      card.style.backgroundImage = inlineBg;
    }
  } else {
    // محاولة استخراج background-image من الـ HTML النصي إن لم تكن inline
    const htmlMatch = savedHtml.match(/background-image\s*:\s*url\((?:'|")?(.*?)(?:'|")?\)/i);
    if (htmlMatch && htmlMatch[1]) {
      card.style.backgroundImage = `url('${htmlMatch[1]}')`;
      const parts = htmlMatch[1].split('/');
      const fn = parts[parts.length-1];
      if (fn) card.dataset.bg = fn;
    }
  }

  // استخرج المحتوى الداخلي (الحقول) وضعه داخل الكرت
  card.innerHTML = root.innerHTML;
  initFields(card);
}

/* ========== حفظ القالب (إنشاء HTML المحفوظ الذي سيحتوي على background-image: url('uploads/filename')) ========== */
function saveTemplate() {
  const card = document.getElementById("cardPreview");
  const fields = card.querySelectorAll('.field');
  fields.forEach(f => {
    switch (f.id) {
      case 'field_username': f.textContent = '{username}'; break;
      case 'field_password': f.textContent = '{password}'; break;
      case 'field_id': f.textContent = '{id}'; break;
      case 'field_duration': f.textContent = '{duration}'; break;
    }
  });
  const cardHtml = card.innerHTML;
  const bg = card.style.backgroundImage;
  const wrapper = `<div class="preview" style="background-image:${bg}; width:200px; height:54px;">${cardHtml}</div>`;
  document.getElementById("templateHtml").value = wrapper;
  return true;
}

/* ========== تحميل القالب المحفوظ عند فتح الصفحة ========== */
hydrateFromSavedHtml(SAVED_HTML);

/* ========== تهيئة إعدادات الأزرار والحقول المُبدئية ======= */
(function(){
  // اجعل أي عناصر تم تحميلها قابلة للسحب
  initFields(document.getElementById('cardPreview'));
})();
</script>

</body>
</html>
