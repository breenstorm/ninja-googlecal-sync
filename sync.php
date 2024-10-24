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
$dryrun = ($_ENV['DRYRUN']=="1");

$customer_separator = ",";
if (isset($_ENV['CUSTOMER_SEPARATOR'])) {
    $customer_separator = $_ENV['CUSTOMER_SEPARATOR'];
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
    $ical->initUrl($_ENV['ICAL_URL'], $username = null, $password = null, $userAgent = null);
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
echo "Getting events from ical... ";
$events = $ical->events();
echo sizeof($events);
echo "\n";
//file_put_contents("events.json",json_encode($events,JSON_PRETTY_PRINT));
if (sizeof($events)>0) {
    echo "Getting tasks from invoiceninja... ";
    $page = 1;
    $more = true;
    $tasks = ["data"=>[]];
    while ($more && ($page < 10)) {
        echo "page ".$page."... ";
        $subtasks = $ninja->tasks->all(["per_page"=>1000,"page"=>$page]);
        $tasks["data"] = array_merge($tasks["data"],$subtasks["data"]);
        if ($subtasks["meta"]["pagination"]["current_page"]==$subtasks["meta"]["pagination"]["total_pages"]) {
            $more = false;
        }
        $page++;
    }
    echo sizeof($tasks["data"]);
    echo "\n";
//    file_put_contents("tasks.json",json_encode($tasks,JSON_PRETTY_PRINT));

    //Get clients from invoiceninja
    echo "Getting clients from invoiceninja... ";
    $page = 1;
    $more = true;
    $clients = ["data"=>[]];
    while ($more && ($page < 10)) {
        echo "page ".$page."... ";
        $subclients = $ninja->clients->all(["per_page"=>1000,"page"=>$page]);
        $clients["data"] = array_merge($clients["data"],$subclients["data"]);
        if ($subclients["meta"]["pagination"]["current_page"]==$subclients["meta"]["pagination"]["total_pages"]) {
            $more = false;
        }
        $page++;
    }
    echo sizeof($clients["data"]);
    echo "\n";

    //Get projects from invoiceninja
    echo "Getting projects from invoiceninja... ";
    $page = 1;
    $more = true;
    $projects = ["data"=>[]];
    while ($more && ($page < 10)) {
        echo "page ".$page."... ";
        $subprojects = $ninja->projects->all(["per_page"=>1000,"page"=>$page]);
        $projects["data"] = array_merge($projects["data"],$subprojects["data"]);
        if ($subprojects["meta"]["pagination"]["current_page"]==$subprojects["meta"]["pagination"]["total_pages"]) {
            $more = false;
        }
        $page++;
    }
    echo sizeof($projects["data"]);
    echo "\n";


    echo "Matching events and tasks...\n";
    foreach ($events as $event) {
        $dtstart = $ical->iCalDateToDateTime($event->dtstart);
        $dtend = $ical->iCalDateToDateTime($event->dtend);
        $guid = $event->uid;
        echo $dtstart->format("Y\-m\-d H:i:s")." - ".$dtend->format("Y\-m\-d H:i:s")." ".$event->summary." GUID:".$guid."\n";

        //find matching client
        $client_bestscore = 0;
        $client_bestmatch = null;
        $description = explode($customer_separator,$event->summary);
        $desc = explode("/",$description[0]);
        foreach ($clients["data"] as $client) {
            if ($client["archived_at"]==null) {
                $thisscore = 0;
                similar_text($desc[0],$client["name"],$thisscore);
                if ($thisscore>$client_bestscore) {
                    $client_bestscore = $thisscore;
                    $client_bestmatch = $client;
                }
            }
        }

        //find matching project
        $project_bestscore = 0;
        $project_bestmatch = null;
        if (isset($desc[1])) {
            foreach ($projects["data"] as $project) {
                if ($project["client_id"]==$client_bestmatch["id"]) {
                    $thisscore = 0;
                    similar_text($desc[1],$project["name"],$thisscore);
                    if ($thisscore>$client_bestscore) {
                        $project_bestscore = $thisscore;
                        $project_bestmatch = $project;
                    }
                }
            }
        }

        //find matching task
        $existingtask = null;
        foreach ($tasks["data"] as $task) {
            if ($task["custom_value1"] == $refprefix.$guid) {
                $existingtask = $task;
            }
        }

        //this event can be linked to a client. process it.
        if ($client_bestmatch!==null) {
            echo "Best matching client is ".$client_bestmatch["name"]."\n";
            if ($project_bestmatch!==null) {
                echo "Best matching project is ".$project_bestmatch["name"]."\n";
            } else {
                echo "No matching project\n";
            }

            if (sizeof($description)>1) {
                array_splice($description,0,1);
            }

            //is the task new?
            if ($existingtask===null) {
                echo "No matching task found for event at ".$event->summary." at ".$dtstart->format("Y\-m\-d H:i:s")."\n";
                //add task for client
                $taskdata = [];
                $taskdata["client_id"] = $client_bestmatch["id"];
                $taskdata["custom_value1"] = $refprefix.$guid;
                if ($project_bestmatch!==null) {
                    $taskdata["project_id"] = $project_bestmatch["id"];
                }
                $taskdata["description"] = trim(implode(",",$description));
                $taskdata["status_id"] = "wMvbmOeYAl";
                $taskdata["time_log"] = json_encode([[$dtstart->getTimestamp(),$dtend->getTimestamp()]]);

                echo "Creating new task\n";
                if (!$dryrun) {
                    $res = $ninja->tasks->create($taskdata);
                } else {
                    echo var_export($taskdata,true);
                }
            } else {
                echo "Task for event ".$event->summary." at ".$dtstart->format("Y\-m\-d H:i:s")." exists.\n";
                //check if task is invoiced
                if ($existingtask["invoice_id"]=="") {
                    //update task
                    $existingtask["client_id"] = $client_bestmatch["id"];
                    $existingtask["custom_value1"] = $refprefix.$guid;
                    if ($project_bestmatch!==null) {
                        $existingtask["project_id"] = $project_bestmatch["id"];
                    }
                    $existingtask["description"] = trim(implode(",",$description));
                    $existingtask["status_id"] = "wMvbmOeYAl";
                    $existingtask["time_log"] = json_encode([[$dtstart->getTimestamp(),$dtend->getTimestamp()]]);

                    echo "Updating task\n";
                    if (!$dryrun) {
                        $res = $ninja->tasks->update($existingtask["id"],$existingtask);
                    } else {
                        echo var_export($existingtask,true);
                    }
                } else {
                    echo "Task is invoiced. Leaving it as is.\n";
                }
            }

        } else {
            echo "No client found for event. Skipping.\n";
        }

    }
    echo "Done\n";
} else {
    echo "No events found. Done.\n";
}

