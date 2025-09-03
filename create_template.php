<?php
$db = new PDO("sqlite:database.db");

// ✅ تحميل القالب المطلوب للتعديل
$template_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$current_template = null;
if ($template_id > 0) {
    $stmt = $db->prepare("SELECT * FROM templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $current_template = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ✅ حفظ أو تحديث القالب
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'], $_POST['html'])) {
    if (!empty($_POST['id'])) {
        // تحديث
        $stmt = $db->prepare("UPDATE templates SET name = :name, html = :html WHERE id = :id");
        $stmt->execute([
            ':name' => $_POST['name'],
            ':html' => $_POST['html'],
            ':id'   => $_POST['id']
        ]);
        echo "<p style='color:green'>✅ تم تحديث القالب بنجاح!</p>";
    } else {
        // إضافة جديد
        $stmt = $db->prepare("INSERT INTO templates (name, html) VALUES (:name, :html)");
        $stmt->execute([
            ':name' => $_POST['name'],
            ':html' => $_POST['html']
        ]);
        $template_id = $db->lastInsertId();
        echo "<p style='color:green'>✅ تم حفظ القالب بنجاح!</p>";
    }
    // إعادة تحميل الصفحة مع ID القالب
    header("Location: ?id=" . ($template_id ?: $_POST['id']));
    exit;
}

// ✅ جلب جميع القوالب للعرض في القائمة
$templates = $db->query("SELECT id, name FROM templates ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ar">
<head>
  <meta charset="UTF-8">
  <title>تصميم كروت الشبكة</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      direction: rtl;
      display: flex;
      gap: 20px;
    }

    /* لوحة التحكم */
    .controls {
      width: 250px;
      padding: 10px;
      border: 1px solid #ccc;
      border-radius: 10px;
    }

    /* قائمة القوالب */
    .templates-list {
      margin-bottom: 15px;
      max-height: 200px;
      overflow-y: auto;
      border: 1px solid #ddd;
      padding: 5px;
      border-radius: 5px;
    }
    .templates-list a {
      display: block;
      padding: 5px;
      text-decoration: none;
      color: #333;
    }
    .templates-list a:hover {
      background: #f0f0f0;
    }

    /* معاينة الكرت */
    .preview {
      width: 200px;
      height: 54px;
      border: 1px solid #000;
      border-radius: 5px;
      position: relative;
      background-size: cover;
      background-position: center;
      overflow: hidden;
    }

    .field {
      position: absolute;
      font-size: 10px;
      color: #000;
      background: rgba(255,255,255,0.6);
      padding: 1px 3px;
      border-radius: 3px;
      cursor: move;
    }

    .field.selected {
      outline: 1px dashed red;
    }
  </style>
</head>
<body>

  <!-- لوحة التحكم -->
  <div class="controls">
    <h3>📂 القوالب المحفوظة</h3>
    <div class="templates-list">
      <?php foreach ($templates as $t): ?>
        <a href="?id=<?= $t['id'] ?>" <?= ($template_id == $t['id']) ? 'style="font-weight:bold;"' : '' ?>>
          <?= htmlspecialchars($t['name']) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <h3>خيارات التصميم</h3>
    <label>خلفية الكرت:</label>
    <input type="file" id="bgInput" accept="image/*"><br><br>

    <button type="button" onclick="toggleField('username')">إظهار/إخفاء اسم المستخدم</button><br><br>
    <button type="button" onclick="toggleField('password')">إظهار/إخفاء كلمة المرور</button><br><br>
    <button type="button" onclick="toggleField('id')">إظهار/إخفاء ID</button><br><br>
    <button type="button" onclick="toggleField('duration')">إظهار/إخفاء المدة</button><br><br>

    <hr>
    <h4>تعديل العنصر المحدد</h4>
    <label>الحجم:</label>
    <input type="number" id="fontSize" min="8" max="30" value="10"><br><br>
    <label>اللون:</label>
    <input type="color" id="fontColor" value="#000000"><br><br>
    <button type="button" onclick="deleteSelected()">🗑️ حذف العنصر</button>

    <hr>
    <h4>حفظ القالب</h4>
    <form method="POST" onsubmit="return saveTemplate()">
      <input type="hidden" name="id" value="<?= htmlspecialchars($current_template['id'] ?? '') ?>">
      <label>اسم القالب:</label><br>
      <input type="text" name="name" id="templateName" required value="<?= htmlspecialchars($current_template['name'] ?? '') ?>"><br><br>
      <input type="hidden" name="html" id="templateHtml">
      <button type="submit">💾 حفظ القالب</button>
    </form>
  </div>

  <!-- معاينة الكرت -->
  <div class="preview" id="cardPreview">
    <?= $current_template['html'] ?? '' ?>
  </div>

<script>
  let selected = null;

  // تحميل الخلفية وتحويلها ل Base64
  document.getElementById("bgInput").addEventListener("change", function(e){
      const file = e.target.files[0];
      if(!file) return;
      const reader = new FileReader();
      reader.onload = function(evt){
          document.getElementById("cardPreview").style.backgroundImage = `url(${evt.target.result})`;
      };
      reader.readAsDataURL(file);
  });

  // إضافة أو إخفاء عنصر
  function toggleField(type) {
    const card = document.getElementById("cardPreview");
    let el = document.getElementById("field_" + type);

    if (el) {
      el.remove();
      if (selected === el) selected = null;
    } else {
      const div = document.createElement("div");
      div.classList.add("field");
      div.id = "field_" + type;
      div.textContent = type.toUpperCase();
      div.style.top = "5px";
      div.style.left = "5px";

      div.onclick = function(e) {
        e.stopPropagation();
        if (selected) selected.classList.remove("selected");
        selected = div;
        selected.classList.add("selected");

        document.getElementById("fontSize").value = parseInt(getComputedStyle(div).fontSize);
        document.getElementById("fontColor").value = rgbToHex(getComputedStyle(div).color);
      };

      div.onmousedown = function(e) {
        if (e.target !== div) return;
        let shiftX = e.clientX - div.getBoundingClientRect().left;
        let shiftY = e.clientY - div.getBoundingClientRect().top;

        function moveAt(pageX, pageY) {
          div.style.left = pageX - card.getBoundingClientRect().left - shiftX + 'px';
          div.style.top = pageY - card.getBoundingClientRect().top - shiftY + 'px';
        }

        function onMouseMove(event) {
          moveAt(event.pageX, event.pageY);
        }

        document.addEventListener('mousemove', onMouseMove);
        div.onmouseup = function() {
          document.removeEventListener('mousemove', onMouseMove);
          div.onmouseup = null;
        };
      };

      div.ondragstart = () => false;
      card.appendChild(div);
    }
  }

  // تعديل الحجم واللون
  document.getElementById("fontSize").addEventListener("input", function() {
    if (selected) selected.style.fontSize = this.value + "px";
  });

  document.getElementById("fontColor").addEventListener("input", function() {
    if (selected) selected.style.color = this.value;
  });

  // حذف العنصر المحدد
  function deleteSelected() {
    if (selected) {
      selected.remove();
      selected = null;
    }
  }

  // تحويل RGB إلى HEX
  function rgbToHex(rgb) {
    const result = rgb.match(/\d+/g).map(Number);
    return "#" + result.map(x => x.toString(16).padStart(2, '0')).join('');
  }

  // إلغاء التحديد عند الضغط على الخلفية
  document.getElementById("cardPreview").onclick = function() {
    if (selected) selected.classList.remove("selected");
    selected = null;
  };

  // عند اختيار قالب من القائمة
function loadTemplate(id){
    fetch("?loadTemplate=" + id)
      .then(res => res.json())
      .then(data => {
          document.getElementById("templateName").value = data.name;

          // إدخال الـ HTML داخل cardPreview
          const card = document.getElementById("cardPreview");
          card.innerHTML = data.html;

          // استخراج الخلفية إذا موجودة
          const match = data.html.match(/background-image:\s*url\((.*?)\)/);
          if(match && match[1]){
              card.style.backgroundImage = `url(${match[1]})`;
          }

          // تجهيز الأحداث لعناصر القالب
          initFields(card);
      });
}


  // حفظ القالب مع placeholders وخلفية Base64
function saveTemplate(){
    const card = document.getElementById("cardPreview");
    const fields = card.querySelectorAll('.field');

    fields.forEach(f=>{
        switch(f.id){
            case 'field_username': f.textContent='{username}'; break;
            case 'field_password': f.textContent='{password}'; break;
            case 'field_id': f.textContent='{id}'; break;
            case 'field_duration': f.textContent='{duration}'; break;
            case 'field_profile': f.textContent='{profile}'; break;
        }
    });

    const cardHtml = card.innerHTML;
    const bg = card.style.backgroundImage; // ← نقرأ الخلفية الحالية
    const wrapper = `<div class="preview" style="background-image:${bg}; width:200px; height:54px;">${cardHtml}</div>`;
    
    document.getElementById("templateHtml").value = wrapper;

    // بعد الحفظ نخلي المعاينة ترجع تعرض الخلفية
    card.style.backgroundImage = bg;

    return true;
}

</script>

</body>
</html>
