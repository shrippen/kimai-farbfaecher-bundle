<?php
// Prints SQL with sample data: colors taken from Kimai's fixed palette, so typical clashes exist.
// Usage (dev/reset.sh): php dev/seed.php | mariadb kimai
mt_srand(42);
$q = fn ($s) => $s === null ? 'NULL' : "'" . addslashes($s) . "'";

$customers = [
    1 => ['Stadtwerke Nord', '#0000ff', 1],
    2 => ['Bäckerei Krume', '#ffa500', 1],
    3 => ['Agentur Pixelwerk', '#ffd700', 1],
    4 => ['Sportfreunde 09 e.V.', '#008000', 1],
    5 => ['Kanzlei Berger', '#000080', 1],
    6 => ['Praxis Dr. Sommer', '#008080', 1],
    7 => ['Intern', '#808080', 1],
    8 => ['Möbelhaus Lindner', '#0000ff', 1],
    9 => ['Buchladen Seite 42', '#1e90ff', 1],
    10 => ['Altkunde GmbH', '#ff0000', 0],
];
$projects = [
    // id => [customer, name, color, visible]
    1 => [1, 'Kundenportal', '#1e90ff', 1],
    2 => [1, 'Störungsmelder App', '#0000ff', 1],
    3 => [1, 'Wartungsvertrag', null, 1],
    4 => [2, 'Onlineshop', '#ffa500', 1],
    5 => [2, 'Filialfinder', '#ffd700', 1],
    6 => [3, 'Relaunch Website', '#ffd700', 1],
    7 => [3, 'Newsletter-Templates', '#ffff00', 1],
    8 => [3, 'Kampagne Frühjahr', '#ffa500', 1],
    9 => [3, 'Retainer', null, 1],
    10 => [4, 'Mitgliederverwaltung', '#008000', 1],
    11 => [4, 'Vereinsheft', '#9acd32', 1],
    12 => [5, 'Mandantenportal', '#000080', 1],
    13 => [5, 'DSGVO-Audit', '#800080', 1],
    14 => [6, 'Terminbuchung', '#008080', 1],
    15 => [6, 'Praxis-Website', '#00ffff', 1],
    16 => [7, 'Weiterbildung', '#c0c0c0', 1],
    17 => [7, 'Verwaltung', '#808080', 1],
    18 => [7, 'Akquise', null, 1],
    19 => [8, 'Produktkonfigurator', '#0000ff', 1],
    20 => [8, 'Warenwirtschaft Anbindung', '#1e90ff', 1],
    21 => [9, 'Webshop', '#00bfff', 1],
    22 => [9, 'Lesungen-Kalender', '#add8e6', 1],
    23 => [10, 'Altprojekt', '#ff0000', 0],
    24 => [2, 'Kassensystem', null, 1],
];
$activities = [
    // id => [project|null, name, color]
    1 => [null, 'Entwicklung', '#0000ff'],
    2 => [null, 'Meeting', '#ffa500'],
    3 => [null, 'Support', '#ff0000'],
    4 => [null, 'Konzeption', '#800080'],
    5 => [null, 'Design', '#ee82ee'],
    6 => [null, 'Projektmanagement', '#808080'],
    7 => [null, 'Reisezeit', '#c0c0c0'],
    8 => [null, 'Dokumentation', null],
    9 => [null, 'Testing', '#008000'],
    10 => [null, 'Wartung', '#008080'],
    11 => [null, 'Code-Review', '#1e90ff'],
    12 => [null, 'Schulung', '#ffd700'],
    13 => [4, 'Produktpflege', '#ffa500'],
    14 => [6, 'Content-Migration', '#ffd700'],
    15 => [14, 'Schnittstelle Praxissoftware', null],
    16 => [12, 'Rechtliche Abstimmung', '#000080'],
];

// Admin: onboarding done, German locale
$sql = "SET @admin = (SELECT id FROM kimai2_users WHERE username = 'admin');\n";
$sql .= "DELETE FROM kimai2_user_preferences WHERE user_id = @admin AND name IN ('__wizards__', 'language', 'locale', 'timezone');\n";
$sql .= "INSERT INTO kimai2_user_preferences (user_id, name, value) VALUES (@admin, '__wizards__', 'intro,profile'), (@admin, 'language', 'de'), (@admin, 'locale', 'de'), (@admin, 'timezone', 'Europe/Berlin');\n";
$sql .= "SET FOREIGN_KEY_CHECKS=0;\nDELETE FROM kimai2_timesheet; DELETE FROM kimai2_activities; DELETE FROM kimai2_projects; DELETE FROM kimai2_customers;\nSET FOREIGN_KEY_CHECKS=1;\n";
foreach ($customers as $id => [$name, $color, $visible]) {
    $sql .= "INSERT INTO kimai2_customers (id, name, visible, color, country, currency, timezone) VALUES ($id, {$q($name)}, $visible, {$q($color)}, 'DE', 'EUR', 'Europe/Berlin');\n";
}
foreach ($projects as $id => [$customer, $name, $color, $visible]) {
    $sql .= "INSERT INTO kimai2_projects (id, customer_id, name, visible, color) VALUES ($id, $customer, {$q($name)}, $visible, {$q($color)});\n";
}
foreach ($activities as $id => [$project, $name, $color]) {
    $p = $project === null ? 'NULL' : $project;
    $sql .= "INSERT INTO kimai2_activities (id, project_id, name, visible, color) VALUES ($id, $p, {$q($name)}, 1, {$q($color)});\n";
}

// Each week the user works on a few "current" projects; some pairs are always together.
$activeProjects = [1, 2, 4, 5, 6, 7, 8, 12, 14, 15, 19, 20, 21, 17, 18, 24, 10];
$globalActs = [1, 2, 3, 4, 5, 6, 8, 9, 10, 11, 12];
$projectActs = [4 => 13, 6 => 14, 14 => 15, 12 => 16];
$start = new DateTime('-120 days');
$start->setTime(0, 0);
$now = new DateTime();
$rows = [];
for ($day = clone $start; $day < $now; $day->modify('+1 day')) {
    if ((int) $day->format('N') >= 6) {
        continue;
    }
    $week = (int) $day->format('W');
    mt_srand($week * 7);
    $weekly = array_rand(array_flip($activeProjects), 4);
    $weekly[] = 1; // Kundenportal is always on
    $weekly[] = 19; // Produktkonfigurator too (same color as Störungsmelder / customer!)
    if ($week % 2 === 0) {
        $weekly[] = 20;
        $weekly[] = 2;
    }
    mt_srand((int) $day->format('Ymd'));
    $hour = 8;
    $entries = mt_rand(2, 4);
    for ($i = 0; $i < $entries; $i++) {
        $project = $weekly[array_rand($weekly)];
        $activity = isset($projectActs[$project]) && mt_rand(0, 2) === 0 ? $projectActs[$project] : $globalActs[array_rand($globalActs)];
        $duration = mt_rand(1, 3) * 3600;
        $begin = (clone $day)->setTime($hour, 0);
        $end = (clone $begin)->modify('+' . $duration . ' seconds');
        $hour += $duration / 3600;
        $rows[] = sprintf("(@admin, %d, %d, '%s', '%s', %d, 0, 'Europe/Berlin', '%s', 1)", $activity, $project, $begin->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $duration, $begin->format('Y-m-d'));
    }
}
$sql .= "INSERT INTO kimai2_timesheet (user, activity_id, project_id, start_time, end_time, duration, rate, timezone, date_tz, billable) VALUES\n" . implode(",\n", $rows) . ";\n";
echo $sql;
