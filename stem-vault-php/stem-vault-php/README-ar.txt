STEM Vault — نسخة PHP
=====================

المحتويات:
  index.html   الواجهة
  api.php      السيرفر (الحسابات + كود التحقق + الملفات)
  config.php   الإعدادات (عدّل فيه فقط)
  .htaccess / .user.ini   رفع الحد الأقصى لحجم الملفات
  storage/     قاعدة البيانات والملفات (بتتعمل تلقائي)

التركيب:
  1) ارفع كل الملفات (بما فيها الملفات اللي بتبدأ بنقطة) على الاستضافة في public_html.
     لازم PHP 7.4 أو أحدث مع pdo_sqlite (موجود في أغلب الاستضافات).
  2) افتح config.php:
       - owner_email: إيميلك (هو اللي هيبقى مالك).
       - max_upload_mb: أقصى حجم للملف (الحد الفعلي حسب إعدادات الاستضافة).
       - mail_from: إيميل على نفس دومين الموقع (لو هتبعت الكود بـ mail()).
       - أو لإرسال الكود بـ EmailJS: حط emailjs.private_key
         وفعّل في EmailJS: Account > Security > Allow EmailJS API for non-browser applications.
  3) افتح الموقع، وسجّل حساب بإيميل المالك.

مهم:
  - لو الاستضافة nginx (مش Apache) لازم تمنع الوصول لمجلد storage من إعدادات السيرفر،
    أو الأفضل تنقل storage برا public_html وتغيّر storage_dir في config.php.
  - البيانات القديمة اللي على jsonbin/Cloudinary ما بتتنقلش، النسخة دي بتبدأ من الصفر.
  - على الاستضافة اتركها debug_otp = false.
