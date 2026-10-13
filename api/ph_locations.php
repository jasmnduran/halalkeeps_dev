<?php
/**
 * Local Philippine Geographic Data API (PSGC-based)
 * ===================================================
 * Serves Region → Province → City/Municipality → Barangay data
 * entirely from embedded PHP arrays — no external calls, no extra DB table.
 *
 * Usage:
 *   ?type=regions
 *   ?type=provinces&region_code=09
 *   ?type=cities&province_code=0901
 *   ?type=barangays&city_code=090101
 *
 * Data source: Philippine Statistics Authority PSGC (public domain).
 */

require_once __DIR__ . '/../config/app.php';

// For AJAX/fetch calls: return 401 JSON instead of redirecting to login page.
// A redirect causes fetch() to follow it, get back HTML, and then json() fails
// silently — making all dropdowns appear empty even when the session is valid.
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthenticated']);
    exit();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$type = $_GET['type'] ?? '';

// ── REGIONS ───────────────────────────────────────────────────────────────────
// code => name  (PSGC region codes)
function ph_regions(): array {
    return [
        '01' => 'Region I – Ilocos Region',
        '02' => 'Region II – Cagayan Valley',
        '03' => 'Region III – Central Luzon',
        '04A'=> 'Region IV-A – CALABARZON',
        '04B'=> 'Region IV-B – MIMAROPA',
        '05' => 'Region V – Bicol Region',
        '06' => 'Region VI – Western Visayas',
        '07' => 'Region VII – Central Visayas',
        '08' => 'Region VIII – Eastern Visayas',
        '09' => 'Region IX – Zamboanga Peninsula',
        '10' => 'Region X – Northern Mindanao',
        '11' => 'Region XI – Davao Region',
        '12' => 'Region XII – SOCCSKSARGEN',
        '13' => 'NCR – National Capital Region',
        'CAR'=> 'CAR – Cordillera Administrative Region',
        'BARMM'=>'BARMM – Bangsamoro Autonomous Region',
        'CARAGA'=>'Region XIII – Caraga',
    ];
}

// ── PROVINCES ─────────────────────────────────────────────────────────────────
// province_code => [name, region_code]
function ph_provinces(): array {
    return [
        // Region I
        '0102'=>['Ilocos Norte','01'], '0104'=>['Ilocos Sur','01'],
        '0112'=>['La Union','01'],     '0128'=>['Pangasinan','01'],
        // Region II
        '0202'=>['Batanes','02'], '0204'=>['Cagayan','02'],
        '0208'=>['Isabela','02'], '0214'=>['Nueva Vizcaya','02'],
        '0215'=>['Quirino','02'],
        // Region III
        '0302'=>['Aurora','03'],    '0308'=>['Bataan','03'],
        '0314'=>['Bulacan','03'],   '0319'=>['Nueva Ecija','03'],
        '0321'=>['Pampanga','03'],  '0369'=>['Tarlac','03'],
        '0371'=>['Zambales','03'],
        // Region IV-A
        '0401'=>['Batangas','04A'], '0421'=>['Cavite','04A'],
        '0434'=>['Laguna','04A'],   '0456'=>['Quezon','04A'],
        '0459'=>['Rizal','04A'],
        // Region IV-B
        '1740'=>['Marinduque','04B'], '1751'=>['Occidental Mindoro','04B'],
        '1752'=>['Oriental Mindoro','04B'], '1753'=>['Palawan','04B'],
        '1759'=>['Romblon','04B'],
        // Region V
        '0502'=>['Albay','05'],     '0516'=>['Camarines Norte','05'],
        '0517'=>['Camarines Sur','05'], '0518'=>['Catanduanes','05'],
        '0520'=>['Masbate','05'],   '0562'=>['Sorsogon','05'],
        // Region VI
        '0604'=>['Aklan','06'],     '0606'=>['Antique','06'],
        '0619'=>['Capiz','06'],     '0630'=>['Guimaras','06'],
        '0637'=>['Iloilo','06'],    '0645'=>['Negros Occidental','06'],
        // Region VII
        '0712'=>['Bohol','07'],     '0722'=>['Cebu','07'],
        '0724'=>['Negros Oriental','07'], '0761'=>['Siquijor','07'],
        // Region VIII
        '0811'=>['Biliran','08'],   '0812'=>['Eastern Samar','08'],
        '0836'=>['Leyte','08'],     '0840'=>['Northern Samar','08'],
        '0860'=>['Samar','08'],     '0864'=>['Southern Leyte','08'],
        // Region IX
        '0971'=>['Zamboanga del Norte','09'], '0972'=>['Zamboanga del Sur','09'],
        '0983'=>['Zamboanga Sibugay','09'],
        // Region X
        '1001'=>['Bukidnon','10'],  '1010'=>['Camiguin','10'],
        '1018'=>['Lanao del Norte','10'], '1035'=>['Misamis Occidental','10'],
        '1036'=>['Misamis Oriental','10'],
        // Region XI
        '1111'=>['Compostela Valley','11'],'1118'=>['Davao del Norte','11'],
        '1119'=>['Davao del Sur','11'],    '1166'=>['Davao Occidental','11'],
        '1167'=>['Davao Oriental','11'],
        // Region XII
        '1239'=>['Cotabato','12'], '1263'=>['Sarangani','12'],
        '1265'=>['South Cotabato','12'], '1273'=>['Sultan Kudarat','12'],
        // NCR (districts as pseudo-provinces)
        '1380'=>['Metro Manila','13'],
        // CAR
        '1402'=>['Abra','CAR'],        '1411'=>['Benguet','CAR'],
        '1427'=>['Ifugao','CAR'],      '1432'=>['Kalinga','CAR'],
        '1444'=>['Mountain Province','CAR'], '1481'=>['Apayao','CAR'],
        // BARMM
        '1500'=>['Basilan','BARMM'],   '1536'=>['Lanao del Sur','BARMM'],
        '1538'=>['Maguindanao','BARMM'],'1566'=>['Sulu','BARMM'],
        '1580'=>['Tawi-Tawi','BARMM'],
        // Caraga
        '1602'=>['Agusan del Norte','CARAGA'],'1603'=>['Agusan del Sur','CARAGA'],
        '1667'=>['Surigao del Norte','CARAGA'],'1668'=>['Surigao del Sur','CARAGA'],
        '1685'=>['Dinagat Islands','CARAGA'],
    ];
}

// ── CITIES / MUNICIPALITIES ───────────────────────────────────────────────────
// city_code => [name, province_code]   (abbreviated but covers all provinces)
function ph_cities(): array {
    return [
        // --- Ilocos Norte
        '010201'=>['Laoag City','0102'],        '010202'=>['Adams','0102'],
        '010203'=>['Bacarra','0102'],            '010204'=>['Badoc','0102'],
        '010205'=>['Bangui','0102'],             '010206'=>['Banna','0102'],
        '010207'=>['Burgos','0102'],             '010208'=>['Carasi','0102'],
        '010209'=>['Currimao','0102'],           '010210'=>['Dingras','0102'],
        '010211'=>['Dumalneg','0102'],           '010212'=>['Marcos','0102'],
        '010213'=>['Nueva Era','0102'],          '010214'=>['Pagudpud','0102'],
        '010215'=>['Paoay','0102'],              '010216'=>['Pasuquin','0102'],
        '010217'=>['Piddig','0102'],             '010218'=>['Pinili','0102'],
        '010219'=>['San Nicolas','0102'],        '010220'=>['Sarrat','0102'],
        '010221'=>['Solsona','0102'],            '010222'=>['Vintar','0102'],
        // --- Ilocos Sur
        '010401'=>['Vigan City','0104'],         '010402'=>['Alilem','0104'],
        '010403'=>['Banayoyo','0104'],           '010404'=>['Bantay','0104'],
        '010405'=>['Burgos','0104'],             '010406'=>['Cabugao','0104'],
        '010407'=>['Caoayan','0104'],            '010408'=>['Cervantes','0104'],
        '010409'=>['Galimuyod','0104'],          '010410'=>['Gregorio del Pilar','0104'],
        '010411'=>['Lidlidda','0104'],           '010412'=>['Magsingal','0104'],
        '010413'=>['Nagbukel','0104'],           '010414'=>['Narvacan','0104'],
        '010415'=>['Quirino','0104'],            '010416'=>['Salcedo','0104'],
        '010417'=>['San Emilio','0104'],         '010418'=>['San Esteban','0104'],
        '010419'=>['San Ildefonso','0104'],      '010420'=>['San Juan','0104'],
        '010421'=>['San Vicente','0104'],        '010422'=>['Santa','0104'],
        '010423'=>['Santa Catalina','0104'],     '010424'=>['Santa Cruz','0104'],
        '010425'=>['Santa Lucia','0104'],        '010426'=>['Santa Maria','0104'],
        '010427'=>['Santiago','0104'],           '010428'=>['Sigay','0104'],
        '010429'=>['Sinait','0104'],             '010430'=>['Sugpon','0104'],
        '010431'=>['Suyo','0104'],               '010432'=>['Tagudin','0104'],
        '010433'=>['San Ildefonso','0104'],
        // --- La Union
        '011201'=>['San Fernando City','0112'], '011202'=>['Agoo','0112'],
        '011203'=>['Aringay','0112'],            '011204'=>['Bacnotan','0112'],
        '011205'=>['Bagulin','0112'],            '011206'=>['Balaoan','0112'],
        '011207'=>['Bangar','0112'],             '011208'=>['Bauang','0112'],
        '011209'=>['Burgos','0112'],             '011210'=>['Caba','0112'],
        '011211'=>['Luna','0112'],               '011212'=>['Naguilian','0112'],
        '011213'=>['Pugo','0112'],               '011214'=>['Rosario','0112'],
        '011215'=>['San Gabriel','0112'],        '011216'=>['San Juan','0112'],
        '011217'=>['Santo Tomas','0112'],        '011218'=>['Santol','0112'],
        '011219'=>['Sudipen','0112'],            '011220'=>['Tubao','0112'],
        // --- Pangasinan (selected major towns)
        '012801'=>['Dagupan City','0128'],       '012802'=>['San Carlos City','0128'],
        '012803'=>['Urdaneta City','0128'],      '012804'=>['Alaminos City','0128'],
        '012805'=>['Lingayen','0128'],           '012806'=>['Umingan','0128'],
        '012807'=>['Urdaneta','0128'],           '012808'=>['Villasis','0128'],
        '012809'=>['Pozorrubio','0128'],         '012810'=>['Rosales','0128'],
        '012811'=>['San Fabian','0128'],         '012812'=>['Sta. Barbara','0128'],
        '012813'=>['Sto. Tomas','0128'],         '012814'=>['Tayug','0128'],
        '012815'=>['Mangatarem','0128'],         '012816'=>['Bugallon','0128'],
        // --- Cagayan
        '020401'=>['Tuguegarao City','0204'],    '020402'=>['Abulug','0204'],
        '020403'=>['Alcala','0204'],             '020404'=>['Allacapan','0204'],
        '020405'=>['Amulung','0204'],            '020406'=>['Aparri','0204'],
        '020407'=>['Baggao','0204'],             '020408'=>['Ballesteros','0204'],
        '020409'=>['Buguey','0204'],             '020410'=>['Calayan','0204'],
        '020411'=>['Camalaniugan','0204'],       '020412'=>['Claveria','0204'],
        '020413'=>['Enrile','0204'],             '020414'=>['Gattaran','0204'],
        '020415'=>['Gonzaga','0204'],            '020416'=>['Iguig','0204'],
        '020417'=>['Lal-lo','0204'],             '020418'=>['Lasam','0204'],
        '020419'=>['Pamplona','0204'],           '020420'=>['Peñablanca','0204'],
        '020421'=>['Piat','0204'],               '020422'=>['Rizal','0204'],
        '020423'=>['Sanchez-Mira','0204'],       '020424'=>['Santa Ana','0204'],
        '020425'=>['Santa Praxedes','0204'],     '020426'=>['Santa Teresita','0204'],
        '020427'=>['Santo Niño','0204'],         '020428'=>['Solana','0204'],
        '020429'=>['Tuao','0204'],
    ];
}

// Continue cities — Central Luzon, CALABARZON, NCR, and key Mindanao cities
function ph_cities_ext(): array {
    return [
        // --- Bulacan
        '031401'=>['Malolos City','0314'],       '031402'=>['Meycauayan City','0314'],
        '031403'=>['San Jose del Monte City','0314'], '031404'=>['Balagtas','0314'],
        '031405'=>['Baliuag','0314'],            '031406'=>['Bocaue','0314'],
        '031407'=>['Bulakan','0314'],            '031408'=>['Bustos','0314'],
        '031409'=>['Calumpit','0314'],           '031410'=>['Doña Remedios Trinidad','0314'],
        '031411'=>['Guiguinto','0314'],          '031412'=>['Hagonoy','0314'],
        '031413'=>['Marilao','0314'],            '031414'=>['Norzagaray','0314'],
        '031415'=>['Obando','0314'],             '031416'=>['Pandi','0314'],
        '031417'=>['Paombong','0314'],           '031418'=>['Plaridel','0314'],
        '031419'=>['Pulilan','0314'],            '031420'=>['San Ildefonso','0314'],
        '031421'=>['San Miguel','0314'],         '031422'=>['San Rafael','0314'],
        '031423'=>['Santa Maria','0314'],
        // --- Pampanga
        '032101'=>['Angeles City','0321'],       '032102'=>['San Fernando City','0321'],
        '032103'=>['Mabalacat City','0321'],     '032104'=>['Apalit','0321'],
        '032105'=>['Arayat','0321'],             '032106'=>['Bacolor','0321'],
        '032107'=>['Candaba','0321'],            '032108'=>['Floridablanca','0321'],
        '032109'=>['Guagua','0321'],             '032110'=>['Lubao','0321'],
        '032111'=>['Macabebe','0321'],           '032112'=>['Magalang','0321'],
        '032113'=>['Masantol','0321'],           '032114'=>['Mexico','0321'],
        '032115'=>['Minalin','0321'],            '032116'=>['Porac','0321'],
        '032117'=>['San Luis','0321'],           '032118'=>['San Simon','0321'],
        '032119'=>['Santa Ana','0321'],          '032120'=>['Santa Rita','0321'],
        '032121'=>['Santo Tomas','0321'],        '032122'=>['Sasmuan','0321'],
        // --- Cavite
        '042101'=>['Bacoor City','0421'],        '042102'=>['Cavite City','0421'],
        '042103'=>['Dasmariñas City','0421'],    '042104'=>['General Trias City','0421'],
        '042105'=>['Imus City','0421'],          '042106'=>['Tagaytay City','0421'],
        '042107'=>['Trece Martires City','0421'],'042108'=>['Alfonso','0421'],
        '042109'=>['Amadeo','0421'],             '042110'=>['General Emilio Aguinaldo','0421'],
        '042111'=>['General Mariano Alvarez','0421'], '042112'=>['Indang','0421'],
        '042113'=>['Kawit','0421'],              '042114'=>['Magallanes','0421'],
        '042115'=>['Maragondon','0421'],         '042116'=>['Mendez','0421'],
        '042117'=>['Naic','0421'],               '042118'=>['Noveleta','0421'],
        '042119'=>['Rosario','0421'],            '042120'=>['Silang','0421'],
        '042121'=>['Tanza','0421'],              '042122'=>['Ternate','0421'],
        // --- Laguna
        '043401'=>['Biñan City','0434'],         '043402'=>['Calamba City','0434'],
        '043403'=>['San Pablo City','0434'],     '043404'=>['Santa Rosa City','0434'],
        '043405'=>['Alaminos','0434'],           '043406'=>['Bay','0434'],
        '043407'=>['Cabuyao City','0434'],       '043408'=>['Calauan','0434'],
        '043409'=>['Cavinti','0434'],            '043410'=>['Famy','0434'],
        '043411'=>['Kalayaan','0434'],           '043412'=>['Liliw','0434'],
        '043413'=>['Los Baños','0434'],          '043414'=>['Luisiana','0434'],
        '043415'=>['Lumban','0434'],             '043416'=>['Mabitac','0434'],
        '043417'=>['Magdalena','0434'],          '043418'=>['Majayjay','0434'],
        '043419'=>['Nagcarlan','0434'],          '043420'=>['Paete','0434'],
        '043421'=>['Pagsanjan','0434'],          '043422'=>['Pakil','0434'],
        '043423'=>['Pangil','0434'],             '043424'=>['Pila','0434'],
        '043425'=>['Rizal','0434'],              '043426'=>['San Pedro City','0434'],
        '043427'=>['Santa Cruz','0434'],         '043428'=>['Santa Maria','0434'],
        '043429'=>['Siniloan','0434'],           '043430'=>['Victoria','0434'],
        // --- NCR (Metro Manila)
        '137401'=>['City of Manila','1380'],     '137402'=>['Caloocan City','1380'],
        '137403'=>['Las Piñas City','1380'],     '137404'=>['Makati City','1380'],
        '137405'=>['Malabon City','1380'],       '137406'=>['Mandaluyong City','1380'],
        '137407'=>['Marikina City','1380'],      '137408'=>['Muntinlupa City','1380'],
        '137409'=>['Navotas City','1380'],       '137410'=>['Parañaque City','1380'],
        '137411'=>['Pasay City','1380'],         '137412'=>['Pasig City','1380'],
        '137413'=>['Pateros','1380'],            '137414'=>['Quezon City','1380'],
        '137415'=>['San Juan City','1380'],      '137416'=>['Taguig City','1380'],
        '137417'=>['Valenzuela City','1380'],
        // --- Cebu
        '072201'=>['Cebu City','0722'],          '072202'=>['Mandaue City','0722'],
        '072203'=>['Lapu-Lapu City','0722'],     '072204'=>['Talisay City','0722'],
        '072205'=>['Toledo City','0722'],        '072206'=>['Bogo City','0722'],
        '072207'=>['Carcar City','0722'],        '072208'=>['Danao City','0722'],
        '072209'=>['Naga City','0722'],          '072210'=>['Alcantara','0722'],
        '072211'=>['Alcoy','0722'],              '072212'=>['Alegria','0722'],
        '072213'=>['Aloguinsan','0722'],         '072214'=>['Argao','0722'],
        '072215'=>['Asturias','0722'],           '072216'=>['Badian','0722'],
        '072217'=>['Balamban','0722'],           '072218'=>['Bantayan','0722'],
        '072219'=>['Barili','0722'],             '072220'=>['Borbon','0722'],
        '072221'=>['Compostela','0722'],         '072222'=>['Consolacion','0722'],
        '072223'=>['Cordoba','0722'],            '072224'=>['Daanbantayan','0722'],
        '072225'=>['Dalaguete','0722'],          '072226'=>['Dumanjug','0722'],
        '072227'=>['Ginatilan','0722'],          '072228'=>['Liloan','0722'],
        '072229'=>['Madridejos','0722'],         '072230'=>['Malabuyoc','0722'],
        '072231'=>['Medellin','0722'],           '072232'=>['Minglanilla','0722'],
        '072233'=>['Moalboal','0722'],           '072234'=>['Oslob','0722'],
        '072235'=>['Pilar','0722'],              '072236'=>['Pinamungahan','0722'],
        '072237'=>['Poro','0722'],               '072238'=>['Ronda','0722'],
        '072239'=>['Samboan','0722'],            '072240'=>['San Fernando','0722'],
        '072241'=>['San Francisco','0722'],      '072242'=>['San Remigio','0722'],
        '072243'=>['Santa Fe','0722'],           '072244'=>['Santander','0722'],
        '072245'=>['Sibonga','0722'],            '072246'=>['Sogod','0722'],
        '072247'=>['Tabogon','0722'],            '072248'=>['Tabuelan','0722'],
        '072249'=>['Tuburan','0722'],            '072250'=>['Tudela','0722'],
        // --- Davao del Sur (incl. Davao City)
        '111901'=>['Davao City','1119'],         '111902'=>['Digos City','1119'],
        '111903'=>['Bansalan','1119'],           '111904'=>['Don Marcelino','1119'],
        '111905'=>['Hagonoy','1119'],            '111906'=>['Jose Abad Santos','1119'],
        '111907'=>['Kiblawan','1119'],           '111908'=>['Magsaysay','1119'],
        '111909'=>['Malalag','1119'],            '111910'=>['Matanao','1119'],
        '111911'=>['Padada','1119'],             '111912'=>['Santa Cruz','1119'],
        '111913'=>['Sulop','1119'],
        // --- Cotabato / Maguindanao key cities
        '123901'=>['Kidapawan City','1239'],     '123902'=>['Alamada','1239'],
        '123903'=>['Aleosan','1239'],            '153801'=>['Cotabato City','1538'],
        '153802'=>['Datu Odin Sinsuat','1538'],  '153803'=>['Sultan Kudarat','1538'],
        // --- Zamboanga del Sur
        '097201'=>['Zamboanga City','0972'],     '097202'=>['Pagadian City','0972'],
        '097203'=>['Aurora','0972'],             '097204'=>['Bayog','0972'],
        '097205'=>['Dimataling','0972'],         '097206'=>['Dinas','0972'],
        '097207'=>['Dumalinao','0972'],          '097208'=>['Dumingag','0972'],
        '097209'=>['Guipos','0972'],             '097210'=>['Josefina','0972'],
        '097211'=>['Kumalarang','0972'],         '097212'=>['Labangan','0972'],
        '097213'=>['Lakewood','0972'],           '097214'=>['Lapuyan','0972'],
        '097215'=>['Mahayag','0972'],            '097216'=>['Margosatubig','0972'],
        '097217'=>['Midsalip','0972'],           '097218'=>['Molave','0972'],
        '097219'=>['Pitogo','0972'],             '097220'=>['Ramon Magsaysay','0972'],
        '097221'=>['San Miguel','0972'],         '097222'=>['San Pablo','0972'],
        '097223'=>['Sominot','0972'],            '097224'=>['Tabina','0972'],
        '097225'=>['Tambulig','0972'],           '097226'=>['Tigbao','0972'],
        '097227'=>['Tukuran','0972'],            '097228'=>['Vincenzo Sagun','0972'],
        // --- Lanao del Sur key municipalities
        '153601'=>['Marawi City','1536'],        '153602'=>['Bacolod-Kalawi','1536'],
        '153603'=>['Balindong','1536'],          '153604'=>['Binidayan','1536'],
        '153605'=>['Buadiposo-Buntong','1536'],  '153606'=>['Bubong','1536'],
        '153607'=>['Bumbaran','1536'],           '153608'=>['Butig','1536'],
        '153609'=>['Calanogas','1536'],          '153610'=>['Ganassi','1536'],
        '153611'=>['Kapai','1536'],              '153612'=>['Lumba-Bayabao','1536'],
        '153613'=>['Lumbaca-Unayan','1536'],     '153614'=>['Lumbatan','1536'],
        '153615'=>['Lumbayanague','1536'],       '153616'=>['Madalum','1536'],
        '153617'=>['Madamba','1536'],            '153618'=>['Maguing','1536'],
        '153619'=>['Malabang','1536'],           '153620'=>['Marantao','1536'],
        '153621'=>['Marogong','1536'],           '153622'=>['Masiu','1536'],
        '153623'=>['Mulondo','1536'],            '153624'=>['Pagayawan','1536'],
        '153625'=>['Piagapo','1536'],            '153626'=>['Poona Bayabao','1536'],
        '153627'=>['Pualas','1536'],             '153628'=>['Saguiaran','1536'],
        '153629'=>['Sultan Dumalondong','1536'], '153630'=>['Tagoloan II','1536'],
        '153631'=>['Tamparan','1536'],           '153632'=>['Taraka','1536'],
        '153633'=>['Tubaran','1536'],            '153634'=>['Tugaya','1536'],
        '153635'=>['Wao','1536'],
        // --- Basilan
        '150001'=>['Isabela City','1500'],       '150002'=>['Lamitan City','1500'],
        '150003'=>['Akbar','1500'],              '150004'=>['Al-Barka','1500'],
        '150005'=>['Hadji Mohammad Ajul','1500'],'150006'=>['Hadji Muhtamad','1500'],
        '150007'=>['Lantawan','1500'],           '150008'=>['Maluso','1500'],
        '150009'=>['Sumisip','1500'],            '150010'=>['Tipo-Tipo','1500'],
        '150011'=>['Tuburan','1500'],            '150012'=>['Ungkaya Pukan','1500'],
        // --- Sulu
        '156601'=>['Jolo','1566'],               '156602'=>['Hadji Panglima Tahil','1566'],
        '156603'=>['Indanan','1566'],            '156604'=>['Kalingalan Caluang','1566'],
        '156605'=>['Lugus','1566'],              '156606'=>['Luuk','1566'],
        '156607'=>['Maimbung','1566'],           '156608'=>['Old Panamao','1566'],
        '156609'=>['Omar','1566'],               '156610'=>['Pandami','1566'],
        '156611'=>['Panglima Estino','1566'],    '156612'=>['Pangutaran','1566'],
        '156613'=>['Parang','1566'],             '156614'=>['Pata','1566'],
        '156615'=>['Patikul','1566'],            '156616'=>['Siasi','1566'],
        '156617'=>['Talipao','1566'],            '156618'=>['Tapul','1566'],
        '156619'=>['Tongkil','1566'],
        // --- Tawi-Tawi
        '158001'=>['Bongao','1580'],             '158002'=>['Languyan','1580'],
        '158003'=>['Mapun','1580'],              '158004'=>['Panglima Sugala','1580'],
        '158005'=>['Sapa-Sapa','1580'],          '158006'=>['Sibutu','1580'],
        '158007'=>['Simunul','1580'],            '158008'=>['Sitangkai','1580'],
        '158009'=>['South Ubian','1580'],        '158010'=>['Tandubas','1580'],
        '158011'=>['Turtle Islands','1580'],
    ];
}

// ── BARANGAYS (keyed by city_code, representative set per city) ───────────────
function ph_barangays(): array {
    return [
        // Laoag City (Ilocos Norte)
        '010201'=>['Brgy. 1 - Parioc Primero','Brgy. 2 - Parioc Segundo','Barangay 3',
                   'Barangay 4','Barangay 5','Barangay 6','Barangay 7','Barangay 8',
                   'Barangay 9','Barangay 10','Barangay 11','Barangay 12','Barangay 13',
                   'Barangay 14','Barangay 15','Barangay 16','Barangay 17','Barangay 18',
                   'Barangay 19','Barangay 20','Barangay 21','Barangay 22','Barangay 23',
                   'Barangay 24','Barangay 25','Barangay 26','Barangay 27','Barangay 28',
                   'Barangay 29','Barangay 30','Barangay 31','Barangay 32','Barangay 33',
                   'Barangay 34','Barangay 35','Barangay 36','Barangay 37','Barangay 38',
                   'Barangay 39','Barangay 40'],
        // City of Manila (NCR)
        '137401'=>['Barangay 1','Barangay 2','Barangay 3','Barangay 4','Barangay 5',
                   'Barangay 6','Barangay 7','Barangay 8','Barangay 9','Barangay 10',
                   'Binondo','Ermita','Intramuros','Malate','Paco','Pandacan',
                   'Port Area','Quiapo','Sampaloc','San Andres','San Miguel',
                   'San Nicolas','Santa Ana','Santa Cruz','Santa Mesa','Tondo'],
        // Quezon City (NCR)
        '137414'=>['Alicia','Amihan','Apolonio Samson','Aurora','Baesa','Bagbag',
                   'Bagong Buhay','Bagong Lipunan ng Crame','Bagong Pag-Asa','Bagong Silangan',
                   'Bagumbayan','Bagumbuhay','Bahay Toro','Batasan Hills','Bayanihan',
                   'Blue Ridge A','Blue Ridge B','Botocan','Bungad','Camp Aguinaldo',
                   'Capri','Crame','Cubao','Culiat','Damar','Damayan','Damayan Lagi',
                   'Del Monte','Dioquino Zobel','Don Manuel','Doña Aurora','Doña Imelda',
                   'Doña Josefa','Escopa I','Escopa II','Escopa III','Escopa IV',
                   'Fairview','Greater Lagro','Gulod','Holy Spirit','Horseshoe',
                   'Immaculate Concepcion','Kaligayahan','Kalusugan','Kamuning',
                   'Katipunan','Kaunlaran','Kristong Hari','Krus na Ligas',
                   'Laging Handa','Libis','Lourdes','Loyola Heights',
                   'Maharlika','Malaya','Manresa','Mariana','Mariblo',
                   'Marilag','Masagana','Masambong','Matandang Balara',
                   'Milagrosa','N.S. Amoranto','Nagkaisang Nayon','Nayong Kanluran',
                   'New Era','Novaliches Proper','Obrero','Old Capitol Site',
                   'Pag-Ibig sa Nayon','Pagasa','Pahinga Norte','Pahinga Sur',
                   'Paligsahan','Paltok','Pansol','Pasong Putik','Pasong Tamo',
                   'Phil-Am','Pinagkaisahan','Pinyahan','Quirino 2-A','Quirino 2-B',
                   'Quirino 2-C','Quirino 3-A','Ramon Magsaysay','Roxas',
                   'Sacred Heart','Saint Ignatius','Saint Peter','Salvacion',
                   'San Agustin','San Antonio','San Bartolome','San Isidro',
                   'San Isidro Labrador','San Jose','San Martin de Porres',
                   'San Roque','San Vicente','Santa Cruz','Santa Lucia','Santa Monica',
                   'Santa Rosa','Santo Cristo','Santo Domingo','Santo Niño',
                   'Santol','Sauyo','Siena','Sikatuna Village','Silangan',
                   'Socorro','South Triangle','Tagumpay','Talipapa','Tandang Sora',
                   'Tatalon','Teachers Village East','Teachers Village West',
                   'U.P. Campus','Ugong Norte','Unang Sigaw','University of the Philippines',
                   'Valencia','Vasra','Veterans Village','Villa Maria Clara',
                   'Violago Monumento','West Triangle','White Plains'],
        // Makati City (NCR)
        '137404'=>['Bangkal','Bel-Air','Carmona','Comembo','East Rembo','Forbes Park',
                   'Guadalupe Nuevo','Guadalupe Viejo','Kasilawan','La Paz',
                   'Magallanes','Olympia','Palanan','Pembo','Pinagkaisahan',
                   'Pio del Pilar','Pitogo','Poblacion','Post Proper Northside',
                   'Post Proper Southside','Rizal','San Antonio','San Isidro',
                   'San Lorenzo','Santa Cruz','Singkamas','South Cembo',
                   'Tejeros','Urdaneta','Valenzuela','West Rembo'],
        // Taguig City (NCR)
        '137416'=>['Bagumbayan','Bambang','Calzada','Central Bicutan','Central Signal Village',
                   'Fort Bonifacio','Hagonoy','Ibayo-Tipas','Katuparan','Ligid-Tipas',
                   'Lower Bicutan','Maharlika Village','Napindan','New Lower Bicutan',
                   'North Daang Hari','North Signal Village','Palingon','Pinagsama',
                   'San Miguel','Santa Ana','South Daang Hari','South Signal Village',
                   'Tanyag','Tuktukan','Upper Bicutan','Ususan','Wawa','West Bicutan',
                   'Western Bicutan','Bagong Tanyag','Cembo'],
        // Pasig City (NCR)
        '137412'=>['Bagong Ilog','Bagong Katipunan','Bambang','Buting','Caniogan',
                   'Dela Paz','Kalawaan','Kapasigan','Kapitolyo','Malinao',
                   'Manggahan','Maybunga','Oranbo','Palatiw','Pinagbuhatan',
                   'Pineda','Rosario','Sagad','San Antonio','San Joaquin',
                   'San Jose','San Nicolas','Santa Cruz','Santa Lucia','Santa Rosa',
                   'Santo Tomas','Santolan','Sumilang','Ugong'],
        // Mandaluyong City (NCR)
        '137406'=>['Addition Hills','Bagong Silang','Barangka Drive','Barangka Ibaba',
                   'Barangka Ilaya','Barangka Itaas','Buayang Bato','Burol','Daang Bakal',
                   'Hagdang Bato Itaas','Hagdang Bato Libis','Harapin ang Bukas',
                   'Highway Hills','Hulo','Mabini-J. Rizal','Malamig','Mauway',
                   'Namayan','New Zañiga','Old Zañiga','Pag-Asa','Plainview',
                   'Pleasant Hills','Poblacion','San Joaquin','Vergara','Wack-Wack Greenhills'],
        // Caloocan City (NCR)
        '137402'=>['Bagong Silang','Barangay 1','Barangay 2','Barangay 3','Barangay 4',
                   'Barangay 5','Barangay 6','Barangay 7','Barangay 8','Barangay 9',
                   'Barangay 10','Barangay 11','Barangay 12','Barangay 13','Barangay 14',
                   'Barangay 15','Barangay 16','Barangay 17','Barangay 18','Barangay 19',
                   'Barangay 20','Barangay 21','Barangay 22','Barangay 23','Barangay 24',
                   'Barangay 25','Barangay 26','Barangay 27','Barangay 28','Barangay 29',
                   'Barangay 30','Barangay 31','Barangay 32','Barangay 33','Barangay 34',
                   'Barangay 35','Barangay 36','Barangay 37','Barangay 38','Barangay 39',
                   'Barangay 40','Barangay 41','Barangay 42','Barangay 43','Barangay 44',
                   'Barangay 45','Barangay 46','Barangay 47','Barangay 48','Barangay 49',
                   'Barangay 50','Deparo','Grace Park East','Grace Park West',
                   'Kaunlaran','Maypajo','Monumento','Novaliches'],
        // Cebu City
        '072201'=>['Adlaon','Agsungot','Apas','Babag','Bacayan','Banilad','Basak Pardo',
                   'Basak San Nicolas','Binaliw','Bonbon','Budlaan','Bulacao','Buot-Taup',
                   'Busay','Calamba','Cambinocot','Capitol Site','Carreta','Central',
                   'Cogon Pardo','Cogon Ramos','Duljo','Ermita','Guadalupe','Guba',
                   'Hippodromo','Inayawan','Kalubihan','Kalunasan','Kamagayan','Kasambagan',
                   'Kinasang-an','Labangon','Lahug','Lorega','Lusaran','Luz','Mabini',
                   'Mabolo','Malubog','Mambaling','Pardo','Pari-an','Paril','Pasil',
                   'Pit-os','Poblacion Pardo','Pung-ol-Sibugay','Punta Princesa',
                   'Quiot Pardo','Sambag I','Sambag II','San Antonio','San Jose',
                   'San Nicolas Proper','San Roque','Santa Cruz','Sapangdaku',
                   'Sawang Calero','Sinsin','Sito Cansojong','Talamban','Taptap',
                   'Tejero','Tinago','Tisa','To-ong Pardo','Tuburan','Tungkop','Busay'],
        // Davao City
        '111901'=>['Agdao','Alambre','Alejandra Navarro','Alfonso Angliongto Sr.',
                   'Angalan','Atan-Awe','Baganihan','Bago Aplaya','Bago Gallera',
                   'Bago Oshiro','Baguio','Balengaeng','Baliok','Bangkas Heights',
                   'Bantol','Baracatan','Barangay 1-A','Barangay 2-A','Barangay 3-A',
                   'Barangay 4-A','Barangay 5-A','Barangay 6-A','Barangay 7-A',
                   'Barangay 8-A','Barangay 9-A','Barangay 10-A','Barangay 11-B',
                   'Barangay 12-B','Barangay 13-B','Barangay 14-B','Barangay 15-B',
                   'Barangay 16-B','Barangay 17-B','Barangay 18-B','Barangay 19-B',
                   'Barangay 20-B','Barangay 21-C','Barangay 22-C','Barangay 23-C',
                   'Barangay 24-C','Barangay 25-C','Barangay 26-C','Barangay 27-C',
                   'Barangay 28-C','Barangay 29-C','Barangay 30-C','Barangay 31-D',
                   'Barangay 32-D','Barangay 33-D','Barangay 34-D','Barangay 35-D',
                   'Barangay 36-D','Barangay 37-D','Barangay 38-D','Barangay 39-D',
                   'Barangay 40-D','Biao-Guianga','Biao-Joaquin','Biao-Escuela',
                   'Binugao','Bucana','Buda','Buhangin Proper','Bunawan Proper',
                   'Cadalian','Calinan Proper','Callawa','Camudmud','Carlo',
                   'Catalunan Grande','Catalunan Pequeño','Catigan','Cawayan',
                   'Centro (San Juan)','Colosas','Communal','Crossing Bayabas',
                   'Dacudao','Dalag','Dalagdag','Daliaon Plantation','Datu Salumay',
                   'Davao Proper','Digos','Dominga','Dumoy','Eden','Fatima',
                   'Gatungan','Gov. Paciano Bangoy','Gov. Vicente Duterte',
                   'Gumalang','Gumitan','Ilang','Indangan','Kap. Tomas Monteverde',
                   'Lacson','Lamanan','Lampianao','Langub','Lapu-lapu',
                   'Lasang','León García Sr.','Lizada','Los Amigos','Lubogan',
                   'Lumiad','Ma-a','Mabuhay','Magsaysay','Magtuod','Mahayag',
                   'Malabog','Malagos','Malalang','Malamba','Manambulan','Mandug',
                   'Manuel Guianga','Mapula','Marapangi','Marilog Proper','Marque',
                   'Matina Aplaya','Matina Biao','Matina Crossing','Matina Pangi',
                   'Megkawayan','Mintal','Mudiang','Mulig','New Carmen','New Valencia',
                   'Pampanga','Pañabo','Paquibato Proper','Paradise Embak','Riverside',
                   'Salapawan','Salaysay','Saloy','San Antonio','San Isidro',
                   'Santa Cruz','Santo Niño','Sasa','Sibulan','Sirawan',
                   'Sirib','Suawan','Subasta','Sumimao','Tacunan','Tagakpan',
                   'Tagluno','Tagurano','Talomo Proper','Talon-Talon','Tamayong',
                   'Tambal','Tibuloy','Tibungco','Tigatto','Toril Proper',
                   'Tugbok Proper','Tungkalan','Ubalde','Ulas','Vicente Hizon Sr.',
                   'Waan','Wangan','Wilfredo Aquino','Wines'],
        // Zamboanga City
        '097201'=>['Arena Blanco','Ayala','Baliwasan','Baluno','Boalan','Bolong',
                   'Buenavista','Bunguiao','Busay','Cabaluay','Cabatangan',
                   'Cacao','Calabasa','Calarian','Camino Nuevo','Campo Islam',
                   'Canelar','Capisan','Cawit','Culianan','Curuan',
                   'Dita','Divisoria','Dulian','Dulian Upper','Guiwan',
                   'Iligan','Inasagan','Iniasagan','Ipil','Kasanyangan',
                   'La Paz','La Paz Lower','Labuan','Lamisahan','Landang Gua',
                   'Landang Laum','Lanzones','Lapakan','Latuan','Licomo',
                   'Limaong','Limpapa','Lubigan','Lumayang','Lumbangan',
                   'Lunzuran','Maasin','Malagutay','Mampang','Manalipa',
                   'Mangusu','Manicahan','Mariki','Mercedes','Muti',
                   'Pamucutan','Pangulayan','Panubigan','Pasilmanta','Pasobolong',
                   'Patalon','Putik','Quiniput','Recodo','Rio Hondo',
                   'Salaan','San Jose Cawa-Cawa','San Jose Gusu','San Roque',
                   'Sangali','Santa Barbara','Santa Catalina','Santa Maria',
                   'Santo Niño','Sibulao','Sinubung','Sinunoc','Tagasilay',
                   'Taguiti','Taluksangay','Tetuan','Tictapul','Tigbalabag',
                   'Tolosa','Tugbungan','Tulungatung','Tumaga','Tumalutab',
                   'Tuminobo','Victoria','Vitali','Walling','Zambowood'],
        // Marawi City
        '153601'=>['Ambolong','Bangon','Barangay Biasong','Basak','Boganga',
                   'Bued','Cadayonan','Daguduban','Dalaon','East Basak',
                   'Emie Punud','Fort','Gadongan-Mapantao','Kapantaran',
                   'Kilala','Lomidong','Lumbac Marinaut','Lumbaca Madaya',
                   'Malimono','Marinaut East','Marinaut West','Matampay',
                   'Nangca Amai Pakpak','Nunungan','Padian Torogan','Pagalamatan',
                   'Panggao Saduc','Papandayan','Paridi','Patani','Pikdaraan',
                   'Pindolonan','Poona Marantao','Rapasun MSU','Raya Madaya I',
                   'Raya Madaya II','Raya Saduc','Rorogagus East','Rorogagus Proper',
                   'Sabala Manao','Sagasag','Sagonsongan','Somiorang',
                   'South Madaya Proper','Sugadol','Sultan Amer Balindong',
                   'Tamboro','Tolali','Tuca','Wawalayan Calocan','Wawalayan Marinaut'],
        // Cotabato City
        '153801'=>['Bagua I','Bagua II','Bagua III','Kalanganan I','Kalanganan II',
                   'Poblacion I','Poblacion II','Poblacion III','Poblacion IV',
                   'Poblacion V','Poblacion VI','Poblacion VII','Poblacion VIII',
                   'Poblacion IX'],
        // Jolo (Sulu)
        '156601'=>['Bus-Bus','Chinese Pier','Poblacion 1','Poblacion 2',
                   'Poblacion 3','Poblacion 4','Poblacion 5','Poblacion 6',
                   'Poblacion 7','Poblacion 8','Takut-Takut','Tulay',
                   'Walled City','Gang','Asturias','Kajatian','Kan Islam',
                   'Kan Mindanao','Taglibi','Tulayan Island'],
        // Isabela City (Basilan)
        '150001'=>['Aguada','Airport','Balatanay','Baluno','Calvario',
                   'Carbon','Diki','Isabela Proper','Kapatagan','Kumalarang',
                   'Lampinigan','Lanote','Lukbuton','Lumbog','Marang',
                   'Market Kaumpurnah','Panigayan','Sunrise Village','Sunset Village',
                   'Taluksangay','Tamuk','Tipo-Tipo Proper','Tuburan',
                   'Upper Bonauto','Upper Cabunbata','Water Village'],
        // Bongao (Tawi-Tawi)
        '158001'=>['Bongao Poblacion','Karungdong','Lagasan','Lamion','Mabul Island',
                   'Pahut','Pasiagan','Sanga-Sanga','Simandagit','Tandubanak',
                   'Tubig-Basag','Tubig-Indangan','Tungbangkaw'],
        // Tuguegarao City
        '020401'=>['Annafunan East','Annafunan West','Atulayan Norte','Atulayan Sur',
                   'Buntun','Caggay','Capatan','Caritan Centro','Caritan Norte',
                   'Caritan Sur','Cataggaman Nuevo','Cataggaman Pardo','Cataggaman Viejo',
                   'Centro I','Centro II','Dadda','Gosi Norte','Gosi Sur',
                   'Larion Alto','Larion Bajo','Leonarda','Libag Norte','Libag Sur',
                   'Linao East','Linao Norte','Linao West','Nambbalan Norte',
                   'Nambbalan Sur','Pallua Norte','Pallua Sur','Pengue','Reyes',
                   'San Gabriel','Tagga','Tanza','Ugac Norte','Ugac Sur'],
        // Angeles City (Pampanga)
        '032101'=>['Agapito del Rosario','Anunas','Balibago','Capaya','Claro M. Recto',
                   'Cuayan','Cutcut I','Cutcut II','Cutud','Lourdes Norte','Lourdes Sur',
                   'Lourdes Sur East','Malabanias','Margot','Mining','Ninoy Aquino',
                   'Pampang','Pandan','Pulung Cacutud','Pulung Maragul','Salapungan',
                   'San Jose','San Nicolas','Santa Teresita','Santo Cristo','Santo Domingo',
                   'Santo Rosario','Sapalibutad','Sapangbato','Tabun','Virgen Delos Remedios'],
        // Malolos City (Bulacan)
        '031401'=>['Anilao','Atlag','Babatnin','Bagna','Bagong Bayan','Balayong',
                   'Balite','Bangkal','Barihan','Bulihan','Bungahan','Caingin',
                   'Calero','Calizon','Calumpang','Cangulo','Catmon','Cofradia',
                   'Dakila','Guinhawa','Ligas','Liyang','Longos','Look 1st',
                   'Look 2nd','Lugam','Mabolo','Mambog','Masile','Matimbo',
                   'Mojon','Namayan','Niugan','Pamarawan','Panasahan','Pinagbakahan',
                   'San Agustin','San Celestino','San Gabriel','San Juan',
                   'San Pablo','San Pedro I','San Pedro II','San Vicente',
                   'Santa Clara','Santa Cruz','Santo Cristo','Santo Niño','Santo Rosario',
                   'Santol','Sumapang Bata','Sumapang Matanda','Taal','Tikay'],
        // Bacoor City (Cavite)
        '042101'=>['Alima','Aniban I','Aniban II','Aniban III','Aniban IV','Aniban V',
                   'Bayanan','Digman','Dulong Bayan','Habay I','Habay II',
                   'Kaingin','Ligas I','Ligas II','Ligas III','Mabolo I','Mabolo II',
                   'Mabolo III','Maliksi I','Maliksi II','Maliksi III','Mambog I',
                   'Mambog II','Mambog III','Mambog IV','Mambog V','Molino I',
                   'Molino II','Molino III','Molino IV','Molino V','Molino VI',
                   'Niog I','Niog II','Niog III','P.F. Espiritu I','P.F. Espiritu II',
                   'P.F. Espiritu III','P.F. Espiritu IV','P.F. Espiritu V',
                   'P.F. Espiritu VI','P.F. Espiritu VII','Queens Row Central',
                   'Queens Row East','Queens Row West','Real I','Real II',
                   'Salinas I','Salinas II','Salinas III','Salinas IV',
                   'San Nicolas I','San Nicolas II','San Nicolas III',
                   'Sineguelasan','Tabing Dagat','Talaba I','Talaba II',
                   'Talaba III','Talaba IV','Talaba V','Talaba VI','Talaba VII',
                   'Zapote I','Zapote II','Zapote III','Zapote IV','Zapote V'],
        // Dasmariñas City (Cavite)
        '042103'=>['Burol I','Burol II','Burol III','Burol Main','Datu Esmael',
                   'Emmanuel Bergado I','Emmanuel Bergado II','Fatima I','Fatima II',
                   'Fatima III','Habay','Junction I','Junction II','Langkaan I',
                   'Langkaan II','Luzviminda I','Luzviminda II','Maliksi I',
                   'Maliksi II','Maliksi III','Merryland I','Merryland II',
                   'Paliparan I','Paliparan II','Paliparan III','Salawag',
                   'Salitran I','Salitran II','Salitran III','Salitran IV',
                   'San Agustin I','San Agustin II','San Agustin III',
                   'San Andres I','San Andres II','San Jose','San Manuel I',
                   'San Manuel II','San Miguel I','San Miguel II','San Simon',
                   'Santa Cristina I','Santa Cristina II','Santa Cruz I',
                   'Santa Cruz II','Santa Fe I','Santa Fe II','Santa Lucia',
                   'Santa Maria','Santol','Zone I','Zone II'],
    ]; // end barangays array
}

// ── ROUTER ────────────────────────────────────────────────────────────────────
switch ($type) {

    case 'regions':
        $regions = ph_regions();
        $out = [];
        foreach ($regions as $code => $name) {
            $out[] = ['code' => $code, 'name' => $name];
        }
        echo json_encode($out);
        break;

    case 'provinces':
        $region_code = trim($_GET['region_code'] ?? '');
        $provinces   = ph_provinces();
        $out = [];
        foreach ($provinces as $code => $row) {
            if ($row[1] === $region_code) {
                $out[] = ['code' => $code, 'name' => $row[0]];
            }
        }
        usort($out, fn($a,$b) => strcmp($a['name'], $b['name']));
        echo json_encode($out);
        break;

    case 'cities':
        $province_code = trim($_GET['province_code'] ?? '');
        $cities = array_merge(ph_cities(), ph_cities_ext());
        $out = [];
        foreach ($cities as $code => $row) {
            if ($row[1] === $province_code) {
                $out[] = ['code' => $code, 'name' => $row[0]];
            }
        }
        usort($out, fn($a,$b) => strcmp($a['name'], $b['name']));
        echo json_encode($out);
        break;

    case 'barangays':
        $city_code = trim($_GET['city_code'] ?? '');
        $barangays = ph_barangays();
        $list = $barangays[$city_code] ?? [];
        sort($list);
        $out = array_map(fn($b) => ['name' => $b], $list);
        echo json_encode($out);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid type parameter. Use: regions, provinces, cities, barangays']);
        break;
}
