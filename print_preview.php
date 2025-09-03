<?php
// الاتصال بقاعدة SQLite
$db = new PDO('sqlite:database.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

session_start();

if (isset($_GET['preview'])) {
    $vouchers = $_SESSION['preview_vouchers'] ?? [];
    $template_html = $_SESSION['preview_template'] ?? "<div class='card'>{username}</div>";
} else {
    // الوضع الطبيعي للدفعات الحقيقية
    // ... الكود السابق لجلب الدفعة من SQLite

// جلب آخر دفعة (أو من خلال GET)
$batchId = isset($_GET['batch']) ? (int)$_GET['batch'] : 0;

if ($batchId == 0) {
    // جلب آخر دفعة
    $batch = $db->query("SELECT * FROM batches ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$batch) die("❌ لا يوجد دفعات بعد!");
    $batchId = $batch['id'];
} else {
    $batch = $db->query("SELECT * FROM batches WHERE id=$batchId")->fetch(PDO::FETCH_ASSOC);
}

// جلب القالب
$template = $db->query("SELECT * FROM templates WHERE id=".$batch['template_id'])->fetch(PDO::FETCH_ASSOC);
if (!$template) die("❌ لم يتم العثور على القالب");

// جلب الكروت
$stmt = $db->prepare("SELECT v.*, p.name as profile_name FROM vouchers v 
    LEFT JOIN profiles p ON v.profile_id = p.id
    WHERE batch_id=?");
$stmt->execute([$batchId]);
$vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// HTML القالب
$template_html = $template['html'];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>معاينة الطباعة</title>
    <style>
        body { font-family: Tahoma, sans-serif; background:#f8f9fa; }
        .print-area { display:flex; flex-wrap:wrap; gap:10px; padding:20px; }
        .card, .small { border:1px solid #ccc; padding:10px; border-radius:8px; background:#fff; }
        .card { width:180px; text-align:center; }
        .small { width:120px; font-size:14px; }
        @media print {
            body * { visibility:hidden; }
            .print-area, .print-area * { visibility:visible; }
            .print-area { gap:0; }
        }
    </style>
</head>
<body>

<h2 style="text-align:center;">معاينة طباعة - دفعة <?= htmlspecialchars($batch['code']) ?></h2>
<div class="print-area">
    <?php
    $columns = 4; $margin_mm=2; 
    $card_width_mm=(210/$columns)-($margin_mm*2);
    foreach($vouchers as $v):
        $html = str_replace(
            ["{username}","{password}","{profile}","{id}","{duration}"],
            [
                htmlspecialchars($v['username']),
                htmlspecialchars($v['password']),
                htmlspecialchars($v['profile_name']),
                htmlspecialchars($v['id']),
                // htmlspecialchars($v['validity_days'])
            ],
            $template_html
        );
        echo '<div class="print-card" style="width:'.$card_width_mm.'mm; margin:'.$margin_mm.'mm;">'.$html.'</div>';
    endforeach;
    ?>
</div>

<div style="text-align:center; margin:20px;">
    <button onclick="window.print()">🖨️ طباعة</button>
</div>

<style>
.print-area { display:flex; flex-wrap:wrap; width:210mm; margin:auto; }
.print-card { page-break-inside:avoid; }
@media print {
    body * { visibility:hidden; }
    .print-area, .print-area * { visibility:visible; }
    .print-area { display:flex; flex-wrap:wrap; width:210mm; margin:auto; }
    .print-card { page-break-inside:avoid; }
}
</style>


</body>
</html>
