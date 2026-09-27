<?php

/*
 * Demo data of the Studio Weber world (shrippen demo) for Farbfächer: the core
 * data uses distinct Kante colours, so this adds what a real Kimai collects over
 * time: last season's projects and extra activities in the same colours as
 * current ones, some without any colour, all booked in the same weeks.
 * Needs the core data first (shrippen.github.io/demo/kimai/seed-core.php);
 * demo/start.sh runs both.
 */

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Kernel;

require '/opt/kimai/vendor/autoload.php';
require __DIR__ . '/DemoWorld.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/opt/kimai/.env');

$kernel = new Kernel('prod', false);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();

$world = new DemoWorld(getenv('DEMO_LANG') ?: 'de', null, 'today');
$w = $world->data;
$de = $world->lang === 'de';
$customer = static fn (string $id) => $em->getRepository(Customer::class)->findOneBy(['name' => array_column($w['customers'], 'name', 'id')[$id]]);
$mara = $em->getRepository(User::class)->findOneBy(['email' => 'mara@studio-weber.example.test'])
    ?? throw new RuntimeException('Core demo data missing (seed-core.php first).');
$selin = $em->getRepository(User::class)->findOneBy(['email' => 'selin@studio-weber.example.test']);

$extraProjects = [
    // customer, name, colour (same as a current project, or none)
    ['northlight', $de ? 'Harbour Lights – Staffel 1' : 'Harbour Lights – Season 1', '#fe8019'],
    ['speiche', $de ? 'Herbstspot' : 'Autumn commercial', '#83a598'],
    ['elbgruen', $de ? 'Jahresbericht-Video' : 'Annual report video', '#8ec07c'],
    ['studio', 'Showreel 2025', null],
];
$extraActivities = [
    [$de ? 'Farbkorrektur' : 'Colour grading', '#83a598'],
    [$de ? 'Drehvorbereitung' : 'Shoot prep', '#d3869b'],
    [$de ? 'Sichtung' : 'Footage review', null],
    [$de ? 'Tonschnitt' : 'Dialogue edit', '#8ec07c'],
];
if ($em->getRepository(Project::class)->findOneBy(['name' => $extraProjects[0][1]]) !== null) {
    echo "Already seeded.\n";
    exit(0);
}

$projects = [];
foreach ($extraProjects as [$cid, $name, $color]) {
    $p = new Project();
    $p->setName($name);
    $p->setCustomer($customer($cid));
    $p->setColor($color);
    $em->persist($p);
    $projects[] = $p;
}
$activities = [];
foreach ($extraActivities as [$name, $color]) {
    $a = new Activity();
    $a->setName($name);
    $a->setColor($color);
    $em->persist($a);
    $activities[] = $a;
}
$em->flush();          // ids first: Farbfächer's listeners query the new entries

// Booked in the same weeks as the current work, so the clashes count.
$count = 0;
for ($week = -6; $week <= -1; $week++) {
    foreach ([[$mara, 1], [$selin, 3]] as [$user, $weekday]) {
        foreach ([0, 1] as $slot) {
            $i = ($week + 6 + $slot) % 4;
            $begin = $world->date($week * 7 + $weekday + $slot, $slot ? '14:00' : '09:00');
            $t = new Timesheet();
            $t->setUser($user);
            $t->setProject($projects[$i]);
            $t->setActivity($activities[($i + $slot) % 4]);
            $t->setBegin(DateTime::createFromImmutable($begin));
            $t->setEnd(DateTime::createFromImmutable($begin->modify('+2 hours')));
            $t->setDuration(7200);
            $em->persist($t);
            $count++;
        }
    }
}
$em->flush();

echo 'Seeded ' . count($projects) . ' older projects, ' . count($activities) . " activities with clashing or missing colours, $count timesheets.\n";
