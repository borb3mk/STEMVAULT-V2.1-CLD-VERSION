<?php
// إعدادات STEM Vault — عدّل القيم هنا فقط.
return [
    // الإيميل اللي بيبقى "المالك" (لوحة التحكم + حذف أي ملف)
    'owner_email' => 'hobazeema900@gmail.com',

    // أقصى حجم للملف بالميجا. الحد الفعلي هو الأصغر بين الرقم ده وإعدادات PHP في الاستضافة.
    'max_upload_mb' => 50,

    // مكان تخزين قاعدة البيانات والملفات. الأفضل تنقله برا مجلد الموقع (public_html)
    // مثال: '/home/USERNAME/stem-storage'
    'storage_dir' => __DIR__ . '/storage',

    // إرسال كود التحقق: بيجرب EmailJS الأول لو الـ private_key موجود، وإلا بيستخدم mail() بتاعة الاستضافة.
    'emailjs' => [
        'service_id'  => 'service_nzthyi8',
        'template_id' => 'template_rsook3v',
        'public_key'  => '1iW1iRr1jl5aiKV-5',
        // من EmailJS: Account → API Keys → Private Key
        // ولازم تفعّل: Account → Security → "Allow EmailJS API for non-browser applications"
        'private_key' => '',
    ],

    // عنوان المرسل لو الإرسال هيتم بـ mail() (استخدم إيميل على نفس دومين الموقع)
    'mail_from' => 'no-reply@example.com',

    // صلاحية كود التحقق بالثواني
    'otp_ttl' => 600,

    // للتجربة على جهازك فقط (localhost): بيرجّع الكود في الرد بدل الإيميل. اتركها false على الاستضافة.
    'debug_otp' => false,
];
