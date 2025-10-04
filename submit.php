<?php
/* ============ الإعدادات ============ */
$TO_EMAIL   = "mshlool684@gmail.com";   // بريد الاستقبال
$SITE_NAME  = "Qannas Safety Consulting";
$UPLOAD_DIR = __DIR__ . "/uploads";
$WA_INTL    = "962790986908";          // 0790986908 بصيغة دولية
$MAX_MB     = 6;                       // الحد الأقصى للمرفق
$ALLOWED_EXT = ['png','jpg','jpeg','pdf','webp']; // الامتدادات المسموحة

// طرق الدفع المعتمدة
$PAY_MAP = [
  'bank'  => ['title'=>'تحويل بنكي', 'label'=>'رقم الحساب',    'value'=>'0116-631646-500'],
  'alias' => ['title'=>'كليك (CliQ)', 'label'=>'الاسم المستعار','value'=>'ASRQ310'],
  'zain'  => ['title'=>'زين كاش',     'label'=>'رقم زين كاش',   'value'=>'0790986908'],
];

if (!is_dir($UPLOAD_DIR)) { @mkdir($UPLOAD_DIR, 0755, true); }

/* ============ مضاد سبام (Honeypot) ============ */
if (!empty($_POST['website'])) { error_page("Spam detected. العملية مرفوضة."); }

/* ============ قراءة الحقول ============ */
function val($k){ return isset($_POST[$k]) ? trim($_POST[$k]) : ""; }
function safe_str($s){ return str_replace(["\r","\n"], ' ', $s); }

$item          = val("item");
$price         = val("price");
$company       = val("company");
$company_phone = val("company_phone");
$contact_name  = val("contact_name");
$contact_phone = val("contact_phone");
$email         = val("email");
$pref          = val("pref");
$notes         = val("notes");
$pm_key        = val("payment_method");           // bank | alias | zain
$pm            = $PAY_MAP[$pm_key] ?? $PAY_MAP['bank'];

/* تحقق أساسي */
if ($company==="" || $company_phone==="" || $contact_name==="" || $contact_phone==="" || $email==="") {
  error_page("يرجى تعبئة جميع الحقول المطلوبة: اسم الشركة، رقم الشركة، اسم المسؤول، رقم هاتفه، البريد.");
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  error_page("صيغة البريد الإلكتروني غير صحيحة.");
}

/* ============ رفع الملف مع فحوصات ============ */
$attachPath = ""; $attachName = ""; $attachMime = "application/octet-stream";
if (empty($_FILES["receipt"]["name"]) || $_FILES["receipt"]["error"] !== UPLOAD_ERR_OK) {
  error_page("يجب إرفاق إيصال الدفع.");
}
$size = $_FILES["receipt"]["size"] ?? 0;
if ($size <= 0 || $size > $MAX_MB*1024*1024) {
  error_page("حجم الملف يتجاوز الحد الأقصى ({$MAX_MB}MB).");
}
$ext = strtolower(pathinfo($_FILES["receipt"]["name"], PATHINFO_EXTENSION));
if (!in_array($ext, $ALLOWED_EXT, true)) {
  error_page("نوع الملف غير مسموح. الأنواع المتاحة: ".implode(', ', $ALLOWED_EXT));
}

// فحص MIME الحقيقي
if (function_exists('finfo_open')) {
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  if ($finfo) {
    $attachMime = finfo_file($finfo, $_FILES["receipt"]["tmp_name"]) ?: $attachMime;
    finfo_close($finfo);
  }
}

$safeExt  = preg_replace("/[^a-z0-9]/","",$ext);
$safeName = "receipt_" . time() . "_" . bin2hex(random_bytes(3)) . "." . $safeExt;
$dest = $UPLOAD_DIR . "/" . $safeName;
if (!move_uploaded_file($_FILES["receipt"]["tmp_name"], $dest)) {
  error_page("تعذّر رفع الملف، حاول مجددًا.");
}
$attachPath = $dest; 
$attachName = basename($_FILES["receipt"]["name"]);

/* ============ بناء الرسالة ============ */
$subject = "طلب جديد: " . safe_str($item);
$lines = [
  "طلب خدمة جديدة من $SITE_NAME",
  "الخدمة: $item",
  "القيمة: " . ($price !== "" ? $price." د.أ" : "-"),
  "طريقة الدفع: {$pm['title']}",
  "{$pm['label']}: {$pm['value']}",
  "اسم الشركة: $company",
  "رقم الشركة (هاتف): $company_phone",
  "اسم مسؤول المتابعة: $contact_name",
  "هاتف المسؤول: $contact_phone",
  "البريد: $email",
  "تواصل مفضّل: " . ($pref ?: "-"),
  "ملاحظات:\n" . ($notes ?: "-"),
  "",
  "IP: " . ($_SERVER['REMOTE_ADDR'] ?? '-') . " | UA: " . ($_SERVER['HTTP_USER_AGENT'] ?? '-'),
];
$bodyText = implode("\n", $lines);

/* ============ إرسال الإيميل بالمرفق ============ */
$boundary = md5(uniqid(time(), true));
$headers  = "From: $SITE_NAME <no-reply@yourdomain.com>\r\n";
$headers .= "Reply-To: ".safe_str($email)."\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/mixed; boundary=\"".$boundary."\"";

$message  = "--$boundary\r\n";
$message .= "Content-Type: text/plain; charset=utf-8\r\n";
$message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$message .= $bodyText . "\n\n";
$message .= "--$boundary\r\n";
$fileData = chunk_split(base64_encode(file_get_contents($attachPath)));
$message .= "Content-Type: ".safe_str($attachMime)."; name=\"".safe_str($attachName)."\"\r\n";
$message .= "Content-Transfer-Encoding: base64\r\n";
$message .= "Content-Disposition: attachment; filename=\"".safe_str($attachName)."\"\r\n\r\n";
$message .= $fileData . "\r\n";
$message .= "--$boundary--";

// إرسال (بدون التأكد من النتيجة حتى لا نكشف التهيئة للسيرفر للمستخدم النهائي)
@mail($TO_EMAIL, "=?UTF-8?B?".base64_encode($subject)."?=", $message, $headers);

/* ============ واتساب جاهز ============ */
$waText = "مرحبًا، تم إرسال طلبي لخدمة: $item بقيمة ".($price!==""?$price." د.أ":"-")
        . ". طريقة الدفع: {$pm['title']} ({$pm['label']}: {$pm['value']})."
        . " الشركة: $company (هاتف: $company_phone)."
        . " المسؤول: $contact_name (هاتف: $contact_phone). البريد: $email.";

/* ============ صفحة الشكر ============ */
success_page($bodyText, $TO_EMAIL, $WA_INTL, $waText, $attachPath);

/* --------- دوال مساعدة --------- */
function error_page($msg){
  http_response_code(400);
  echo "<!doctype html><meta charset='utf-8'><title>خطأ</title>
  <div style='font-family:Cairo,system-ui;max-width:720px;margin:40px auto;padding:20px;border:1px solid #e2e8f0;border-radius:14px'>
  <h3>تعذّر إرسال الطلب</h3><p style='color:#555'>".$msg."</p>
  <a href='checkout.html' style='display:inline-block;margin-top:10px;padding:10px 14px;border:1px solid #cfd7e6;border-radius:10px;text-decoration:none'>عودة</a>
  </div>";
  exit;
}

function success_page($bodyText,$to,$waIntl,$waText,$attachPath){
  ?>
  <!doctype html>
  <html lang="ar" dir="rtl">
  <head>
    <meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>تم الإرسال — شكراً</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
      body{margin:0; font-family:"Cairo",system-ui; background:#eef3fa; color:#0e1623}
      .wrap{max-width:760px; margin:auto; padding:32px 20px}
      .panel{background:#fff; border:1px solid #cfd7e6; border-radius:16px; padding:20px; box-shadow:0 10px 30px rgba(20,40,80,.08)}
      .btn{display:inline-flex; align-items:center; gap:8px; padding:12px 16px; border-radius:12px; font-weight:800; border:1px solid transparent; text-decoration:none}
      .btn-wa{background:linear-gradient(135deg,#25D366,#20bf5a); color:#fff}
      .btn-ghost{background:#fff; border:1px solid #cfd7e6; color:#0e1623}
      .muted{color:#4c5a70}
      pre{white-space:pre-wrap; background:#f7f9fd; border:1px solid #e2e8f0; padding:12px; border-radius:12px}
    </style>
  </head>
  <body>
    <div class="wrap">
      <div class="panel">
        <h1>شكرًا، تم استلام طلبك 🎉</h1>
        <p class="muted">وصلتنا التفاصيل على البريد: <strong><?php echo htmlspecialchars($to); ?></strong><?php
          if($attachPath){ echo " (تم حفظ المرفق بنجاح)."; } ?></p>
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin:10px 0 16px">
          <a class="btn btn-wa" href="<?php echo 'https://wa.me/'.$waIntl.'?text='.urlencode($waText); ?>" target="_blank" rel="noopener">إرسال تأكيد عبر واتساب</a>
          <a class="btn btn-ghost" href="index.html">عودة للصفحة الرئيسية</a>
        </div>
        <h3>ملخص الطلب</h3>
        <pre><?php echo htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'); ?></pre>
      </div>
    </div>
  </body>
  </html>
  <?php
}
