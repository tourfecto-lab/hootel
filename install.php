<?php
/**
 * One-time setup:  php install.php
 * Creates the database, seeds the hotel content, and creates the admin account
 * with a random password that is printed ONCE here (not stored anywhere in plain text).
 * Safe to re-run: it never overwrites existing content; use --reset-admin to issue a new password.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/app/bootstrap.php';
$pdo = db();

function L(string ...$v): string
{
    $k = ['ar', 'en', 'de', 'fr', 'it', 'es', 'ru', 'zh'];
    return json_encode(array_combine($k, $v), JSON_UNESCAPED_UNICODE);
}

$seeded = (int)$pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn() > 0;
if (!$seeded) {
    $rooms = [
        ['single', 700, 'room', 1, 2,
            L('غرفة سنجل', 'Single Room', 'Einzelzimmer', 'Chambre simple', 'Camera singola', 'Habitación individual', 'Одноместный номер', '单人间'),
            L('غرفة لفرد واحد بحمام داخلي.', 'A private room for one guest with en-suite bathroom.', 'Privatzimmer für eine Person mit eigenem Bad.', 'Chambre privée pour une personne avec salle de bain.', 'Camera privata per una persona con bagno interno.', 'Habitación privada para una persona con baño propio.', 'Отдельный номер для одного гостя с собственной ванной.', '带独立卫生间的单人私人房间。')],
        ['double', 900, 'room', 2, 4,
            L('غرفة دبل', 'Double Room', 'Doppelzimmer', 'Chambre double', 'Camera doppia', 'Habitación doble', 'Двухместный номер', '双人间'),
            L('غرفة لشخصين بحمام داخلي.', 'A private room for two guests with en-suite bathroom.', 'Privatzimmer für zwei Personen mit eigenem Bad.', 'Chambre privée pour deux personnes avec salle de bain.', 'Camera privata per due persone con bagno interno.', 'Habitación privada para dos personas con baño propio.', 'Отдельный номер для двух гостей с собственной ванной.', '带独立卫生间的双人私人房间。')],
        ['triple', 1300, 'room', 3, 5,
            L('غرفة تربل', 'Triple Room', 'Dreibettzimmer', 'Chambre triple', 'Camera tripla', 'Habitación triple', 'Трёхместный номер', '三人间'),
            L('غرفة لثلاثة أشخاص بحمام داخلي، مناسبة للأصدقاء والعائلات الصغيرة.', 'A private room for three guests with en-suite bathroom, ideal for friends or small families.', 'Privatzimmer für drei Personen mit eigenem Bad, ideal für Freunde oder kleine Familien.', 'Chambre privée pour trois personnes avec salle de bain, idéale pour amis ou petites familles.', 'Camera privata per tre persone con bagno interno, ideale per amici o piccole famiglie.', 'Habitación privada para tres personas con baño propio, ideal para amigos o familias pequeñas.', 'Отдельный номер для троих с собственной ванной, подходит для друзей или небольшой семьи.', '带独立卫生间的三人私人房间，适合朋友或小家庭。')],
        ['dorm-male', 300, 'bed', 1, 1,
            L('سرير في غرفة مشتركة (شباب)', 'Bed in Male Dorm', 'Bett im Männerschlafsaal', 'Lit en dortoir hommes', 'Letto in dormitorio maschile', 'Cama en dormitorio masculino', 'Место в мужском общем номере', '男生多人间床位'),
            L('سرير في غرفة مشتركة للشباب فقط.', 'A bed in a male-only shared dorm.', 'Ein Bett in einem reinen Männerschlafsaal.', 'Un lit dans un dortoir réservé aux hommes.', 'Un letto in un dormitorio solo maschile.', 'Una cama en un dormitorio solo para hombres.', 'Место в общем номере только для мужчин.', '仅限男性的多人间床位。')],
        ['dorm-female', 300, 'bed', 1, 1,
            L('سرير في غرفة مشتركة (بنات)', 'Bed in Female Dorm', 'Bett im Frauenschlafsaal', 'Lit en dortoir femmes', 'Letto in dormitorio femminile', 'Cama en dormitorio femenino', 'Место в женском общем номере', '女生多人间床位'),
            L('سرير في غرفة مشتركة للبنات فقط.', 'A bed in a female-only shared dorm.', 'Ein Bett in einem reinen Frauenschlafsaal.', 'Un lit dans un dortoir réservé aux femmes.', 'Un letto in un dormitorio solo femminile.', 'Una cama en un dormitorio solo para mujeres.', 'Место в общем номере только для женщин.', '仅限女性的多人间床位。')],
    ];
    $st = $pdo->prepare('INSERT INTO rooms(slug, price, unit, guests, qty, sort, name_json, desc_json) VALUES(?,?,?,?,?,?,?,?)');
    foreach ($rooms as $i => $r) {
        $st->execute([$r[0], $r[1], $r[2], $r[3], $r[4], $i, $r[5], $r[6]]);
    }

    // Amenities start UNCONFIRMED: they only show on the site after the owner confirms each one.
    $am = [
        ['wifi', '📶', L('واي فاي', 'Wi-Fi', 'WLAN', 'Wi-Fi', 'Wi-Fi', 'Wi-Fi', 'Wi-Fi', '无线网络')],
        ['ac', '❄️', L('تكييف', 'Air conditioning', 'Klimaanlage', 'Climatisation', 'Aria condizionata', 'Aire acondicionado', 'Кондиционер', '空调')],
        ['breakfast', '🍳', L('فطار', 'Breakfast', 'Frühstück', 'Petit-déjeuner', 'Colazione', 'Desayuno', 'Завтрак', '早餐')],
        ['restaurant', '🍽️', L('مطعم', 'Restaurant', 'Restaurant', 'Restaurant', 'Ristorante', 'Restaurante', 'Ресторан', '餐厅')],
        ['laundry', '🧺', L('خدمة غسيل', 'Laundry service', 'Wäscheservice', 'Service de blanchisserie', 'Servizio lavanderia', 'Servicio de lavandería', 'Прачечная', '洗衣服务')],
        ['elevator', '🛗', L('أسانسير', 'Elevator', 'Aufzug', 'Ascenseur', 'Ascensore', 'Ascensor', 'Лифт', '电梯')],
        ['reception', '🛎️', L('استقبال 24 ساعة', '24-hour reception', 'Rezeption rund um die Uhr', 'Réception 24h/24', 'Reception 24 ore', 'Recepción 24 horas', 'Круглосуточная стойка регистрации', '24小时前台')],
        ['pool', '🏊', L('مسبح', 'Pool', 'Pool', 'Piscine', 'Piscina', 'Piscina', 'Бассейн', '游泳池')],
        ['shuttle', '🚐', L('توصيل من المطار', 'Airport shuttle', 'Flughafentransfer', 'Navette aéroport', 'Navetta aeroportuale', 'Traslado al aeropuerto', 'Трансфер из аэропорта', '机场接送')],
    ];
    $st = $pdo->prepare('INSERT INTO amenities(akey, icon, confirmed, sort, label_json) VALUES(?,?,0,?,?)');
    foreach ($am as $i => $a) {
        $st->execute([$a[0], $a[1], $i, $a[2]]);
    }

    $links = [['booking', 'Booking.com'], ['agoda', 'Agoda'], ['expedia', 'Expedia'], ['hostelworld', 'Hostelworld'], ['tripadvisor', 'Tripadvisor'],
        ['google', 'Google Maps'], ['facebook', 'Facebook'], ['instagram', 'Instagram'], ['tiktok', 'TikTok']];
    $st = $pdo->prepare('INSERT INTO links(lkey, label, url, sort) VALUES(?,?,?,?)');
    foreach ($links as $i => $l) {
        $st->execute([$l[0], $l[1], $l[0] === 'google' ? 'https://maps.app.goo.gl/Q5E52QXkkyVTibo1A' : '', $i]);
    }

    $defaults = [
        'name_json' => L('فندق كينجز بالاس', 'Kings Palace Hotel', 'Kings Palace Hotel', 'Kings Palace Hotel', 'Kings Palace Hotel', 'Kings Palace Hotel', 'Kings Palace Hotel', 'Kings Palace Hotel'),
        'tagline_json' => L(
            'إقامة بسيطة ونضيفة في قلب وسط البلد، على بعد دقايق من المتحف المصري',
            'Simple, clean stays in the heart of downtown Cairo, minutes from the Egyptian Museum',
            'Einfache, saubere Unterkunft im Herzen der Kairoer Innenstadt, nur Minuten vom Ägyptischen Museum',
            'Un séjour simple et propre au cœur du centre-ville du Caire, à quelques minutes du Musée égyptien',
            'Soggiorni semplici e puliti nel cuore del centro del Cairo, a pochi minuti dal Museo Egizio',
            'Alojamiento sencillo y limpio en pleno centro de El Cairo, a pocos minutos del Museo Egipcio',
            'Простое и чистое жильё в самом центре Каира, в нескольких минутах от Египетского музея',
            '位于开罗市中心的简洁干净住宿，距埃及博物馆仅几分钟'),
        'address_json' => L('3 شارع إبراهيم القباني، باب اللوق، عابدين، القاهرة', '3 Ibrahim Al Kabani St, Bab Al Louq, Abdeen, Cairo', '3 Ibrahim Al Kabani St, Bab Al Louq, Abdeen, Kairo', '3 Ibrahim Al Kabani St, Bab Al Louq, Abdeen, Le Caire', '3 Ibrahim Al Kabani St, Bab Al Louq, Abdeen, Il Cairo', '3 Ibrahim Al Kabani St, Bab Al Louq, Abdeen, El Cairo', 'ул. Ибрахим аль-Кабани, 3, Баб-эль-Лук, Абдин, Каир', '开罗阿卜丁区巴布卢克 Ibrahim Al Kabani 街 3 号'),
        'phone' => '+20223919374', 'whatsapp' => '', 'email' => '', 'checkin' => '', 'checkout' => '',
        'lat' => '30.0475232', 'lng' => '31.2396991', 'maps_url' => 'https://maps.app.goo.gl/Q5E52QXkkyVTibo1A',
        'enabled_langs' => 'ar,en,de,fr,it,es,ru,zh', 'default_lang' => 'en', 'site_url' => '',
        'metro_min' => '8', 'museum_min' => '11', 'notify_email' => '',
    ];
    foreach ($defaults as $k => $v) {
        set_setting($k, $v);
    }
    echo "Seeded hotel content.\n";
}

// Starter FAQ / policies / nearby places (Arabic + English; other languages fall back to English until translated in Admin)
if ((int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn() === 0) {
    $j = fn(array $a) => json_encode($a, JSON_UNESCAPED_UNICODE);
    $items = [
        ['attraction', ['name' => json_decode(L('المتحف المصري', 'Egyptian Museum', 'Ägyptisches Museum', 'Musée égyptien', 'Museo Egizio', 'Museo Egipcio', 'Египетский музей', '埃及博物馆'), true), 'min' => 11, 'mode' => 'walk', 'note' => []]],
        ['attraction', ['name' => json_decode(L('محطة مترو محمد نجيب', 'Mohamed Naguib metro station', 'U-Bahn-Station Mohamed Naguib', 'Station de métro Mohamed Naguib', 'Stazione metro Mohamed Naguib', 'Estación de metro Mohamed Naguib', 'Станция метро «Мохамед Нагиб»', '穆罕默德·纳吉布地铁站'), true), 'min' => 8, 'mode' => 'walk', 'note' => []]],
        ['faq', ['q' => ['ar' => 'فين الفندق؟', 'en' => 'Where is the hotel?'],
            'a' => ['ar' => '3 شارع إبراهيم القباني، باب اللوق، عابدين، وسط البلد. حوالي 8 دقائق مشيًا من مترو محمد نجيب و11 دقيقة من المتحف المصري.',
                    'en' => '3 Ibrahim Al Kabani St, Bab Al Louq, Abdeen, downtown Cairo: about 8 minutes on foot from Mohamed Naguib metro station and 11 minutes from the Egyptian Museum.']]],
        ['faq', ['q' => ['ar' => 'إيه أنواع الغرف عندكم؟', 'en' => 'What room types do you have?'],
            'a' => ['ar' => 'غرف سنجل ودبل وتربل خاصة، وأسرّة في غرف مشتركة للشباب وللبنات.',
                    'en' => 'Private single, double and triple rooms, plus beds in separate male and female shared dorms.']]],
        ['faq', ['q' => ['ar' => 'إزاي أحجز؟', 'en' => 'How do I book?'],
            'a' => ['ar' => 'املا نموذج طلب الحجز بمواعيدك وهنرد عليك ونأكد التوافر. الطلب مش حجز مؤكد لحد ما نأكد.',
                    'en' => 'Send the booking request form with your dates and we will reply to confirm availability. A request is not a confirmed booking until we confirm it.']]],
        ['faq', ['q' => ['ar' => 'فيه إيه في كل غرفة؟', 'en' => 'What is in every room?'],
            'a' => ['ar' => 'حمام خاص، تكييف، إنترنت، شاشة تلفزيون، وبلكونة بإطلالة على وسط البلد.',
                    'en' => 'A bathroom, air conditioning, internet, a TV and a balcony overlooking downtown Cairo.']]],
        ['faq', ['q' => ['ar' => 'هل الفطار متاح؟', 'en' => 'Is breakfast available?'],
            'a' => ['ar' => 'أيوه، الفطار متاح. اسألنا عن التفاصيل وقت الحجز.', 'en' => 'Yes, breakfast is available. Ask us about the details when you book.']]],
        ['policy', ['title' => ['ar' => 'تأكيد الحجز', 'en' => 'Booking confirmation'],
            'text' => ['ar' => 'الطلبات بتتأكد بعد ما نرد عليك ونتأكد من التوافر.', 'en' => 'Requests are confirmed once we reply and check availability.']]],
        ['policy', ['title' => ['ar' => 'الأسعار', 'en' => 'Prices'],
            'text' => ['ar' => 'الأسعار بالجنيه المصري وقد تختلف حسب الموسم والتوافر.', 'en' => 'Prices are in Egyptian pounds and may vary by season and availability.']]],
    ];
    $st = $pdo->prepare('INSERT INTO items(type, sort, data_json) VALUES(?,?,?)');
    foreach ($items as $i => [$type, $data]) {
        $st->execute([$type, $i, $j($data)]);
    }
    echo "Seeded starter FAQ, policies and nearby places.\n";
}

$reset = in_array('--reset-admin', $argv, true);
$has = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
if (!$has || $reset) {
    $pw = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'xy'), '=');
    $pdo->exec('DELETE FROM admins');
    $pdo->prepare('INSERT INTO admins(username, pass_hash) VALUES(?, ?)')->execute(['admin', password_hash($pw, PASSWORD_DEFAULT)]);
    echo "\nAdmin login  -> username: admin\n             -> password: $pw\n(Shown once. Change it from Admin > Account after you sign in.)\n";
} else {
    echo "Admin already exists (use --reset-admin to issue a new password).\n";
}
