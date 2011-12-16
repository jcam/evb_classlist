<?php

include("phpmailer/class.phpmailer.php");
include("phpmailer/class.smtp.php");

//Set up the connection key parameters
$userkey = '<user_key>';
$appkey = '<app_key>';
$classfile = 'instructor_event_map.csv';
$instructorfile = 'instructor_list.csv';
$apiroot = 'https://www.eventbrite.com/xml';

if ( $_SERVER['REQUEST_METHOD'] == 'GET' ) {
	print "<html><head><title>Class List</title></head><body>\n";
}

if ( empty ( $_REQUEST['instructor_id'] ) ) {
	print '<h1>Malformed Request</h1>';
	print '</body></html>';
	exit();
}
else {
	$instructor_id = $_REQUEST['instructor_id'];
}

if ( $instructor_id == '<admin_id>' ) {
	management($instructorfile, $classfile);
	exit();
}

if ( !file_exists($classfile)) {
	print '<h1>Error reading map</h1>';
	print '</body></html>';
	exit();
}

$fd = fopen($classfile, "r");
while (( $row = fgetcsv($fd, 1024)) != FALSE) {
	if ( $row[0] == $instructor_id ) {
		$events[]=$row[1];
	}
}
fclose($fd);

if ( empty ( $events ) ) {
	print '<h1>Malformed Request</h1>';
	print '</body></html>';
	exit();
}

foreach ( $events as $event_id ) {
	//grab the event name

	$req = "$apiroot/event_get?user_key=$userkey&app_key=$appkey&id=$event_id";
	$res = file_get_contents($req);

	$evxml = new SimpleXMLElement($res);

	//grab the attendees
	
	$req = "$apiroot/event_list_attendees?user_key=$userkey&app_key=$appkey&id=$event_id";
	$res = file_get_contents($req);
 
	$xml = new SimpleXMLElement($res);
	
	$emails["0"] = '';
	$list["0"] = '';

	foreach ( $xml->attendee as $attendee ) {
		if ( isset ( $attendee->event_date )) {
			$arrnum = $attendee->event_date;
		}
		else {
			$arrnum = 0;
		}
		if ( empty ( $emails["{$arrnum}"] )) {
			$emails["{$arrnum}"] = "{$attendee->email}";
		}
		else {
			$emails["{$arrnum}"] .= ",{$attendee->email}";
		}
		$list["{$arrnum}"] .= "{$attendee->first_name} {$attendee->last_name} ({$attendee->quantity}) <a href='mailto:{$attendee->email}'>{$attendee->email}</a><br />\n";
	}
	
	if ( count ( $emails ) > 1 ) {
	
		foreach ( $emails as $arrnum => $email ) {
			print "<b>Class: <a href='http://www.eventbrite.com/event/{$evxml->id}'>{$evxml->title}</a> on {$arrnum}</b><br />\n";
			print "<a href='mailto:{$email}'>Email full class</a><br />\n";
			print $list[$arrnum];
			print "<br />\n";
		}
	}
	else {
		print "<b>Class: <a href='http://www.eventbrite.com/event/{$evxml->id}'>{$evxml->title}</a></b><br />\n";
		print "<a href='mailto:{$emails["0"]}'>Email full class</a><br />\n";
		print $list["0"];
		print "<br />\n";
	}
}
print "</body></html>";

function management($instructorfile, $classfile) {

	//instructors
	
	if ( !file_exists($instructorfile)) {
		print '<h1>Error reading map</h1>';
		print '</body></html>';
		exit();
	}

	$fd = fopen($instructorfile, "r");
	while (( $row = fgetcsv($fd, 1024)) != FALSE) {
		$instructors[$row[0]] = array( 'id' => $row[0], 'name' => $row[1], 'email' => $row[2]);
	}
	fclose($fd);
	
	if ( isset ( $_POST['btninstdelete'] )) {
		unset($instructors[$_POST['instructor']]);
		updateInstructors($instructorfile, $instructors);
	}
	
	if ( isset ( $_POST['btninstadd'] )) {
		$newid = 1000001;
		while ( $newid == 1000001 || isset ( $instructors[$newid] )) {
			$newid = rand(1000001,9999999);
		}
		
		$instructors[$newid]['id'] = $newid;
		$instructors[$newid]['name'] = $_POST['name'];
		$instructors[$newid]['email'] = $_POST['email'];
		updateInstructors($instructorfile, $instructors);
	}
	
	//classes
	
	if ( !file_exists($classfile)) {
		print '<h1>Error reading map</h1>';
		print '</body></html>';
		exit();
	}

	$fd = fopen($classfile, "r");
	while (( $row = fgetcsv($fd, 1024)) != FALSE) {
		$classes[] = array( 'instructor' => $row[0], 'class_id' => $row[1] );
	}
	fclose($fd);
	
	if ( isset ( $_POST['btnclassdelete'] )) {
		foreach ($classes as $key=>$class) {
			if ( $class['instructor'] == $_POST['classinstructor'] && 
				$class['class_id'] == $_POST['classid'] ) {
				unset($classes[$key]);
			}
		}
		updateClasses($classfile, $classes);
	}
	
	if ( isset ( $_POST['btnclassadd'] )) {
		$classes[] = array( 'instructor' => $_POST['instructor'], 'class_id' => $_POST['classid'] );
		updateClasses($classfile, $classes);
	}
	
	if ( isset ( $_POST['btnemail'] )) {
		$mail = new PHPMailer();
		$mail->IsSMTP();
		$mail->SMTPAuth   = true;                  // enable SMTP authentication
		$mail->SMTPSecure = "ssl";                 // sets the prefix to the servier
		$mail->Host       = "smtp.gmail.com";      // sets GMAIL as the SMTP server
		$mail->Port       = 465;                   // set the SMTP port

		$mail->Username   = "<user>";  // GMAIL username
		$mail->Password   = "<pass>";            // GMAIL password

		$mail->From       = "<email>";
		$mail->FromName   = "<fullname>";
		$mail->Subject    = "<subject>";
		$mail->AddAddress($instructors[$_POST['instructor']]['email'],$instructors[$_POST['instructor']]['name']);
		$mail->AddCC("<list_email>", "<list_name>");
		$mail->IsHTML(false);
		$body = "{$instructors[$_POST['instructor']]['name']}, <email_body>{$_POST['instructor']}\n" .
			"If you have any questions about the list tool please reply-all to this email.";
		$mail->Body       = $body;
		
		if(!$mail->Send()) {
			echo "Mailer Error: " . $mail->ErrorInfo;
		}
	}
	
	if ( isset ( $_POST['btnupdateevents'] )) {
		updateEvents();
	}
	
	if ( $_SERVER['REQUEST_METHOD'] == 'POST' ) {
		header( "Location: {$_SERVER['PHP_SELF']}?instructor_id={$_REQUEST['instructor_id']}" );
	}

	foreach ( $instructors as $instructor ) {
		$selections .= "<option value='{$instructor['id']}'>{$instructor['name']} &lt;{$instructor['email']}&gt;</option>\n";
	}
	
	$events = getEvents();
	
	foreach ( $events as $event ) {
		$classoptions .= "<option value='{$event['id']}'>{$event['title']}</option>\n";
	}
		
	print "<form action='{$_SERVER['PHP_SELF']}' method='POST'>" .
		"<input type='hidden' name='instructor_id' value='{$_REQUEST['instructor_id']}' />" .
		"New instructor name: <input type='text' name='name' />\n" .
		"email: <input type='text' name='email' />\n" .
		"<input type='submit' name='btninstadd' value='Add Instructor!' />\n</form><br />\n";
	
	print "<form action='{$_SERVER['PHP_SELF']}' method='POST'>" .
		"<input type='hidden' name='instructor_id' value='{$_REQUEST['instructor_id']}' />" .
		"<select name='instructor'>\n{$selections}</select>\n" .
		"<input type='submit' name='btnemail' value='Email Instructor Class List Link!' />" .
		"<input type='submit' name='btninstdelete' value='Delete Instructor!' />\n</form><br />\n";

	print "<form action='{$_SERVER['PHP_SELF']}' method='POST'>" .
		"<input type='hidden' name='instructor_id' value='{$_REQUEST['instructor_id']}' />" .
		"<select name='instructor'>\n{$selections}</select>\n" .
		"<select name='classid'>\n{$classoptions}</select>\n" .
		"<input type='submit' name='btnclassadd' value='Add Class!' />\n<br />" .
		"<input type='submit' name='btnupdateevents' value='Update Event List' /></form><br />\n";

	print "<table>";
	foreach ( $classes as $class ) {
		print "<tr><form action='{$_SERVER['PHP_SELF']}' method='POST'>" .
			"<td>{$instructors[$class['instructor']]['name']}</td><td>{$events[$class['class_id']]['title']}</td>" .
			"<input type='hidden' name='instructor_id' value='{$_REQUEST['instructor_id']}' />" .
			"<input type='hidden' name='classid' value='{$class['class_id']}' />\n" .
			"<input type='hidden' name='classinstructor' value='{$class['instructor']}' />\n" .
			"<td><input type='submit' name='btnclassdelete' value='Delete!' /></td>" .
			"</form></tr>\n";
	}
	print "</table>";
}

function compare_title($a, $b) {
	return strnatcmp($a['title'], $b['title']);
}

function compare_name($a, $b) {
	return strnatcmp($a['name'], $b['name']);
}

function updateInstructors ($instructorfile, $instructors) {
	uasort($instructors,'compare_name');
	$fd = fopen($instructorfile, "w");
	foreach ( $instructors as $instructor ) {
		fputcsv ($fd, $instructor);
	}
	fclose($fd);
}

function updateClasses ($classfile, $classes) {
	sort($classes);
	$fd = fopen($classfile, "w");
	foreach ( $classes as $class ) {
		fputcsv ($fd, $class);
	}
	fclose($fd);
}

function updateEvents () {
	$userkey = '<userkey>';
	$appkey = '<appkey>';
	$apiroot = 'https://www.eventbrite.com/xml';
	$organizer_id = '<ordid>';
	
	date_default_timezone_set('UTC');
	$mydate = date("Y-m-01 00:00:00", time());
	
	$req = "$apiroot/organizer_list_events?user_key=$userkey&app_key=$appkey&id=$organizer_id";
	$res = file_get_contents($req);
	$xml = new SimpleXMLElement($res);

	foreach ( $xml->event as $event ) {
		if ( ($event->start_date >= $mydate || $event->end_date >= $mydate ) &&
			( $event->status == 'Live' || $event->status == 'Started' ) ) {
			$events["{$event->id}"] = array ( 'title' => $event->title, 'id' => $event->id );
		}
	}
	
	uasort($events, 'compare_title'); ;
	$fd = fopen('eventlist.csv', "w");
	foreach ( $events as $event) {
		fputcsv ($fd, $event);
	}
	fclose($fd);
}

function getEvents () {
	if ( !file_exists('eventlist.csv')) {
		print '<h1>Error reading map</h1>';
		print '</body></html>';
		//exit();
	}

	$fd = fopen('eventlist.csv', "r");
	while (( $row = fgetcsv($fd, 1024)) != FALSE) {
		$events[$row[1]] = array( 'title' => $row[0], 'id' => $row[1] );
	}
	fclose($fd);
	
	return $events;
}

?>
