<?php
// phpcs:disable Generic.Arrays.DisallowLongArraySyntax

require_once 'vendor/autoload.php';

use ICal\ICal;
use InvoiceNinja\Sdk\InvoiceNinja;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$days = intval($_ENV['DAYS']);
$refprefix = $_ENV['REFPREFIX'];
$dryrun = false;
if (isset($_ENV['DRYRUN'])) {
    $dryrun = ($_ENV['DRYRUN']=="1");
}

$lookback = new DateInterval("P".$days."D");
$startdate = new DateTime();
$startdate->sub($lookback);

try {
    $ical = new ICal(false, array(
        'defaultSpan'                 => 2,     // Default value
        'defaultTimeZone'             => 'Europe/Amsterdam',
        'defaultWeekStart'            => 'SU',  // Default value
        'disableCharacterReplacement' => false, // Default value
        'filterDaysAfter'             => 0,  // Default value
        'filterDaysBefore'            => $days,  // Default value
        'httpUserAgent'               => null,  // Default value
        'skipRecurrence'              => false, // Default value
    ));
    $ical->initUrl($_ENV['GCAL_URL'], $username = null, $password = null, $userAgent = null);
} catch (\Exception $e) {
    die($e);
}

try {
    $ninja = new InvoiceNinja($_ENV['NINJA_TOKEN']);
    $ninja->setUrl($_ENV['NINJA_URL']);
} catch (\Exception $e) {
    die($e);
}
echo "Looking back ".$_ENV['DAYS']." days\n";
//get calendar items
echo "Getting events... ";
$events = $ical->events();
echo sizeof($events);
echo "\n";
if (sizeof($events)>0) {
    echo "Getting tasks... ";
    $tasks = $ninja->tasks->all(["per_page"=>9999999]);
    echo sizeof($tasks["data"]);
    echo "\n";
    echo "Getting clients... ";
    $clients = $ninja->clients->all(["per_page"=>9999999]);
    echo sizeof($clients["data"]);
    echo "\n";
    echo "Matching events and tasks...\n";
    foreach ($events as $event) {
        $dtstart = $ical->iCalDateToDateTime($event->dtstart);
        $dtend = $ical->iCalDateToDateTime($event->dtend);
        $guid = $event->uid;
        echo $dtstart->format("Y\-m\-d H:i:s")." - ".$dtend->format("Y\-m\-d H:i:s")." ".$event->summary." GUID:".$guid."\n";
        $found = false;
        foreach ($tasks["data"] as $task) {
//        echo "Comparing ".$task["custom_value1"]." to ".$refprefix.$guid."";
            if ($task["custom_value1"] == $refprefix.$guid) {
//            echo "MATCH!";
                $found = true;
            }
//        echo "\n";
        }
        if (!$found) {
            echo "No matching task found. Creating task for event ".$event->summary." at ".$dtstart->format("Y\-m\-d H:i:s")."\n";
            //find matching client
            $bestscore = 0;
            $bestmatch = null;
            $description = explode(",",$event->summary);
            foreach ($clients["data"] as $client) {
                $thisscore = 0;
                foreach (array_reverse($description) as $value) {
                    $thissubscore = 0;
                    similar_text($value,$client["name"],$thissubscore);
                    $thisscore = (0.1*$thisscore) + $thissubscore;
                }
                if ($thisscore>$bestscore) {
//                    echo "Client ".$client["name"]." matches better at ".$thisscore."%\n";
                    $bestscore = $thisscore;
                    $bestmatch = $client;
                }
            }
            if ($bestmatch!==null) {
//            echo "Best match is ".$bestmatch["name"]." ".$bestmatch["id"]."\n";
                //add task for client
                $client_id = $bestmatch["id"];
                $taskdata = [];
                $taskdata["client_id"] = $client_id;
                $taskdata["custom_value1"] = $refprefix.$guid;
                if (sizeof($description)>1) {
                    array_splice($description,0,1);
                }
                $taskdata["description"] = trim(implode(",",$description));
                $taskdata["status_id"] = "wMvbmOeYAl";
                $taskdata["time_log"] = json_encode([[$dtstart->getTimestamp(),$dtend->getTimestamp()]]);
                if (!$dryrun) {
                    $res = $ninja->tasks->create($taskdata);
                }
            } else {
                echo "No client found for event. Skipping.\n";
            }
        } else {
            echo "Task for event ".$event->summary." at ".$dtstart->format("Y\-m\-d H:i:s")." already exists. Skipping\n";
        }
    }
    echo "Done\n";
} else {
    echo "No events found. Done.\n";
}

