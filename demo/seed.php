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
$customer = static fn (string $id) => $em->getRepository(Customer::class)->findOneBy(['name' => array_column($w['customers'], 'name', 'id')[$id]]);
$archive = $w['archive'];
$bookings = $archive['bookings'];
$people = array_column($w['people'], 'email', 'id');
$users = [];
foreach ($bookings['people'] as $b) {
    $users[] = [$em->getRepository(User::class)->findOneBy(['email' => $people[$b['user']]])
        ?? throw new RuntimeException('Core demo data missing (seed-core.php first).'), $b['weekday']];
}
// customer, name, colour (same as a current project, or none)
$extraProjects = array_map(static fn (array $p) => [$p['customer'], $world->t($p['name']), $p['color']], $archive['projects']);
$extraActivities = array_map(static fn (array $a) => [$world->t($a['name']), $a['color']], $archive['activities']);
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
[$firstWeek, $lastWeek] = $bookings['weeks'];
$slots = $bookings['slots'];
for ($week = $firstWeek; $week <= $lastWeek; $week++) {
    foreach ($users as [$user, $weekday]) {
        foreach (array_keys($slots) as $slot) {
            $i = ($week - $firstWeek + $slot) % count($projects);
            $begin = $world->date($week * 7 + $weekday + $slot, $slots[$slot]);
            $t = new Timesheet();
            $t->setUser($user);
            $t->setProject($projects[$i]);
            $t->setActivity($activities[($i + $slot) % count($activities)]);
            $t->setBegin(DateTime::createFromImmutable($begin));
            $t->setEnd(DateTime::createFromImmutable($begin->modify('+' . $bookings['hours'] . ' hours')));
            $t->setDuration($bookings['hours'] * 3600);
            $em->persist($t);
            $count++;
        }
    }
}
$em->flush();

echo 'Seeded ' . count($projects) . ' older projects, ' . count($activities) . " activities with clashing or missing colours, $count timesheets.\n";
