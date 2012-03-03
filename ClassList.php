<?php

include("phpmailer/class.phpmailer.php");
include("phpmailer/class.smtp.php");
include("config.php");

class ClassList {
	private $userkey = Config::evb_userkey;
	private $appkey = Config::evb_appkey;
	private $classfile = 'instructor_event_map.csv';
	private $instructorfile = 'instructor_list.csv';
	private $eventfile = 'eventlist.csv';
	private $apiroot = 'https://www.eventbrite.com/xml';
	private $tickets;
	private $instructors;
	
	public function main() {
		if ( empty ( $_REQUEST['instructor_id'] ) ) {
			$this->error('Malformed Request');
		}

		$instructor_id = $_REQUEST['instructor_id'];

		if ( $instructor_id == Config::admin_id ) {
			if ( $_SERVER['REQUEST_METHOD'] == 'GET' ) {
				$this->viewManagement();
			}
			else {
				$this->management();
			}
		}
		else {
			$this->viewClasses($instructor_id);
		}
	}
	
	private function viewClasses($instructor_id) {
		$this->tickets['0'] = 'Null Ticket';

		print "<html><head><title>Class List</title></head><body>\n";
		
		if ( !file_exists($this->classfile)) {
			print '<h1>Error reading class map</h1>';
			print '</body></html>';
			exit();
		}

		$fd = fopen($this->classfile, "r");
		while (( $row = fgetcsv($fd, 1024)) != FALSE) {
			if ( $row[0] == $instructor_id || $instructor_id == Config::view_all_id ) {
				$events[]=$row[1];
			}
		}
		fclose($fd);

		if ( empty ( $events ) ) {
			$fd = fopen($this->instructorfile, "r");
			while (( $row = fgetcsv($fd, 1024)) != FALSE) {
				if ( $row[0] == $instructor_id ) {
					$inst_name=$row[1];
				}
			}
			fclose($fd);
			if ( empty ( $inst_name ) ) {
				print '<h1>Malformed Request</h1>';
			}
			else {
				print "<h2>No classes entered for $inst_name</h2>";
			}
			print '</body></html>';
			exit();
		}

		foreach ( $events as $event_id ) {
			//grab the event name

			$req = "$this->apiroot/event_get?user_key=$this->userkey&app_key=$this->appkey&id=$event_id";
			$res = file_get_contents($req);

			$evxml = new SimpleXMLElement($res);

			//grab the attendees
	
			$req = "$this->apiroot/event_list_attendees?user_key=$this->userkey&app_key=$this->appkey&id=$event_id";
			$res = file_get_contents($req);
 
			$xml = new SimpleXMLElement($res);
	
			$emails["0"] = '';
			$list["0"] = '';
	
			//make the ticket type list
			foreach ( $evxml->tickets->ticket as $ticket ) {
				$this->tickets["{$ticket->id}"] = $ticket->name;
			}

			//add each attendee to the attendee list and email list
			foreach ( $xml->attendee as $attendee ) {
				if ( isset ( $attendee->event_date )) {
					$arrdate = $attendee->event_date;
				}
				else {
					$arrdate = 0;
				}
				if ( empty ( $emails["{$arrdate}"]["{$attendee->ticket_id}"] )) {
					$emails["{$arrdate}"]["{$attendee->ticket_id}"] = "{$attendee->email}";
				}
				else {
					$emails["{$arrdate}"]["{$attendee->ticket_id}"] .= ",{$attendee->email}";
				}
				$list["{$arrdate}"]["{$attendee->ticket_id}"] .= "{$attendee->first_name} {$attendee->last_name} ({$attendee->quantity}) <a href='mailto:{$attendee->email}'>{$attendee->email}</a><br />\n";
			}
	
			//print the class lists split by event date and ticket type
			foreach ( $emails as $arrdate => $email_tkt_type ) {
				if ( count ( $emails ) > 1 ) {
					print "<b>Class: <a href='http://www.eventbrite.com/event/{$evxml->id}'>{$evxml->title}</a> on {$arrdate}</b><br />\n";
				}
				else {
					print "<b>Class: <a href='http://www.eventbrite.com/event/{$evxml->id}'>{$evxml->title}</a></b><br />\n";
				}
				
				uksort ( $email_tkt_type, array($this, 'compare_tickets' ));
				foreach ( $email_tkt_type as $type => $email ) {
					print "<b><i>{$this->tickets[$type]}</b></i><br />\n";
					print "<a href='mailto:{$email}'>Email all ticket holders of this ticket type</a><br />\n";
					print $list[$arrdate][$type];
				}
		
				print "<br />\n";
			}
		}
		print "</body></html>";
	}
	
	private function viewManagement() {
		$this->loadInstructors();
		$classes = $this->loadClasses();
		$events = $this->loadEvents();
		
		print "<html><head><title>Class List Management</title></head><body>\n";

		foreach ( $this->instructors as $instructor ) {
			if($instructor['id'] != Config::view_all_id) {
				$selections .= "<option value='{$instructor['id']}'>{$instructor['name']} &lt;{$instructor['email']}&gt;</option>\n";
			}
		}
	
		foreach ( $events as $event ) {
			$classoptions .= "<option value='{$event['id']}'>{$event['title']}</option>\n";
		}
		
		print "<form action='{$_SERVER['PHP_SELF']}' method='POST'>" .
			"<input type='hidden' name='instructor_id' value='{$_REQUEST['instructor_id']}' />" .
			"New instructor name: <input type='text' name='name' />\n" .
			"email: <input type='text' name='email' />\n" .
			"<input type='submit' name='btninstadd' value='Add Instructor!' />\n<br /><br />" .
			"<input type='submit' name='btnemailall' value='Email All Instructors!' /></form><br />\n";

		print "<form action='{$_SERVER['PHP_SELF']}' method='POST'>" .
			"<input type='hidden' name='instructor_id' value='{$_REQUEST['instructor_id']}' />" .
			"<select name='instructor'>\n{$selections}</select>" .
			"<input type='submit' name='btnviewinst' value='View Instructor Page' />" .
			"<input type='submit' name='btnemail' value='Email Instructor Class List Link!' />" .
			"<input type='submit' name='btninstdelete' value='Delete Instructor!' />\n<br />" .
			"<input type='submit' name='btnclassadd' value='Add Class-Instructor Link' />\n<br />" .
			"<select name='classid'>\n{$classoptions}</select>" .
			"<input type='submit' name='btnviewclass' value='View Class Page' />" .
			"<input type='submit' name='btnupdateevents' value='Update Event List' />" .
			"</form>\n<br />";

		print "<table>";
		uasort ( $classes, array($this, 'compare_class_inst_name' ));
		foreach ( $classes as $class ) {
			print "<tr><form action='{$_SERVER['PHP_SELF']}' method='POST'>" .
				"<td>{$this->instructors[$class['instructor']]['name']}</td><td>{$events[$class['class_id']]['title']}</td>" .
				"<input type='hidden' name='instructor_id' value='{$_REQUEST['instructor_id']}' />" .
				"<input type='hidden' name='classid' value='{$class['class_id']}' />\n" .
				"<input type='hidden' name='classinstructor' value='{$class['instructor']}' />\n" .
				"<td><input type='submit' name='btnclassdelete' value='Delete!' /></td>" .
				"</form></tr>\n";
		}
	
		print "</table>\n";
		print "<br /><br />\n";
	
		foreach ( $classes as $class ) {
			unset ($events[$class['class_id']]);
		}
	
		print "<table>";
		print "<tr><td>Unmapped Classes:</td></tr>\n";
		foreach ( $events as $event ) {
			print "<tr><td>{$event['title']}</td></tr>\n";
		}
	
		print "</table>";
		print "</body></html>";
	}
	
	private function loadInstructors() {
		if ( !file_exists($this->instructorfile)) {
			$this->error('Error reading instructors');
		}

		$fd = fopen($this->instructorfile, "r");
		while (( $row = fgetcsv($fd, 1024)) != FALSE) {
			$this->instructors[$row[0]] = array( 'id' => $row[0], 'name' => $row[1], 'email' => $row[2]);
		}
		fclose($fd);
	}
	
	private function loadClasses() {
		if ( !file_exists($this->classfile)) {
			$this->error('Error reading class map');
		}

		$fd = fopen($this->classfile, "r");
		while (( $row = fgetcsv($fd, 1024)) != FALSE) {
			$classes[] = array( 'instructor' => $row[0], 'class_id' => $row[1] );
		}
		fclose($fd);
		return $classes;
	}
	
	private function loadEvents () {
		if ( !file_exists($this->eventfile)) {
			$this->error('Error reading map');
		}

		$fd = fopen($this->eventfile, "r");
		while (( $row = fgetcsv($fd, 1024)) != FALSE) {
			$events[$row[1]] = array( 'title' => $row[0], 'id' => $row[1] );
		}
		fclose($fd);
	
		return $events;
	}
	
	private function management() {
		if ( isset ( $_POST['btnviewinst'] )) {
			header( "Location: {$_SERVER['PHP_SELF']}?instructor_id={$_POST['instructor']}" );
			exit();
		}
	
		if ( isset ( $_POST['btnviewclass'] )) {
			header( "Location: http://www.eventbrite.com/event/{$_POST['classid']}" );
			exit();
		}

		//instructors		
		$this->loadInstructors();
	
		if ( isset ( $_POST['btninstdelete'] )) {
			unset($this->instructors[$_POST['instructor']]);
			$this->updateInstructors();
		}
	
		if ( isset ( $_POST['btninstadd'] )) {
			$newid = 1000001;
			while ( $newid == 1000001 || isset ( $this->instructors[$newid] )) {
				$newid = rand(1000001,9999999);
			}
		
			$this->instructors[$newid]['id'] = $newid;
			$this->instructors[$newid]['name'] = $_POST['name'];
			$this->instructors[$newid]['email'] = $_POST['email'];
			$this->updateInstructors();
		}

		//classes

		$classes = $this->loadClasses();

		if ( isset ( $_POST['btnclassdelete'] )) {
			foreach ($classes as $key=>$class) {
				if ( $class['instructor'] == $_POST['classinstructor'] && 
					$class['class_id'] == $_POST['classid'] ) {
					unset($classes[$key]);
				}
			}
			$this->updateClasses($classes);
		}
	
		if ( isset ( $_POST['btnclassadd'] )) {
			$classes[] = array( 'instructor' => $_POST['instructor'], 'class_id' => $_POST['classid'] );
			$this->updateClasses($classes);
		}
	
		if ( isset ( $_POST['btnemail'] )) {
			$this->classMailer($_POST['instructor'], 'first');
		}
	
		if ( isset ( $_POST['btnupdateevents'] )) {
			$this->updateEvents();
		}
	
		if ( isset ( $_POST['btnemailall'] )) {
			foreach ($this->instructors as $instructor) {
				foreach ($classes as $class) {
					if ($class['instructor'] == $instructor['id']) {
						$this->classMailer($instructor['id'], 'all');
						break;
					}
				}
			}
		}
	
		header( "Location: {$_SERVER['PHP_SELF']}?instructor_id={$_REQUEST['instructor_id']}" );
	}
	
	private function error($errmsg) {
		print '<html><body>';
		print "<h1>$errmsg</h1>";
		print '</body></html>';
		exit();	
	}

	private function compare_title($a, $b) {
		return strnatcmp($a['title'], $b['title']);
	}

	private function compare_class_inst_name($a, $b) {
		return $this->compare_name($this->instructors[$a['instructor']],$this->instructors[$b['instructor']]);
	}

	private function compare_name($a, $b) {
		return strnatcmp($a['name'], $b['name']);
	}

	private function compare_tickets($a, $b) {
		return strnatcmp($this->tickets[$a], $this->tickets[$b]);
	}

	private function updateInstructors () {
		if ( !file_exists($this->instructorfile)) {
			$this->error('Error reading instructors');
		}

		$fd = fopen($this->instructorfile, "w");
		uasort($this->instructors,array($this, 'compare_name'));
		foreach ( $this->instructors as $instructor ) {
			fputcsv ($fd, $instructor);
		}
		fclose($fd);
	}

	private function updateClasses ($classes) {
		sort($classes);
		$fd = fopen($this->classfile, "w");
		foreach ( $classes as $class ) {
			fputcsv ($fd, $class);
		}
		fclose($fd);
	}

	private function classMailer ($instructor, $type) {
		$mail = new PHPMailer();
		$mail->IsSMTP();
		$mail->SMTPAuth   = true;
		$mail->SMTPSecure = Config::email_server_type;
		$mail->Host       = Config::email_server;
		$mail->Port       = Config::email_port;

		$mail->Username   = Config::email_user;
		$mail->Password   = Config::email_pass;

		$mail->From       = Config::email_from;
		$mail->FromName   = Config::email_from_name;
		$mail->AddAddress($this->instructors[$instructor]['email'],$this->instructors[$instructor]['name']);
		if($type == "first") {
			$mail->AddCC(Config::email_cc, Config::email_cc_name);
			$mail->Subject = Config::email_subject_first;
			$mail->Body = Config::email_body_first;
		} elseif($type == "all") {
			$mail->Subject = Config::email_subject_all;
			$mail->Body = Config::email_body_all;
		}
		$mail->IsHTML(false);
		$mail->Body = str_replace("[INSTRUCTOR_NAME]", $this->instructors[$instructor]['name'], $mail->Body);
		$mail->Body = str_replace("[INSTRUCTOR_LINK]", "http://{$_SERVER['SERVER_NAME']}{$_SERVER['PHP_SELF']}?instructor_id=$instructor", $mail->Body);
	
		if(!$mail->Send()) {
			$this->error("Mailer Error: " . $mail->ErrorInfo);
		}
	}

	private function updateEvents () {
		$req = "$this->apiroot/user_list_events?user_key=$this->userkey&app_key=$this->appkey&do_not_display=venue%2Clogo%2Cstyle%2Ctickets%2Corganizer&event_statuses=live%2Cstarted%2Cended";
		$res = file_get_contents($req);
		$xml = new SimpleXMLElement($res);

		foreach ( $xml->event as $event ) {
			preg_match ('/Instructor Biography.*?ALT="([\w\s-]*?)"/s', $event->description, $groups);
			if ( $groups[1] == '' ) {
				preg_match ('/Instructor Biography.*?<P>(?:<SPAN CLASS="notranslate">)?(\w*\s*\w*)/s', $event->description, $groups);
			}
			if ( $groups[1] != '' ) {
				$groups[1] .= ': ';
			}
			$events["{$event->id}"] = array ( 'title' => $groups[1] . $event->title, 'id' => $event->id );
		}
	
		uasort($events, array($this, 'compare_title')); ;
		$fd = fopen($this->eventfile, "w");
		foreach ( $events as $event) {
			fputcsv ($fd, $event);
		}
		
		fclose($fd);
	}
}
$cl = new ClassList;
$cl->main();
?>
