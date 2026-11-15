<?php
/** "How to get here" block for the contact page (8 languages). Distances to the museum / metro come from the hotel itself. */

function howto_text(string $lang): array
{
    $t = [
        'ar' => [
            'h' => 'إزاي توصلنا',
            'metro' => 'مترو: محطة محمد نجيب، وبعدها حوالي 8 دقايق مشي.',
            'walk' => 'المتحف المصري على بعد حوالي 11 دقيقة مشي.',
            'taxi' => 'من المطار أو محطة رمسيس: أوبر أو تاكسي (المدة حسب الزحمة). اكتب لنا على واتساب وإحنا نوصفلك الطريق.',
            'show' => 'للسواق: ٣ شارع إبراهيم القباني، باب اللوق، عابدين، القاهرة.',
        ],
        'en' => [
            'h' => 'How to get here',
            'metro' => 'Metro: get off at Mohamed Naguib station, then about 8 minutes on foot.',
            'walk' => 'The Egyptian Museum is about an 11-minute walk away.',
            'taxi' => 'From the airport or Ramses station: Uber or a taxi (time depends on traffic). Message us on WhatsApp and we will guide you.',
            'show' => 'Show your driver: 3 Ibrahim Al Kabani St, Bab Al Louq, Abdeen, Cairo.',
        ],
        'de' => [
            'h' => 'Anreise',
            'metro' => 'U-Bahn: Station Mohamed Naguib, danach etwa 8 Minuten zu Fuß.',
            'walk' => 'Zum Ägyptischen Museum sind es etwa 11 Minuten zu Fuß.',
            'taxi' => 'Vom Flughafen oder Bahnhof Ramses: Uber oder Taxi (Dauer je nach Verkehr). Schreiben Sie uns per WhatsApp, wir erklären den Weg.',
            'show' => 'Für den Fahrer: 3 Ibrahim Al Kabani St, Bab Al Louq, Abdeen, Kairo.',
        ],
        'fr' => [
            'h' => 'Comment venir',
            'metro' => 'Métro : station Mohamed Naguib, puis environ 8 minutes à pied.',
            'walk' => 'Le Musée égyptien est à environ 11 minutes à pied.',
            'taxi' => "Depuis l'aéroport ou la gare de Ramsès : Uber ou taxi (durée selon la circulation). Écrivez-nous sur WhatsApp, nous vous guidons.",
            'show' => 'À montrer au chauffeur : 3 Ibrahim Al Kabani St, Bab Al Louq, Abdeen, Le Caire.',
        ],
        'it' => [
            'h' => 'Come arrivare',
            'metro' => 'Metro: scendi alla stazione Mohamed Naguib, poi circa 8 minuti a piedi.',
            'walk' => 'Il Museo Egizio dista circa 11 minuti a piedi.',
            'taxi' => "Dall'aeroporto o dalla stazione di Ramses: Uber o taxi (tempi in base al traffico). Scrivici su WhatsApp e ti guidiamo.",
            'show' => "Da mostrare all'autista: 3 Ibrahim Al Kabani St, Bab Al Louq, Abdeen, Il Cairo.",
        ],
        'es' => [
            'h' => 'Cómo llegar',
            'metro' => 'Metro: estación Mohamed Naguib, y luego unos 8 minutos a pie.',
            'walk' => 'El Museo Egipcio está a unos 11 minutos a pie.',
            'taxi' => 'Desde el aeropuerto o la estación de Ramsés: Uber o taxi (el tiempo depende del tráfico). Escríbenos por WhatsApp y te guiamos.',
            'show' => 'Para el conductor: 3 Ibrahim Al Kabani St, Bab Al Louq, Abdeen, El Cairo.',
        ],
        'ru' => [
            'h' => 'Как добраться',
            'metro' => 'Метро: станция «Мохамед Нагиб», затем около 8 минут пешком.',
            'walk' => 'До Египетского музея примерно 11 минут пешком.',
            'taxi' => 'Из аэропорта или с вокзала Рамзес: Uber или такси (время зависит от пробок). Напишите нам в WhatsApp, мы подскажем дорогу.',
            'show' => 'Для водителя: 3 Ibrahim Al Kabani St, Bab Al Louq, Abdeen, Каир.',
        ],
        'zh' => [
            'h' => '如何到达',
            'metro' => '地铁：在 Mohamed Naguib 站下车，步行约 8 分钟。',
            'walk' => '步行约 11 分钟可到埃及博物馆。',
            'taxi' => '从机场或拉美西斯火车站：可乘 Uber 或出租车（时间视交通而定）。通过 WhatsApp 联系我们，我们会为您指路。',
            'show' => '给司机看：3 Ibrahim Al Kabani St, Bab Al Louq, Abdeen, Cairo。',
        ],
    ];
    return $t[$lang] ?? $t['en'];
}

function howto_block(string $lang): string
{
    $x = howto_text($lang);
    return '<section class="wrap howto"><h2>' . e($x['h']) . '</h2><ul class="near">'
        . '<li>🚇 ' . e($x['metro']) . '</li>'
        . '<li>🏛️ ' . e($x['walk']) . '</li>'
        . '<li>🚕 ' . e($x['taxi']) . '</li>'
        . '<li>📍 <span dir="auto">' . e($x['show']) . '</span></li>'
        . '</ul></section>';
}
