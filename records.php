<?php
// 1. Připojení konfigurace
require_once '../../../config_chmu.php';
// 2. Připojení k DB pomocí konstant z configu
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset("utf8mb4");


// Kolik stanic zpracovat na jedno načtení (F5)
$limit_stanic = 20; 
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

// --- NOVÉ: Zjištění celkového počtu stanic ---
// Uložíme si to do jednoduchého dotazu, aby to bylo bleskové
$sql_celkem = "SELECT COUNT(DISTINCT sid) as celkem FROM pocasi";
$res_celkem = $conn->query($sql_celkem);
$row_celkem = $res_celkem->fetch_assoc();
$celkem_stanic = $row_celkem['celkem'];

// Výpočet procent
$procenta = ($celkem_stanic > 0) ? round(($offset / $celkem_stanic) * 100, 1) : 0;
if ($procenta > 100) $procenta = 100;

echo "<h2>Výpočet KOMPLETNÍCH historických rekordů</h2>";
echo "<p>Celkový počet stanic k výpočtu: <strong>$celkem_stanic</strong></p>";

// Jednoduchý vizuální progress bar
echo "<div style='width: 100%; max-width: 500px; background: #eee; border: 1px solid #ccc; border-radius: 5px; margin-bottom: 15px;'>
        <div style='width: $procenta%; background: #4CAF50; height: 25px; transition: width 0.5s; text-align: center; color: white; line-height: 25px; font-weight: bold;'>
            " . (($procenta > 5) ? "$procenta %" : "") . "
        </div>
      </div>";

echo "<p>Zpracovávám dávku: <strong>$offset</strong> až <strong>" . min($offset + $limit_stanic, $celkem_stanic) . "</strong>...</p><hr>";

// Zjistíme, které stanice máme zpracovat v této dávce
$sql_stanice = "SELECT DISTINCT sid FROM pocasi ORDER BY sid LIMIT $limit_stanic OFFSET $offset";
$result_stanice = $conn->query($sql_stanice);

if ($result_stanice->num_rows == 0 || $offset >= $celkem_stanic) {
    die("<h3 style='color: green;'>HOTOVO! Všechny rekordy pro $celkem_stanic stanic jsou spočítány.</h3>");
}

// Připravíme si vkládací dotaz pro všech 23 sloupců
$stmt_insert = $conn->prepare("
    REPLACE INTO statistika_rekordy 
    (sid, t_max, t_max_den, t_min, t_min_den, srazky_max, srazky_max_den, 
     snih_celkem_max, snih_celkem_max_den, snih_novy_max, snih_novy_max_den,
     vitr_max, vitr_max_den, tlak_max, tlak_max_den, tlak_min, tlak_min_den,
     slunecni_svit_max, slunecni_svit_max_den, svh_max, svh_max_den, api_30_max, api_30_max_den) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$zpracovano = 0;

while ($row = $result_stanice->fetch_assoc()) {
    $sid = $row['sid'];
    
    // Zde budeme ukládat výsledky
    $rekordy = array_fill_keys([
        't_max', 't_max_den', 't_min', 't_min_den',
        'srazky_max', 'srazky_max_den', 'snih_celkem_max', 'snih_celkem_max_den',
        'snih_novy_max', 'snih_novy_max_den', 'vitr_max', 'vitr_max_den',
        'tlak_max', 'tlak_max_den', 'tlak_min', 'tlak_min_den',
        'slunecni_svit_max', 'slunecni_svit_max_den', 'svh_max', 'svh_max_den',
        'api_30_max', 'api_30_max_den'
    ], null);

    // --- POMOCNÁ FUNKCE ---
    $najdi_rekord = function($sloupec, $razeni) use ($conn, $sid) {
        $sql = "SELECT $sloupec, den FROM pocasi WHERE sid = $sid AND $sloupec IS NOT NULL ORDER BY $sloupec $razeni LIMIT 1";
        $res = $conn->query($sql);
        return ($res && $res->num_rows > 0) ? $res->fetch_assoc() : false;
    };

    // 1. Teploty a Srážky
    if ($r = $najdi_rekord('t_max', 'DESC')) { $rekordy['t_max'] = $r['t_max']; $rekordy['t_max_den'] = $r['den']; }
    if ($r = $najdi_rekord('t_min', 'ASC'))  { $rekordy['t_min'] = $r['t_min']; $rekordy['t_min_den'] = $r['den']; }
    if ($r = $najdi_rekord('srazky', 'DESC')) { $rekordy['srazky_max'] = $r['srazky']; $rekordy['srazky_max_den'] = $r['den']; }

    // 2. Sníh
    if ($r = $najdi_rekord('snih_celkem', 'DESC')) { $rekordy['snih_celkem_max'] = $r['snih_celkem']; $rekordy['snih_celkem_max_den'] = $r['den']; }
    if ($r = $najdi_rekord('snih_novy', 'DESC'))   { $rekordy['snih_novy_max'] = $r['snih_novy']; $rekordy['snih_novy_max_den'] = $r['den']; }

    // 3. NOVÉ: Vítr a Tlak
    if ($r = $najdi_rekord('vitr_rychlost', 'DESC')) { $rekordy['vitr_max'] = $r['vitr_rychlost']; $rekordy['vitr_max_den'] = $r['den']; }
    if ($r = $najdi_rekord('tlak', 'DESC'))          { $rekordy['tlak_max'] = $r['tlak']; $rekordy['tlak_max_den'] = $r['den']; }
    if ($r = $najdi_rekord('tlak', 'ASC'))           { $rekordy['tlak_min'] = $r['tlak']; $rekordy['tlak_min_den'] = $r['den']; }

    // 4. NOVÉ: Slunce a Indexy
    if ($r = $najdi_rekord('slunecni_svit', 'DESC')) { $rekordy['slunecni_svit_max'] = $r['slunecni_svit']; $rekordy['slunecni_svit_max_den'] = $r['den']; }
    if ($r = $najdi_rekord('svh', 'DESC'))           { $rekordy['svh_max'] = $r['svh']; $rekordy['svh_max_den'] = $r['den']; }
    if ($r = $najdi_rekord('api_30', 'DESC'))        { $rekordy['api_30_max'] = $r['api_30']; $rekordy['api_30_max_den'] = $r['den']; }

    // Zápis do databáze
    $stmt_insert->bind_param("idssdssdssdssdssdssdssd", 
        $sid, 
        $rekordy['t_max'], $rekordy['t_max_den'],
        $rekordy['t_min'], $rekordy['t_min_den'],
        $rekordy['srazky_max'], $rekordy['srazky_max_den'],
        $rekordy['snih_celkem_max'], $rekordy['snih_celkem_max_den'],
        $rekordy['snih_novy_max'], $rekordy['snih_novy_max_den'],
        $rekordy['vitr_max'], $rekordy['vitr_max_den'],
        $rekordy['tlak_max'], $rekordy['tlak_max_den'],
        $rekordy['tlak_min'], $rekordy['tlak_min_den'],
        $rekordy['slunecni_svit_max'], $rekordy['slunecni_svit_max_den'],
        $rekordy['svh_max'], $rekordy['svh_max_den'],
        $rekordy['api_30_max'], $rekordy['api_30_max_den']
    );
    $stmt_insert->execute();
    
    $zpracovano++;
}

echo "<p style='color: blue;'>Úspěšně zpracováno a uloženo dalších $zpracovano stanic.</p>";

// --- AUTOMATICKÝ REFRESH PRO DALŠÍ DÁVKU ---
$dalsi_offset = $offset + $limit_stanic;
echo "<script>setTimeout(function(){ window.location.href = '?offset=$dalsi_offset'; }, 500);</script>";

?>