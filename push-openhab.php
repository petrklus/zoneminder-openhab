#!/usr/bin/php
<?php
/*
Author: Petr Klus
INITIAL SCRIPT FROM: https://code.google.com/p/openhab-samples/wiki/integration

*/

//THIS ARRAY CONTAINS THE MONITOR IDS AND THE OPENHAB ITEMS YOU WANT TO UPDATE.  THE OPENHAB ITEM IS A SIMPLE NUMBER ITEM THAT STORES THE COUNT

$oh_items = array(
                   1 => array(
                        'monitorId' => '1', 
                        'openhabItem' => 'ZM_testAlarm',                         
                        'openhabItemCounter' => 'ZM_testAlarmCounter', 
                        'lastCount' => 0
                    ),
                );

$openhab_url = "http://192.168.2.212:8080/";

function getValByID($id) {
  $res = file_get_contents($GLOBALS["openhab_url"]. "rest/items/" . $id . "/state");
  return $res;
}

function doPostRequest($item, $data) {
  $url = $GLOBALS["openhab_url"]. "rest/items/" . $item;

  $options = array(
    'http' => array(
        'header'  => "Content-type: text/plain\r\n",
        'method'  => 'POST',
        'content' => $data  //http_build_query($data),
    ),
  );

  $context  = stream_context_create($options);
  $result = file_get_contents($url, false, $context);

  return $result;
}

$lastId = 0;
$countCheckTimestamp = time();

//FIRST RUN, BEFORE WE GO INTO THE MAIN LOOP MAKE SURE ALL COUNTS IN OPENHAB ARE SET TO 0
foreach ($oh_items as $curCount) {
  doPostRequest($curCount['openhabItemCounter'], "0");
}

$con = mysqli_connect("localhost", "root", "", "zm");

if (mysqli_connect_errno($con)) {
  exec('logger "zoneminderAlarm cannot connect to database"');
} else {
  while(1 == 1) {
    //CHECK FOR EVENTS
    if ($result = $con->query("select * from Events order by Id desc limit 1")) {
      while ($row = $result->fetch_row()) {
        if ($lastId == 0) {
          $lastId = $row[0];
        }

        if ($row[0] > $lastId) {
          $lastId = $row[0];
          //$row[1] IS THE MONITOR ID IN ZONEMINDER, TO FIND THIS GO TO THE ZONEMINDER WEBSITE, POINT TO THE LINK TO VIEW THE MONITOR, LOOK AT THE LINK URL, MID= IS THE MONITOR ID
          $monitor_id = intval($row[1]);
          echo "Alarm on:".$monitor_id;
          if (array_key_exists($monitor_id, $oh_items)) {
              doPostRequest($oh_items[$monitor_id]["openhabItem"], "ON");
          }
        }
      }
    }

    //EVERY 30 SECONDS UPDATE THE EVENT COUNTS
    if (time() - $countCheckTimestamp >= 30) {
      $countCheckTimestamp = time();
      //GET EVENT COUNTS
      $curDate = date("Y-m-d");

      foreach ($oh_items as $key => $curCount) {

        if ($result = $con->query("select count(Id) as num from Events where MonitorId = '" . $curCount['monitorId'] . "' and StartTime like '" . $curDate . "%'")) {
          $row = $result->fetch_row();

          if (is_numeric($row[0])) {
            //ONLY SEND A COUNT TO OPENHAB IF IT HAS CHANGED
            if ($row[0] <> $curCount['lastCount']) {
              $oh_items[$key]['lastCount'] = $row[0];
              doPostRequest($curCount['openhabItemCounter'], $row[0]);
            }
          } else {
            $oh_items[$key]['lastCount'] = "0";
            doPostRequest($curCount['openhabItemCounter'], "0");
          }
        }
      } //foreach ($oh_items as $curCount)
    } //if (time() - $countCheckTimestamp > 30)

    sleep(1);
  }
}
?>