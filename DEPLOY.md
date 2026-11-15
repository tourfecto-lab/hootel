# نشر موقع Kings Palace Hotel (aaPanel / Nginx)

الموقع **منفصل تمامًا** عن Tourfecto: موقع جديد بدومين جديد، ولا يلمس أي ملف أو قاعدة بيانات للمنصة.

## 0) قبل البدء
- اشترِ الدومين (مثلًا `kingspalacehotel.online`) باسم صاحب الفندق.
- في DNS الدومين: سجل `A` للجذر و`www` يشير إلى IP السيرفر. (لو الدومين على Cloudflare: اتركه DNS only أثناء إصدار الشهادة.)

## 1) إنشاء الموقع في aaPanel
1. **Website → Add site** → الدومين `kingspalacehotel.online` (+ `www`)، PHP **8.2 أو 8.4** (تأكد من تثبيت الإضافات لنفس الإصدار)، من غير قاعدة بيانات (الموقع بيستخدم SQLite).
2. افتح إعدادات الموقع → **Site directory**:
   - ارفع محتوى الحزمة `kings-palace-site-deploy.zip` داخل `/www/wwwroot/kingspalacehotel.online/` وفك الضغط.
   - **Running directory** = `/public` (مهم جدًا: `app/` و`data/` يجب أن يبقوا خارج المسار العام).
   - عطّل **Anti-XSS attack / Anti-CSRF open_basedir** لو منع كتابة `data/` (أو اضبط open_basedir ليشمل مجلد الموقع كله).
3. **PHP 8.2 → Install extensions**: تأكد من `fileinfo` و`gd` و`curl` و`mbstring` و`pdo_sqlite` (SQLite3).

## 2) قاعدة Nginx (aaPanel لا يقرأ .htaccess)
**Website → Config → URL rewrite** والصق:
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
# لا تنفذ أي PHP داخل uploads
location ~* ^/uploads/.*\.(php|phtml|phar)$ {
    deny all;
}
# كاش للملفات الثابتة
location ~* \.(jpg|jpeg|png|webp|css|js)$ {
    expires 30d;
    access_log off;
}
```

## 3) الصلاحيات
```bash
cd /www/wwwroot/kingspalacehotel.online
chown -R www:www data public/uploads
chmod -R 750 data
chmod -R 755 public/uploads
```

## 4) كلمة سر الأدمن (إجباري)
الحزمة فيها نسخة قاعدة بيانات نظيفة (بدون طلبات حجز). **غيّر كلمة السر فورًا**:
```bash
cd /www/wwwroot/kingspalacehotel.online
sudo -u www php install.php --reset-admin
```
سيطبع كلمة سر جديدة مرة واحدة. سجّل الدخول من `https://الدومين/admin` ثم غيّرها من **Account**.

## 5) SSL
aaPanel → الموقع → **SSL → Let's Encrypt** → فعّل الدومين و`www` → **Force HTTPS**.

## 6) إعدادات الموقع (من لوحة الأدمن → Content & settings)
- **Public site URL**: `https://kingspalacehotel.online`
- رقم واتساب الحجز، أوقات الدخول والخروج، الإيميل.
- (اختياري) سعر الدولار/اليورو للعرض.

## 7) Cron لمزامنة المنصات (كل 15 دقيقة)
aaPanel → **Cron → Shell Script**:
```bash
*/15 * * * * cd /www/wwwroot/kingspalacehotel.online && /www/server/php/84/bin/php cron/sync_ical.php >> /dev/null 2>&1
```
> لا تحذف أو تعدّل مهام الـ cron الخاصة بـ Tourfecto. هذه مهمة منفصلة.

## 8) فحص بعد النشر
- `https://الدومين/` يحوّل للغة المتصفح، وكل لغة تعمل (`/ar/`, `/de/`, ...).
- صفحة غرفة، صفحة الأسئلة، التواصل.
- أرسل طلب حجز تجريبي ثم احذفه من الأدمن.
- `https://الدومين/sitemap.xml` و`/robots.txt`.
- أرسل الـ sitemap إلى **Google Search Console**.
- حدّث رابط الموقع في **Google Business Profile** بالدومين الجديد.

## 9) نسخ احتياطي
من الأدمن → **Backup** (قاعدة البيانات) + انسخ `public/uploads/` دوريًا.

## تحديث الموقع لاحقًا
ارفع ملفات `app/` و`public/` الجديدة فقط. **لا تستبدل** `data/` ولا `public/uploads/` لأنهما فيهما بيانات الفندق.

## النشر عبر Git (لو المستودع الخاص لا يظهر في aaPanel)
قائمة المستودعات في aaPanel بتعرض فقط المستودعات اللي صلاحية ربط GitHub بتشوفها. لو `hootel` (خاص) مش ظاهر:
1. **أسهل حل:** تجاهل Git وارفع `kings-palace-site-deploy.zip` (الخطوة 1).
2. أو في GitHub: *Settings → Applications* وافتح تطبيق aaPanel وأعطه صلاحية على `hootel` (Repository access)، ثم في aaPanel اضغط **Unbind** ثم اربط من جديد.
3. أو اختر **Repository URL** واستخدم رابط SSH `git@github.com:tourfecto-lab/hootel.git` مع **Deploy key**: انسخ المفتاح العام من aaPanel وأضفه في GitHub: *hootel → Settings → Deploy keys* (للقراءة فقط).

بعد أي نشر عبر Git: انسخ `data/site.sqlite` من الحزمة إلى مجلد `data/` (قاعدة البيانات غير موجودة في Git عمدًا) ثم نفّذ الخطوة 4.
