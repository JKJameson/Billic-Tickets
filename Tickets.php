<?php
class Tickets {
	public $settings = array(
		'admin_menu_category' => 'Support',
		'admin_menu_name' => 'Tickets',
		'description' => 'Provide support to your users.',
		'admin_menu_icon' => '<i class="icon-ticket"></i>',
		'permissions' => array(
			'Tickets_New',
			'Tickets_Close',
			'Tickets_Delete'
		) ,
		'user_menu_name' => 'My Tickets',
		'user_menu_icon' => '<i class="icon-ticket"></i>',
		'allowed_tags' => '<p><a><strong><u><blockquote><ul><ol><li><h2><h3><s><em><img><br>',
	);
	function show_messages($ticket, $area) { // $area = admin OR client
		global $db, $billic;
		$show_santa_hat = false;
		if (date('n') == '12' && date('j') > 7 && get_config('Tickets_christmas') == 1) {
			$show_santa_hat = true;
		}
		// get all ticket replies
		$messages = $db->q('SELECT * FROM `ticketmessages` WHERE `tid` = ? ORDER BY `date` ASC', $_GET['ID']);
		// mark as read
		if ($ticket[$area . 'unread'] == 1) {
			$db->q('UPDATE `tickets` SET `' . $area . 'unread` = \'0\' WHERE `id` = ?', $_GET['ID']);
		}
		foreach ($messages as $message) {
			if ($message['userid'] == $billic->user['id']) {
				$user_row = $billic->user;
			} else {
				$user_row = $db->q('SELECT `id`, `firstname`, `lastname`, `email`, `permissions` FROM `users` WHERE `id` = ?', $message['userid']);
				$user_row = $user_row[0];
			}
			$name_line = '';
			$email = '';
			echo '<table class="table table-striped"><tr><td rowspan="2" width="15%" class="ticketview-left">';
			if ($message['userid'] != 0) {
				if ($area == 'admin' && $user_row['id'] == $ticket['userid']) {
					$name_line.= '<a href="/Admin/Users/ID/' . $message['userid'] . '/">';
				}
				$name_line.= safe(wordwrap($user_row['firstname'] . ' ' . $user_row['lastname'], 16, PHP_EOL, true)) . '<br>';
				if ($area == 'admin' && $user_row['id'] == $ticket['userid']) {
					$name_line.= '</a>';
				}
				$email = $user_row['email'];
			} else if (!empty($message['name'])) {
				$name_line.= safe(wordwrap($message['name'], 16, PHP_EOL, true)) . '<br>';
			}
			if (!empty($message['email'])) {
				$name_line.= '<sup>';
				if (strlen($message['email']) <= 16) {
					$name_line.= safe($message['email']);
				} else {
					$name_line.= '<span title="' . safe($message['email']) . '">' . safe(substr($message['email'], 0, 16)) . '...</span>';
				}
				$name_line.= '</sup>';
				$email = $message['email'];
			}
			if (!empty($email)) {
				// START Santa Hat
				if ($show_santa_hat) {
					echo '<div style="position:relative;width:100px;height:100px;margin-left:auto;margin-right:auto;margin-top: 40px"><img src="/Modules/Tickets/santa-hat.png" style="position:absolute;bottom:70px;right:16px">';
				}
				echo '<img src="' . $billic->avatar($email, 100) . '" width="100" height="100" class="img-circle">';
				// STOP Santa Hat
				if ($show_santa_hat) {
					echo '</div>';
				}
			}
			echo '<br>' . $name_line;
			if ($billic->user_has_permission($user_row, 'admin')) {
				echo '<span class="label label-primary">Staff</span>';
			} else {
				echo '<span class="label label-default">User</span>';
			}
			echo '</td><td class="ticketview-top">' . $billic->time_ago($message['date']) . ' ago<span class="pull-right">' . date(DATE_RFC2822, $message['date']) . '</span></th></tr>';
			echo '<tr><td class="ticketview-bottom">';
			if (!empty($message['attachments'])) {
				$attachments = explode('|', $message['attachments']);
				echo '<div style="float:right;text-align:center">';
				foreach ($attachments as $attachment) {
					echo '<a href="/User/Tickets/Download/' . urlencode($attachment) . '/" target="_blank"><img src="/User/Tickets/Download/' . urlencode($attachment) . '/Thumbnail/120/"><br>' . urlencode($attachment) . '</a><br>';
				}
				echo '</div>';
			}
			$msg = $message['message'];
			if ($message['date'] < 1434415726) {
				$msg = htmlentities($msg, ENT_QUOTES, 'UTF-8');
				$msg = nl2br($msg);
			}
			// if there is no html then convert new lines to line breaks
			if (stripos($msg, '<br>') === false) {
				$msg = str_replace(PHP_EOL, '<br>', $msg);
			}
			//$msg = preg_replace('#http(s?)://(.*?)([\s<])#', '<a href="http$1://$2" target="_blank">http$1://$2</a>$3', $msg);
			echo $msg;
			echo '</td></tr></table>';
		}
	}
	function check_reply($ticket) {
		global $billic, $db;
		$billic->disable_content();
		$drafts = $db->q('SELECT `userid`, `timestamp` FROM `tickets_draft` WHERE `ticketid` = ? AND `userid` != ?', $ticket['id'], $billic->user['id']);
		$num = count($drafts);
		if ($num == 1) {
			$message = 'The user ';
		} else if ($num > 1) {
			$message = 'The following users were replying to this ticket;<br>';
		}
		foreach ($drafts as $draft) {
			$user = $db->q('SELECT `firstname`, `lastname` FROM `users` WHERE `id` = ?', $draft['userid']);
			$user = $user[0];
			if (empty($user)) {
				continue;
			}
			$message.= $user['firstname'] . ' ' . $user['lastname'] . ' ';
			if ($num == 1) {
				$message.= ' was writing a reply to this ticket at ' . date('jS M Y H:i', $draft['timestamp']);
			} else {
				$message.= ' at ' . date('jS M Y H:i', $draft['timestamp']) . '<br>';
			}
		}
		echo json_encode(array(
			'count' => count($drafts) ,
			'message' => $message,
		));
		exit;
	}
	function merge_tickets($ids) {
		global $billic, $db;
		if (!is_array($ids) || count($ids)<2) {
			$billic->errors[] = 'At least two tickets must be selected to merge';
			return;	
		}
		$tickets = [];
		$ticketOwner = false;
		ksort($ids);
		foreach($ids as $id => $val) {
			$ticket = $db->q('SELECT `id`, `userid` FROM `tickets` WHERE `id` = ?', $id)[0];
			if (empty($ticket))
				$billic->errors[] = 'An invalid ticket was selected';
			if ($ticketOwner===false)
				$ticketOwner = $ticket['userid'];
			if ($ticketOwner!=$ticket['userid'])
				$billic->errors[] = 'The tickets to merge must belong to the same account';
			$tickets[] = $ticket;
		}
		if (empty($billic->errors)) {
			// Merge with the first ticket
			$mergeTo = $tickets[0];
			unset($tickets[0]);
			foreach($tickets as $ticket) {
				//echo 'Merging ticket #'.$ticket['id'].' to '.$mergeTo['id'].'<br>';
				$db->q('UPDATE `ticketmessages` SET `tid` = ? WHERE `tid` = ?', $mergeTo['id'], $ticket['id']);
				$db->q('DELETE FROM `tickets` WHERE `id` = ?', $ticket['id']);
			}
		}
	}
	function admin_area() {
		global $billic, $db;
		if (isset($_GET['ID'])) {
			$ticket = $db->q('SELECT * FROM `tickets` WHERE `id` = ?', $_GET['ID']);
			$ticket = $ticket[0];
			if (empty($ticket)) {
				err('Ticket ' . $_GET['ID'] . ' does not exist');
			}
			if ($_GET['Action'] == 'SaveDraft') {
				$billic->disable_content();
				$this->save_draft($ticket['id'], $_POST['message']);
				exit;
			}
			if ($_GET['Action'] == 'CheckReply') {
				$this->check_reply($ticket);
			}
			if (empty($ticket['replypassword'])) {
				$ticket['replypassword'] = $billic->rand_str(30);
				$db->q('UPDATE `tickets` SET `replypassword` = ? WHERE `id` = ?', $ticket['replypassword'], $ticket['id']);
			}
			if ($_GET['Action'] == 'Close') {
				if (!$billic->user_has_permission($billic->user, 'Tickets_Close')) {
					err('You do not have permission to close this ticket');
				}
				$db->q('UPDATE `tickets` SET `status` = \'Closed\' WHERE `id` = ?', $ticket['id']);
				$user_row = $db->q('SELECT `firstname`, `lastname`, `email` FROM `users` WHERE `id` = ?', $ticket['userid']);
				$user_row = $user_row[0];
				if (!empty($user_row['email'])) {
					$billic->email($user_row['email'], 'Ticket #' . $ticket['id'] . ' Closed - ' . safe($ticket['title']) , 'Dear ' . $user_row['firstname'] . ' ' . $user_row['lastname'] . ',<br>This ticket has been closed. Please reply to the ticket if you have anything further to discuss.<br><br><hr><br><a href="http' . (get_config('billic_ssl') == 1 ? 's' : '') . '://' . get_config('billic_domain') . '/User/Tickets/ID/' . $ticket['id'] . '/">http' . (get_config('billic_ssl') == 1 ? 's' : '') . '://' . get_config('billic_domain') . '/User/Tickets/ID/' . $ticket['id'] . '/</a><br>' . $ticket['replypassword']);
					$billic->redirect('/Admin/Tickets/');
				}
			}
			if ($_GET['Action'] == 'Delete') {
				if (!$billic->user_has_permission($billic->user, 'Tickets_Delete')) {
					err('You do not have permission to delete this ticket');
				}
				foreach($db->q('SELECT `attachments` FROM `ticketmessages` WHERE `tid` = ?', $ticket['id']) as $message) {
					$attachments = explode('|', $message['attachments']);
					foreach ($attachments as $attachment) {
						if (empty($attachment))
							continue;
						@unlink('../attachments/' . $attachment);
						if (file_exists('../attachments/' . $attachment))
							err('Failed to delete ' . $attachment);
					}
				}
				$db->q('DELETE FROM `ticketmessages` WHERE `tid` = ?', $ticket['id']);
				$db->q('DELETE FROM `tickets` WHERE `id` = ?', $ticket['id']);
				$billic->redirect('/Admin/Tickets/');
			}
			if (isset($_GET['Verify']) && $billic->user_has_permission($billic->user, 'Users_Verify')) {
				if ($_GET['Verify'] == 0 || $_GET['Verify'] == 1) {
					$db->q('UPDATE `users` SET `verified` = ? WHERE `id` = ?', $_GET['Verify'], $ticket['userid']);
					$attachments = explode('|', $ticket['attachments']);
					foreach ($attachments as $attachment) {
						if (empty($attachment)) {
							continue;
						}
						@unlink('../attachments/' . $attachment);
						if (file_exists('../attachments/' . $attachment)) {
							err('Failed to delete ' . $attachment);
						}
					}
					$replies = $db->q('SELECT * FROM `ticketmessages` WHERE `tid` = ?', $ticket['id']);
					foreach ($replies as $reply) {
						$attachments = explode('|', $ticket['attachments']);
						foreach ($attachments as $attachment) {
							if (empty($attachment)) {
								continue;
							}
							@unlink('../attachments/' . $attachment);
							if (file_exists('../attachments/' . $attachment)) {
								err('Failed to delete ' . $attachment);
							}
						}
						$db->q('UPDATE `ticketmessages` SET `attachments` = \'\' WHERE `id` = ?', $reply['id']);
					}
				}
			}
			echo '<div style="float:right"><a href="/Admin/Tickets/ID/' . $ticket['id'] . '/Action/Delete/" class="btn btn-danger" role="button" onClick="return confirm(\'Are you sure you want to delete this support ticket?\')">Delete</a> <a href="/Admin/Tickets/ID/' . $ticket['id'] . '/Action/Close/" class="btn btn-danger" role="button">Close</a></div>';
			echo '<h1>Support Ticket #' . $_GET['ID'] . ' - ' . safe($ticket['title']) . '</h1>';
			if (!empty($ticket['userid'])) {
				$user_row = $db->q('SELECT `firstname`, `lastname` FROM `users` WHERE `id` = ?', $ticket['userid']);
				$user_row = $user_row[0];
				if (empty($user_row)) {
					$billic->errors[] = 'This ticket is owned by User ID ' . $ticket['userid'] . ' but the user does not exist';
				} else {
					//echo 'User: <a href="/Admin/Users/ID/'. $ticket['userid'].'/">'.$user_row['firstname'].' '.$user_row['lastname'].'</a><br>';
					
				}
			}
			if (!empty($ticket['serviceid'])) {
				$service = $db->q('SELECT * FROM `services` WHERE `id` = ? AND `userid` = ?', $ticket['serviceid'], $ticket['userid']);
				$service = $service[0];
			}
			echo '<hr>
<table class="table table-striped ticketdetails">
  <tr>
	  <th>Priority</th>
	  <th>Service</th>
	  <th>Last Reply</th>
	  <th>Date Created</th>
	  <th>Assigned To</th>
	  <th>Department</th>
	  <th>Status</th>
  </tr>
  <tr>
	  <td>' . $this->priority($ticket) . '</td>
	  <td>';
			if (empty($service)) {
				echo 'None';
			} else {
				echo '<a href="/Admin/Services/ID/' . $service['id'] . '/">#' . $service['id'] . '</a><br>';
			}
			echo '</td>
	  <td>' . $billic->time_ago($ticket['lastreply']) . ' ago</td>
	  <td>' . $billic->time_ago($ticket['date']) . ' ago</td>
	  <td>';
			if (empty($ticket['assignedto'])) {
				echo 'Nobody';
			}
			echo '</td>
	  <td>' . $ticket['queue'] . '</td>
	  <td>' . $this->status_label($ticket['status']) . '</td>
  </tr>
</table>';
			if (isset($_POST['message'])) {
				$message = strip_tags($_POST['message'], $this->settings['allowed_tags']);
				$message = preg_replace('/<([a-z]+)( href\="([a-z0-9\/\:\.\-\_\+\=]+)")?[^>]*>/ims', '<$1$2>', $message);
				if (empty($message)) {
					$billic->errors[] = 'Please enter a message';
				}
				$last_reply = $db->q('SELECT MD5(`message`), LENGTH(`message`) FROM `ticketmessages` WHERE `tid` = ? ORDER BY `id` DESC LIMIT 1', $ticket['id']);
				if (strlen($message) == $last_reply[0]['LENGTH(`message`)'] && md5($message) == $last_reply[0]['MD5(`message`)']) {
					$billic->errors[] = 'You refreshed the page and tried to reply with the same message';
				}
				$attachments = '';
				if (empty($billic->errors) && !empty($_FILES['files'])) {
					foreach ($_FILES['files']['tmp_name'] as $key => $tmp_name) {
						if ($_FILES['files']['error'][$key] != UPLOAD_ERR_OK) {
							if ($_FILES['files']['error'][$key] != UPLOAD_ERR_NO_FILE) {
								$billic->errors[] = 'There was an error while uploading the file: ' . $_FILES['files']['name'][$key];
							}
						} else {
							$filename = basename($_FILES['files']['name'][$key]);
							$filename = str_replace(' ', '_', $filename);
							$safe_chars = 'a b c d e f g h i j k l m n o p q r s t u v w x y z 0 1 2 3 4 5 6 7 8 9 _ .';
							$safe_chars = explode(' ', $safe_chars);
							$safe_name = '';
							for ($i = 0;$i < strlen($filename);$i++) {
								if (!in_array(strtolower($filename[$i]) , $safe_chars)) {
									continue;
								}
								$safe_name.= $filename[$i];
							}
							$filename = $safe_name;
							$filename = preg_replace('/([\.]+)/', '.', $filename);
							$filename = preg_replace('/([_]+)/', '_', $filename);
							$filename = time() . '_' . basename($filename);
							move_uploaded_file($tmp_name, '../attachments/' . $filename);
							$attachments.= $filename . '|';
						}
					}
				}
				$attachments = substr($attachments, 0, -1);
				if (empty($billic->errors)) {
					$now = time();
					$db->insert('ticketmessages', array(
						'tid' => $_GET['ID'],
						'userid' => $billic->user['id'],
						'date' => $now,
						'message' => $message,
						'attachments' => $attachments,
					));
					$db->q('UPDATE `tickets` SET `lastreply` = ?, `status` = ?, `clientunread` = \'1\' WHERE `id` = ?', $now, $_POST['status'], $_GET['ID']);
					$db->q('DELETE FROM `tickets_draft` WHERE `ticketid` = ? AND `userid` = ?', $ticket['id'], $billic->user['id']);
					$user_row = $db->q('SELECT `firstname`, `lastname`, `email` FROM `users` WHERE `id` = ?', $ticket['userid']);
					$user_row = $user_row[0];
					$billic->email($user_row['email'], 'Ticket #' . $ticket['id'] . ' - ' . safe($ticket['title']) , 'Dear ' . $user_row['firstname'] . ' ' . $user_row['lastname'] . ',<br>A response has been made to your ticket.<br><br>' . $message . '<br><br><hr><br><a href="http' . (get_config('billic_ssl') == 1 ? 's' : '') . '://' . get_config('billic_domain') . '/User/Tickets/ID/' . $ticket['id'] . '/">http' . (get_config('billic_ssl') == 1 ? 's' : '') . '://' . get_config('billic_domain') . '/User/Tickets/ID/' . $ticket['id'] . '/</a><br>' . $ticket['replypassword']);
					$billic->redirect('/Admin/Tickets/');
				}
			}
			$billic->show_errors();
			$this->show_messages($ticket, 'admin');
			$this->reply_box('admin');
			return;
		}
		if (isset($_POST['merge']))
			$this->merge_tickets($_POST['ids']);
		$billic->module('ListManager');
		$billic->modules['ListManager']->configure(array(
			'search' => array(
				'id' => 'text',
				'subject' => 'text',
				'status' => array(
					'(All)',
					'Answered',
					'Awaiting Reply',
					'Closed',
					'Customer-Reply',
					'In Progress',
					'Open'
				) ,
				'queue' => 'text',
			) ,
		));
		if (empty($_POST['status'])) {
			$_POST['search'] = 1;
			$_POST['status'] = 'Awaiting Reply';
		}
		$where = '';
		$where_values = array();
		if (isset($_POST['search'])) {
			if (!empty($_POST['id'])) {
				$where.= '`id` = ? AND ';
				$where_values[] = $_POST['id'];
			}
			if (!empty($_POST['username'])) {
				$where.= '`username` LIKE ? AND ';
				$where_values[] = '%' . $_POST['username'] . '%';
			}
			if (!empty($_POST['desc'])) {
				$where.= '`domain` LIKE ? AND ';
				$where_values[] = '%' . $_POST['desc'] . '%';
			}
			if (!empty($_POST['plan'])) {
				$where.= '`plan` LIKE ? AND ';
				$where_values[] = '%' . $_POST['plan'] . '%';
			}
			if (!empty($_POST['price'])) {
				$where.= '`amount` = ? AND ';
				$where_values[] = $_POST['price'];
			}
			if ($_POST['status'] == 'Awaiting Reply') {
				$where.= '`status` != \'Closed\' AND `status` != \'Answered\' AND ';
			} else if (!empty($_POST['status']) && $_POST['status'] != '(All)') {
				$where.= '`status` LIKE ? AND ';
				$where_values[] = '%' . $_POST['status'] . '%';
			}
		}
		$where = substr($where, 0, -4);
		$func_array_select1 = array();
		$func_array_select1[] = '`tickets`' . (empty($where) ? '' : ' WHERE ' . $where);
		foreach ($where_values as $v) {
			$func_array_select1[] = $v;
		}
		$func_array_select2 = $func_array_select1;
		$func_array_select1[0] = 'SELECT COUNT(*) FROM ' . $func_array_select1[0];
		$total = call_user_func_array(array(
			$db,
			'q'
		) , $func_array_select1);
		$total = $total[0]['COUNT(*)'];
		$pagination = $billic->pagination(array(
			'total' => $total,
		));
		echo $pagination['menu'];
		$func_array_select2[0] = 'SELECT * FROM ' . $func_array_select2[0] . ' ORDER BY `lastreply` DESC LIMIT ' . $pagination['start'] . ',' . $pagination['limit'];
		$tickets = call_user_func_array(array(
			$db,
			'q'
		) , $func_array_select2);
		$billic->set_title('Admin/Tickets');
		echo '<h1><i class="icon-ticket"></i> Support Tickets</h1>';
		$billic->show_errors();
		echo $billic->modules['ListManager']->search_box();
		echo '<div style="float: right;padding-right: 40px;">Showing ' . $pagination['start_text'] . ' to ' . $pagination['end_text'] . ' of ' . $total . '</div>' . $billic->modules['ListManager']->search_link();
		if (empty($tickets)) {
			echo '<p>No Support Tickets matching filter.</p>';
		} else {
			echo '<form method="POST">';
			echo 'With Selected: <button type="submit" class="btn btn-xs btn-primary" name="merge"><i class="icon-resize-down"></i> Merge</button>';
			echo '<table class="table table-striped"><tr><th><input type="checkbox" onclick="checkAll(this, \'ids\')" data-enpassusermodified="yes"></th><th>Subject</th><th>Queue</th><th>Priority</th><th>Status</th><th>Client</th><th>Time</th></tr>';
			foreach ($tickets as $ticket) {
				$user_row = $db->q('SELECT `firstname`, `lastname`, `companyname` FROM `users` WHERE `id` = ?', $ticket['userid']);
				$user_row = $user_row[0];
				if (empty($user_row)) {
					$client = safe(wordwrap($ticket['email'], 25, PHP_EOL, true));
				} else {
					$client = '<a href="/Admin/Users/ID/' . $ticket['userid'] . '/">' . safe(wordwrap($user_row['firstname'] . ' ' . $user_row['lastname'], 25, PHP_EOL, true)) . '</a>';
					$num_tickets_user = $db->q('SELECT COUNT(*) FROM `tickets` WHERE `status` != \'Closed\' AND `status` != \'Answered\' AND `userid` = ?', $ticket['userid']);
					$num_tickets_user = $num_tickets_user[0]['COUNT(*)'];
					if ($num_tickets_user > 1) {
						$client.= ' <span class="badge badge-secondary" title="Tickets of this user awaiting reply">' . $num_tickets_user . '</span>';
					}
					if (!empty($user_row['companyname']))
						$client .= '<br>' . safe($user_row['companyname']);
				}
				$time = $billic->time_ago($ticket['lastreply']);
				if ($ticket['lastreply']!==$ticket['date'])
					$time .= '<br>Created: '.$billic->time_ago($ticket['date']);
				$time = str_replace(' ', '&nbsp;', $time);
				echo '<tr><td><input type="checkbox" name="ids['.$ticket['id'].']"></td><td><a href="/Admin/Tickets/ID/' . $ticket['id'] . '/">' . ($ticket['adminunread'] == 1 ? '<b>' : '') . htmlentities($ticket['title'], ENT_QUOTES, 'UTF-8') . ($ticket['adminunread'] == 1 ? '</b>' : '') . '</a></td><td>' . $ticket['queue'] . '</td><td>' . $this->priority($ticket) . '</td><td>' . $this->status_label($ticket['status']) . '</td><td>' . $client . '</td><td>'.$time.'</td></tr>';
			}
			echo '</table>';
			echo '</form>';
		}
	}
	function status_label($status) {
		switch ($status) {
			case 'Open':
			case 'Customer-Reply':
				return '<span class="label label-primary">Awaiting Reply</span>';
			break;
			case 'In Progress':
				return '<span class="label label-success">In Progress</span>';
			break;
			case 'Answered':
				return '<span class="label label-dark">Answered</span>';
			break;
			case 'Closed':
				return '<span class="label label-light">Closed</span>';
			break;
			default:
				return '<span class="label label-warning">' . $status . '</span>';
			break;
		}
	}
	function priority_label($priority) {
		if (empty($priority)) $priority = 'Normal';
		switch ($priority) {
			case 'Low':
				return '<span class="label label-dark">'.$priority.'</span>';
			break;
			case 'Normal':
				return '<span class="label label-light">'.$priority.'</span>';
			break;
			case 'High':
				return '<span class="label label-danger">'.$priority.'</span>';
			break;
			default:
				return '<span class="label label-primary">' . $priority . '</span>';
			break;
		}
	}
	function priority($ticket) {
		global $billic, $db;
		return $this->priority_label($ticket['priority']);
	}
	function save_draft($ticketid, $message) {
		global $billic, $db;
		if (empty(trim($message))) {
			echo json_encode(array(
				'status' => 'OK'
			));
			exit;
		}
		$db->q('UPDATE `tickets_draft` SET `message` = ?, `timestamp` = ? WHERE `ticketid` = ? AND `userid` = ?', $message, time() , $ticketid, $billic->user['id']);
		if ($db->affected_rows == 0) {
			$db->insert('tickets_draft', array(
				'ticketid' => $ticketid,
				'userid' => $billic->user['id'],
				'message' => $message,
				'timestamp' => time() ,
			));
		}
		echo json_encode(array(
			'status' => 'OK'
		));
		exit;
	}
	function user_area() {
		global $billic, $db;
		$billic->force_login();
		if (isset($_GET['Download'])) {
			$attachment = basename(urldecode($_GET['Download']));
			if (empty($attachment)) {
				err('no attachment specified');
			}
			$billic->disable_content();
			$src = '../attachments/' . $attachment;
			preg_match('/\.[^\.]+$/i', $src, $ext);
			$ext = strtolower($ext[0]);
			if (isset($_GET['Thumbnail'])) {
				if ($_GET['Thumbnail'] < 0 || $_GET['Thumbnail'] > 300) {
					err('too big');
				}
				if (!file_exists($src)) {
					header('Content-Type: image/png');
					echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAQAAAAAYLlVAAAABGdBTUEAALGPC/xhBQAAACBjSFJNAAB6JgAAgIQAAPoAAACA6AAAdTAAAOpgAAA6mAAAF3CculE8AAAAAmJLR0QAAKqNIzIAAAAJcEhZcwAADdcAAA3XAUIom3gAAAAHdElNRQfiAQQWLzue5qBFAAAD2ElEQVRo3u3Z3YtUdRzH8dfsbitoTyYJWgabCz7kjV0I3QwJZmVXmSV5EdY/kK4LeREoPlysQa2rQksZCUJaYF0kebFua2gSqd0IQWgZGPvAujuzOQ+ms78u9jjOzu7O7iwr48V8fjCH7+/MfHmf7+f8vr85HKqqqqoOoYKjIybIuV6hi39aLcG1ilX/mlAzwylrbXLGz95RB2I26nLeZg9F8VtO+8W7UWymK7BekHNHsAmsy8eb8/GwO4L3RipwD+CUjMP4QVbGl/guOpaj04JVlrnjHDiZj8/n47hG/7k4AlCX/2m/hIHoGAzgRjRTjm7hUcTcKhEPqYniGbfg1fwCex2szscbi+K3iy2YKa3xre+9IhbFq51w0rp8/KJvnPKa2P0CKEfXhJjAPT9mXDkf+gQ7fGDskp9FHYYl7iPATXBTYhyAJ9VU3IKZ7oRlqwpQBagCTA7wuKMu+9wjRfONWnTo1atDi8YSGR72mcuOemL806UbUb1fo92rvWA2ZouUIEjlj+/nt5vRqtUZZTg+5twUNqM2wU+ecdufBbNbBBnNGkCDZhnBlnEz7BJc8pShcf5bTAqwXtBnIbJ6CoqfkrFSXKc+fTrFrZSRGseINXKSGtEjWy7AsxKGvUwRQIugWVxOkJYW5MQ1C1qKMizQK3iT6QDUuyDYG0WFAB2CBp2CHerV2yHo1CDoGJWh1o+CQ1FUNkCb4IzZvrCrCKBXCn3S6iPUtD6k9I5x/6LZDvm4fIA3IvcPCnqKABIS+c+JZnhJTtJiewTZcgFG3F9rgzBNgLvur5UrDVBnrOp97TF7XXXR8ASru7RqfWW+Q875TY3h0l8eW4EDkfsXBHuiay+vArsj97sEB6Nrn7IFGwrc71I7DYBC9y+YVR7AYgk5a70QNdhBQTeyhv1tt7mTAizUK9jguahPDBqWQY/gun3mlwY4LtiD5/1lMBrtOCYhK9hpsmXYLjiIpf7IZziGdoMyggOlAbrlzJngblkluGTkAXSkEW1Xp872fCM6jd8FCybIsFTOP6UBTgiSukeNNhzR49+oOvsE26JWnJSMWvE2wT4cFgzpGTWOoE23IcGnpQHmOWFQtmCM3AMZQa+PzMMSaWkrxHVJSuoSt0Ja2hLMcdSNogwZdAv6tY2qzpSeDfsNWe62KwVzWwUpTRaBRZqkBFsnyHDFbcsN6Z/KKhirVkEuuv3uKqZJRhAMGBAEGU0TtqydUYbW6QHM1+qqFnOL5pfZ76ykpLP2W1Yiw1wtrmodtQDLALifqj4bVgGqAFWABwHgAXhhUeFXNpV2oKqqKq//Ac5kzQPdGhQiAAAAJXRFWHRkYXRlOmNyZWF0ZQAyMDE4LTAxLTA0VDIyOjQ3OjU5KzAxOjAwdhqA3wAAACV0RVh0ZGF0ZTptb2RpZnkAMjAxOC0wMS0wNFQyMjo0Nzo1OSswMTowMAdHOGMAAAAZdEVYdFNvZnR3YXJlAHd3dy5pbmtzY2FwZS5vcmeb7jwaAAAAAElFTkSuQmCC');
				}
				$source_image = false;
				if ($ext == '.pdf') {
					header('Content-Type: image/png');
					echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAMAAACdt4HsAAAAA3NCSVQICAjb4U/gAAAACXBIWXMAAAG7AAABuwE67OPiAAAAGXRFWHRTb2Z0d2FyZQB3d3cuaW5rc2NhcGUub3Jnm+48GgAAASlQTFRF////4uLr8lVE4uXmwcbL8VZC4ubn4uXnsLe9tLvAtbzBwcfMytHYz9Xb0c7S1tvf3ODj3eDj4uXn5oh95sPA7nRl8VZC8VdD8VlF8VpG8VtH8l1J8l1K8l9M8mBN8mFO8mFP8mJP8mNQ8mVT8mZU8mdV82xa83Fg9HZm9Hdn9Hts9H5u9IBx9YJ09YR19YV39YZ39Yh69Yt99Yx+9pWI956T96CU96CV+Kec+K2j+K6l+K+l+LCn+bGo+biw+buz+r62+sK7+sO8+sW/+sa/+8/J+8/K+9HM/NbR/NfS/NnU/NrV/NvW/N7a/N/b/ODc/eTh/ebj/ebk/ejl/ejm/ero/ezq/vTy/vX0/vb1/vf2/vj4//r5//r6//v7//z8//39//7+/////YM9wgAAAAh0Uk5TABo8psDJ5upYXiAEAAABv0lEQVRYw+XX2VLCMBQGYLBaUEA0aBC3qqBIVcR9wX1XEBRBUfbz/g9h6AIotklarvC/6KSZyTfJmTSdOBydcQoiMsmggxKnC5nGTRMERAFogkgFKAKiA+YCC2AqMAFmAhtgIjACxgIrYCgwA0YCO2Ag0IARmkADPG6KQAPGhykCogoe81Ughvi97VgCOgVrQIdgEWgLVoGWYBnQBeuAJtgAVMEOoAi2gKZgDyCCTQD57QKod8DAEObI5EQXwDWeCF0A5kxfA2FZlmMzSnNakqSQ1h2cJy/SLB1YqQJJ43IJ40SZtGrZs0XSPfUCSv8xFUhAOZvNA9xhfAJfhcIHwPsqxhFlPFT2GYAseW7UIUaAc9KMpiATJECFsQYqgO9hVwNwpAE7/MArbOsAfoAkAWoHJGssQE6W4zdQWW4BSbjSa1APMwBqufdwC3iGIwI0rkkOWWZQSqcfL5pz1YAowBZ/DbAOhNZzkMIcwCZk2kC1WCT76i3KAyzkk3oz/kmKUXo6nWvu5PTtP/qcrQKch2rA5rEeGDX8sXjZ4rMJ+MZ6/2vrI0DkGy9yX31/ReC+fP+My/nH9V1gXoUoqOO/ATvkEwaxqvgyAAAAAElFTkSuQmCC');
				} else if ($ext == '.jpg' || $ext == '.jpeg') {
					$source_image = imagecreatefromjpeg($src);
				} else if ($ext == '.png') {
					$source_image = imagecreatefrompng($src);
				} else if ($ext == '.gif') {
					$source_image = imagecreatefromgif($src);
				}
				if (!is_resource($source_image)) {
					header('Content-Type: image/png');
					echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAQAAAAAYLlVAAAABGdBTUEAALGPC/xhBQAAACBjSFJNAAB6JgAAgIQAAPoAAACA6AAAdTAAAOpgAAA6mAAAF3CculE8AAAAAmJLR0QAAKqNIzIAAAAJcEhZcwAADdcAAA3XAUIom3gAAAAHdElNRQfiAQQWIjnFRr8kAAAEC0lEQVRo3sXZb2jVZRQH8M/u5sxZzfJ/lpaZ/zC1tJKaUUZCUFRUsOiFL4IQiYgyI0iiPy8S6pVRqxeCbzKJSjA0FojoKixCwZGFpGaT1BDdbHNzuzu92HVct90/3t17933e/H6/55znfJ/fOc/zO+f5kS+W2+E/UUA74wPj87aTAc/rLsj4pbYh08AVeZm/W5NRaHWgAPKL1TpjQuGzv86x1Dx2F6S/W4hMnYmc6hU2m1E4+1zITeBVj+GUntKRyIZ7dQtJK3SOhAvG26oKb9s1ErOvsEMIjRKU6g1kwxtCOGEiI0Hgfj1Ct+Wp+zLHwERbVOJNewsyOkwkNArh27SdsqwuWC+Ev1yf9qyMBB6UFC6657KnZYuBKT6XwDr7CjI3TCTsEsLXg3rK5IJ3hPCn2pEh8LCk0OnOIfoKJzDfMssydVYNmH8Cp60bQnIU2q7YeLWLfssmkJ4RTXcsR4ZUb2seRms84SHTTXeT0Y5odlCzH7XkUnwlZ2pZndP4fTZpG1K/w1qV2ZUbhFBnypBtdB5zf1pvv8GkFj9pdDTt2c8W5CYwNw9DQ6PKBSEc976FadE1xiJrnRdCp3mlI1AphG0q3Ogln9hpjwYvmglm2CmEpsxxNlwCnBTa7E576X2zfk8NLu0Ia0pHYMNlhnt19V/vBHN1CecyJQHDJzDKViFprxfMcZUK06z2jxBWg8+EMLtUBEioHeTjBTqF38FqITyVrlBc9GodtO8324Hb1JDaFReWjsDQOIqEebgI6RtaeQj0fdzOYlY/oTISqFWHHsdxOzhSXgIrVaFRj2qrcMGv5SRQ7XXQgHqTscXZoUWLsQwH40Mh7JEw1SkhLMkkWgoCj+gVzpren21+mlm4+ASmOi2EerwlhGZjykegIlVfbcYDksKF0uYDA/GyEI64xjgn0r4HZSIwX6cQHsXHQvgql0pxCXyZKm9ZKimcuqzSLDmBOakK41YJvwjh2dxKxSTQN9a7WCOE7/JXKg6Bw0K7Ggn/Cu1uySRYmq14kllo0uEuE7A9/ftXDgKLwF6sgGzFWWkI9CUc5zAJUulYGQn0lWBJUun4ocyiVXkMd+XoS7zWWZUqSlrzUyveKqjRklYfHM9Wc5fGBR3qfeO8Lm02ezzb+UhpXECTpvwES5mSVZqW+5dQqQjM9r3zWpzzRWop5oHiBeFS7WlBeNIN5X0DCQ1q0Gq7Dky2MbNwehD2xWqdm4dl/gczLMEBddrVOmSqJ43Vnlv1mWH9mrzUFntOCK+lRv1ICEvzccE2+4vigr59sCZ1dy3oziQ8cJk8OOCM/MqxyTh/4LCVjrnDHldLGqurKJPLE42pg7rDKbc0lNM4zPR3WlTsT7mhrBhno4Pa7LM++/nq/4ALJzEa9yMkAAAAJXRFWHRkYXRlOmNyZWF0ZQAyMDE4LTAxLTA0VDIyOjM0OjU3KzAxOjAw5ClVAgAAACV0RVh0ZGF0ZTptb2RpZnkAMjAxOC0wMS0wNFQyMjozNDo1NyswMTowMJV07b4AAAAZdEVYdFNvZnR3YXJlAHd3dy5pbmtzY2FwZS5vcmeb7jwaAAAAAElFTkSuQmCC');
					exit;
				}
				$width = imagesx($source_image);
				$height = imagesy($source_image);
				$desired_height = floor($height * ($_GET['Thumbnail'] / $width));
				$virtual_image = imagecreatetruecolor($_GET['Thumbnail'], $desired_height);
				imagecopyresized($virtual_image, $source_image, 0, 0, 0, 0, $_GET['Thumbnail'], $desired_height, $width, $height);
				header('Content-Type: image/jpg');
				imagejpeg($virtual_image);
				exit;
			}
			if (!file_exists($src)) {
				err('Download does not exist');
			}
			if ($ext == '.jpg' || $ext == '.jpeg') {
				header('Content-Type: image/jpg');
			} else if ($ext == '.png') {
				header('Content-Type: image/png');
			} else if ($ext == '.gif') {
				header('Content-Type: image/gif');
			} else if ($ext == '.pdf') {
				header('Content-type: application/pdf');
			} else if ($ext == '.html' || $ext == '.htm' || $ext == '.shtml' || $ext == '.js' || $ext == '.php') {
				header('Content-type: text/plain');
			} else {
				header('Content-Type: application/octet-stream');
				header('Content-Disposition: attachment; filename="' . basename($src) . '"');
			}
			readfile($src);
			exit;
		}
		if ($_GET['Action'] == 'CheckReply') {
			$this->check_reply($ticket);
		}
		if (isset($_GET['New'])) {
			$billic->set_title('New Ticket');
			echo '<h1>New Support Ticket</h1>';
			$priorities = ['High', 'Normal', 'Low'];
			if (empty($_POST['title']) && !empty($_GET['Title'])) {
				$_POST['title'] = urldecode($_GET['Title']);
			}
			if (isset($_POST['message'])) {
				if (empty($_POST['title'])) {
					$billic->errors[] = 'Please enter a short summary';
				}
				if (!in_array($_POST['priority'], $priorities))
					$billic->errors[] = 'Invalid Priority';
				$message = strip_tags($_POST['message'], $this->settings['allowed_tags']);
				$message = preg_replace('/<([a-z]+)( href\="([a-z0-9\/\:\.\-\_\+\=]+)")?[^>]*>/ims', '<$1$2>', $message);
				if (empty($message)) {
					$billic->errors[] = 'Please enter a message';
				}
				$successful_uploads = 0;
				if (!empty($_FILES['files'])) {
					foreach ($_FILES['files']['error'] as $v) {
						if ($v == UPLOAD_ERR_OK) {
							$successful_uploads++;
						}
					}
				}
				if (isset($_POST['min_attachments']) && $_POST['min_attachments'] > $successful_uploads) {
					$billic->errors[] = 'You must upload at least ' . floor($_POST['min_attachments']) . ' attachments.';
				}
				$attachments = '';
				if (empty($billic->errors) && !empty($_FILES['files'])) {
					if (!file_exists('../attachments/')) {
						mkdir('../attachments/', 0750);
						if (!file_exists('../attachments/')) {
							echo '<br><b>Warning:</b> billic tried to create the folder at ../attachments/ but failed.<br>';
						}
					}
					foreach ($_FILES['files']['tmp_name'] as $key => $tmp_name) {
						if ($_FILES['files']['error'][$key] != UPLOAD_ERR_OK) {
							if ($_FILES['files']['error'][$key] != UPLOAD_ERR_NO_FILE) {
								$billic->errors[] = 'There was an error while uploading the file: ' . $_FILES['files']['name'][$key];
							}
						} else {
							$filename = basename($_FILES['files']['name'][$key]);
							$filename = str_replace(' ', '_', $filename);
							$safe_chars = 'a b c d e f g h i j k l m n o p q r s t u v w x y z 0 1 2 3 4 5 6 7 8 9 _ .';
							$safe_chars = explode(' ', $safe_chars);
							$safe_name = '';
							for ($i = 0;$i < strlen($filename);$i++) {
								if (!in_array(strtolower($filename[$i]) , $safe_chars)) {
									continue;
								}
								$safe_name.= $filename[$i];
							}
							$filename = $safe_name;
							$filename = preg_replace('/([\.]+)/', '.', $filename);
							$filename = preg_replace('/([_]+)/', '_', $filename);
							$filename = time() . '_' . basename($filename);
							move_uploaded_file($tmp_name, '../attachments/' . $filename);
							$attachments.= $filename . '|';
						}
					}
				}
				$attachments = substr($attachments, 0, -1);
				if (!empty($_POST['serviceid'])) {
					$serviceid = $db->q('SELECT * FROM `services` WHERE `userid` = ? AND `id` = ?', $billic->user['id'], $_POST['serviceid']);
					$serviceid = $serviceid[0];
					if (empty($serviceid)) {
						$_POST['serviceid'] = '';
					}
				}
				if (empty($billic->errors)) {
					$now = time();
					$serviceid = 0;
					if (isset($_POST['serviceid'])) {
						$serviceid = $_POST['serviceid'];
					}
					$ticketid = $db->insert('tickets', array(
						'queue' => 'Support',
						'userid' => $billic->user['id'],
						'date' => $now,
						'title' => $_POST['title'],
						'status' => 'Open',
						'lastreply' => $now,
						'clientunread' => 1,
						'adminunread' => 1,
						'serviceid' => $serviceid,
						'replypassword' => $billic->rand_str(30),
						'priority' => $_POST['priority'],
					));
					$db->insert('ticketmessages', array(
						'tid' => $ticketid,
						'userid' => $billic->user['id'],
						'date' => $now,
						'message' => $message,
						'attachments' => $attachments,
					));
					$db->q('DELETE FROM `tickets_draft` WHERE `ticketid` = ? AND `userid` = ?', 0, $billic->user['id']);
					$url = 'http' . (get_config('billic_ssl') ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . '/Admin/Tickets/ID/' . $ticketid . '/';
					$emails = get_config('Tickets_emails');
					$emails = explode(PHP_EOL, $emails);
					foreach ($emails as $email) {
						$email = trim($email);
						if (empty($email)) {
							continue;
						}
						$billic->email($email, 'Support Ticket #' . $ticketid . ' Opened Notification', $billic->user['firstname'] . ' ' . $billic->user['lastname'] . ' has opened a support ticket.<br><a href="' . $url . '">' . $url . '</a><br>' . $message);
					}
					$billic->redirect('/User/Tickets/ID/' . $ticketid . '/');
				}
			}
			$billic->show_errors();
			echo '<form method="POST" id="replyForm" enctype="multipart/form-data"><table class="table table-striped" id="attachTable">';
			if (isset($_POST['min_attachments'])) {
				echo '<input type="hidden" name="min_attachments" value="' . floor($_POST['min_attachments']) . '">';
			}
			$services = $db->q('SELECT * FROM `services` WHERE `userid` = ? AND (`domainstatus` = \'Active\' OR `domainstatus` = \'Suspended\' OR `domainstatus` = \'Pending\')', $billic->user['id']);
			if (!empty($services)) {
				echo '<tr><td>Service:<br>(if applicable)</td><td><select class="form-control" name="serviceid"><option value=""></option>';
				foreach ($services as $service) {
					echo '<option value="' . $service['id'] . '"' . ($_GET['Service'] == $service['id'] ? ' selected' : '') . '>' . $billic->service_type($service) . '</option>';
				}
				echo '</select></td></tr>';
			}
			echo '<tr><td width="110">Title:</td><td colspan="5"><input type="text" class="form-control" name="title" id="title" value="' . safe($_POST['title']) . '" maxlength="75"></td></tr>';
			echo '<tr><td width="110">Priority:</td><td colspan="5"><select class="form-control" name="priority" id="priority">';
			if (empty($_POST['priority'])) $_POST['priority'] = 'Normal';
			foreach($priorities as $priority)
				echo '<option value="'.$priority.'"'.($priority==$_POST['priority']?' selected':'').'>'.$priority.'</option>';
			echo '</select></td></tr>';
			$this->reply_box('newticket');
			//<td>SSH/RDP Port:<br>(if applicable)</td><td><input type="text" class="form-control" name="connport" value="'.safe($_POST['port']).'" autocomplete="off"></td>
			//<td>Root/Admin User:<br>(if applicable)</td><td><input type="text" class="form-control" name="loginuser" value="'.safe($_POST['user']).'" autocomplete="off"></td>
			//<td>Root/Admin Pass:<br>(if applicable)</td><td><input type="password" class="form-control" name="loginpass" value="'.safe($_POST['pass']).'" autocomplete="off"></td></tr>
			echo '</table></form>';
			return;
		}
		if (isset($_GET['ID'])) {
			$ticket = $db->q('SELECT * FROM `tickets` WHERE `id` = ? AND `userid` = ?', $_GET['ID'], $billic->user['id']);
			$ticket = $ticket[0];
			if (empty($ticket)) {
				err("Ticket " . $_GET['ID'] . " does not exist");
			}
			if ($_GET['Action'] == 'SaveDraft') {
				$billic->disable_content();
				$this->save_draft($ticket['id'], $_POST['message']);
				exit;
			}
			$billic->set_title('Ticket #' . $ticket['id']);
			echo '<h1>Support Ticket #' . $ticket['id'] . ' - ' . htmlentities($ticket['title']) . '</h1>';
			if (!empty($ticket['serviceid'])) {
				$service = $db->q('SELECT * FROM `services` WHERE `id` = ? AND `userid` = ?', $ticket['serviceid'], $ticket['userid']);
				$service = $service[0];
				if (empty($service)) {
					$billic->errors[] = 'This ticket is regarding service ID ' . $_GET['ID'] . ' but it does not exist';
				} else {
					echo 'Service: <a href="/Admin/Services/ID/' . $service['id'] . '/">' . $billic->service_type($service) . '</a><br><br>';
				}
			}
			if (isset($_POST['message'])) {
				$message = strip_tags($_POST['message'], $this->settings['allowed_tags']);
				$message = preg_replace('/<([a-z]+)( href\="([a-z0-9\/\:\.\-\_\+\=]+)")?[^>]*>/ims', '<$1$2>', $message);
				if (empty($message)) {
					$billic->errors[] = 'Please enter a message';
				}
				$last_reply = $db->q('SELECT MD5(`message`), LENGTH(`message`) FROM `ticketmessages` WHERE `tid` = ? AND `userid` = ? ORDER BY `id` DESC LIMIT 1', $ticket['id'], $billic->user['id']);
				if (strlen($message) == $last_reply[0]['LENGTH(`message`)'] && md5($message) == $last_reply[0]['MD5(`message`)']) {
					$billic->errors[] = 'You refreshed the page and tried to reply with the same message';
				}
				$attachments = '';
				if (empty($billic->errors) && !empty($_FILES['files'])) {
					if (!file_exists('../attachments/')) {
						mkdir('../attachments/', 0750);
						if (!file_exists('../attachments/')) {
							echo '<br><b>Warning:</b> billic tried to create the folder at ../attachments/ but failed.<br>';
						}
					}
					foreach ($_FILES['files']['tmp_name'] as $key => $tmp_name) {
						if ($_FILES['files']['error'][$key] != UPLOAD_ERR_OK) {
							if ($_FILES['files']['error'][$key] != UPLOAD_ERR_NO_FILE) {
								$billic->errors[] = 'There was an error while uploading the file: ' . $_FILES['files']['name'][$key];
							}
						} else {
							$filename = basename($_FILES['files']['name'][$key]);
							$filename = str_replace(' ', '_', $filename);
							$safe_chars = 'a b c d e f g h i j k l m n o p q r s t u v w x y z 0 1 2 3 4 5 6 7 8 9 _ .';
							$safe_chars = explode(' ', $safe_chars);
							$safe_name = '';
							for ($i = 0;$i < strlen($filename);$i++) {
								if (!in_array(strtolower($filename[$i]) , $safe_chars)) {
									continue;
								}
								$safe_name.= $filename[$i];
							}
							$filename = $safe_name;
							$filename = preg_replace('/([\.]+)/', '.', $filename);
							$filename = preg_replace('/([_]+)/', '_', $filename);
							$filename = time() . '_' . basename($filename);
							move_uploaded_file($tmp_name, '../attachments/' . $filename);
							$attachments.= $filename . '|';
						}
					}
				}
				$attachments = substr($attachments, 0, -1);
				if (empty($billic->errors)) {
					$now = time();
					$db->insert('ticketmessages', array(
						'tid' => $ticket['id'],
						'userid' => $billic->user['id'],
						'date' => $now,
						'message' => $message,
						'attachments' => $attachments,
					));
					$db->q('UPDATE `tickets` SET `lastreply` = ?, `status` = \'Customer-Reply\', `adminunread` = \'1\' WHERE `id` = ?', $now, $ticket['id']);
					$db->q('DELETE FROM `tickets_draft` WHERE `ticketid` = ? AND `userid` = ?', $ticketid, $billic->user['id']);
					$url = 'http' . (get_config('billic_ssl') ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . '/Admin/Tickets/ID/' . $ticket['id'] . '/';
					$emails = get_config('Tickets_emails');
					$emails = explode(PHP_EOL, $emails);
					foreach ($emails as $email) {
						$email = trim($email);
						if (empty($email)) {
							continue;
						}
						$billic->email($email, 'Support Ticket #' . $ticket['id'] . ' Reply Notification', $billic->user['firstname'] . ' ' . $billic->user['lastname'] . ' has replied.<br><a href="' . $url . '">' . $url . '</a><br>' . $message);
					}
				}
			}
			$billic->show_errors();
			$this->show_messages($ticket, 'client');
			$this->reply_box('client');
			return;
		}
		$billic->set_title('My Support Tickets');
		echo '<a href="/User/Tickets/New" class="btn btn-success pull-right"><i class="icon-plus"></i> Open a New Ticket</a><h1><i class="icon-ticket"></i> My Support Tickets</h1>';
		$tickets = $db->q('SELECT * FROM `tickets` WHERE `userid` = ? ORDER BY `lastreply` DESC', $billic->user['id']);
		if (empty($tickets)) {
			echo '<p>You have no Support Tickets.</p>';
		} else {
			echo '<table class="table table-striped"><tr><th>Subject</th><th>Queue</th><th>Status</th><th width="150">Last Updated</th></tr>';
			foreach ($tickets as $ticket) {
				echo '<tr><td><a href="/User/Tickets/ID/' . $ticket['id'] . '/">' . ($ticket['clientunread'] == 1 ? '<b>' : '') . htmlentities($ticket['title']) . ($ticket['clientunread'] == 1 ? '</b>' : '') . '</a></td><td>' . $ticket['queue'] . '</td><td>' . $this->status_label($ticket['status']) . '</td><td>' . $billic->time_ago($ticket['lastreply']) . ' ago</td></tr>';
			}
			echo '</table>';
		}
	}
	function reply_box($mode) {
		global $billic, $db;
		switch ($mode) {
			case 'newticket':
				$title = 'Open a new ticket';
			break;
			case 'admin':
			case 'client':
				$title = 'Reply to this ticket';
			break;
		}
		echo '<span id="ticket_draft_ajax"></span>';
		echo '<span id="ticket_reply_ajax"></span>';
		$billic->add_script('//cdn.ckeditor.com/4.5.9/basic/ckeditor.js');
		if ($mode != 'newticket') {
			//echo '<div class="alert alert-danger alert-dismissible" role="alert"><button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button><strong>Warning! Josh Jameson </strong> is currently replying to this ticket.</div>';
			echo '<form method="POST" id="replyForm" enctype="multipart/form-data"><table class="table table-striped" id="attachTable"><tr><th colspan="2">' . $title . '</th></tr>';
		}
		$draft = $db->q('SELECT `message` FROM `tickets_draft` WHERE `ticketid` = ? AND `userid` = ?', $_GET['ID'], $billic->user['id']);
		$draft = $draft[0]['message'];
		if (!empty($draft)) {
			$_POST['message'] = $draft;
		}
		echo '<tr><td colspan="2"><textarea name="message" id="ticket_message" style="width:99%; height:100px">' . (empty($_POST['message']) ? $billic->user['signature'] : htmlentities($_POST['message'], ENT_QUOTES, 'UTF-8')) . '</textarea></td></tr>';
		echo '<tr><td colspan="2"><button type="button" class="btn btn-info" onClick="ticket_add_attachment()" style="float:right"><i class="icon-paperclip"></i> Add an attachment</button><div class="input-group">';
		if ($mode == 'admin') {
			echo '<select class="form-control" name="status" style="width:150px;margin-right:10px">';
			$ticket_status_list = array(
				'Answered',
				'In Progress',
				'Closed'
			);
			foreach ($ticket_status_list as $status) {
				echo '<option value="' . $status . '"' . ($_POST['status'] == $status ? ' selected' : '') . '>' . $status . '</option>';
			}
			echo '</select>';
		}
		if ($mode == 'newticket') {
			echo ' <input type="submit" class="btn btn-success" value="Open Ticket &raquo"></div></td></tr>';
		} else {
			echo ' <input type="submit" class="btn btn-success" value="Reply &raquo;"></div></td></tr></table></form>';
		}
?><script type="text/javascript">var attachment_count=0; function ticket_add_attachment() { attachment_count++; $('#attachTable').append('<tr><td width="150">Attachment #'+attachment_count+':</td><td><input class="form-control" type="file" name="files[]"></td></tr>'); }

addLoadEvent(function() {
	// Update message while typing (part 1)
	key_count_global = 0; // Global variable
	
	CKEDITOR.replace('ticket_message', {   
		allowedContent: true,
		enterMode: CKEDITOR.ENTER_BR,
		disableNativeSpellChecker: false,
	}).on('key',
		function(e){
			//setTimeout(function(){
			//	document.getElementById('text_hidden').value = e.editor.getData();
			//},10);
		
			// Update message while typing (part 2)
			key_count_global++;
			setTimeout("lookup("+key_count_global+")", 1000);
		}
	);
});

function addZero(i) {
    if (i < 10) {
        i = "0" + i;
    }
    return i;
}
			 
// Update message while typing (part 3)
function lookup(key_count) {
	if (key_count == key_count_global) { // The control will reach this point 1 second after user stops typing.
		var message = CKEDITOR.instances.ticket_message.getData();
		message = message.trim();
		if (message = "") {
			return;	
		}
		$.post( "/<?php echo ucwords($_SERVER['billic_mode']); ?>/Tickets/ID/<?php echo $_GET['ID']; ?>/Action/SaveDraft/", { "message": message }, function( data ) {
			try {
				json = jQuery.parseJSON(data);
			} catch (e) {
				$( "#ticket_draft_ajax" ).html('<div class="alert alert-danger" role="alert">An error occured while saving a draft!</div>');
				return;
			}
			if (json.status=='OK') {
				var time = new Date();
				$( "#ticket_draft_ajax" ).html('<div class="alert alert-info" role="alert">Draft saved at '+addZero(time.getHours())+':'+addZero(time.getMinutes())+'</div>');
				return;
			}
			$( "#ticket_draft_ajax" ).html('<div class="alert alert-danger" role="alert">An undefined error occured while trying to save the draft!</div>');
		});
	}
}

</script><?php
		if (array_key_exists('ID', $_GET) && ($billic->user_has_permission($billic->user, 'admin') || get_config('tickets_checkreply_users') == 1)) {
?>
<script type="text/javascript">
// Check if someone is already replying to the ticket
function ticket_check_reply() {
	$.get( "/<?php echo ucwords($_SERVER['billic_mode']); ?>/Tickets/ID/<?php echo $_GET['ID']; ?>/Action/CheckReply/", function( data ) {
		try {
			json = jQuery.parseJSON(data);
		} catch (e) {
			$( "#ticket_reply_ajax" ).html('<div class="alert alert-danger" role="alert">An error occured while checking for replies!</div>');
			return;
		}
		if (json.count==0) {
			$( "#ticket_reply_ajax" ).html('');
			return;
		}
		$( "#ticket_reply_ajax" ).html('<div class="alert alert-warning" role="alert">'+json.message+'</div>');
	});
}
addLoadEvent(function() {
	ticket_check_reply();
	setInterval("ticket_check_reply()", 5000);
});
</script>

<?php
		}
		/*
		      if ($billic->user_has_permission($billic->user, 'Users_Verify')) {
		          echo '<br>';
		          $user_row = $db->q('SELECT `verified`, `firstname`, `lastname` FROM `users` WHERE `id` = ?', $ticket['userid']);
		          $user_row = $user_row[0];
		          if ($user_row['verified']==0) {
		              echo '<a href="/Admin/Tickets/ID/'.$ticket['id'].'/Verify/1/" onclick="return confirm(\'Verify Account? (Attachments will be deleted)\');">Verify the account for '.safe($user_row['firstname']).' '.safe($user_row['lastname']).'</a>';
		          }
		          if ($user_row['verified']==1) {
		              echo '<a href="/Admin/Tickets/ID/'.$ticket['id'].'/Verify/0/" onclick="return confirm(\'Remove account verification?\');">Remove the account verification</a>';
		          }
		      }
		*/
	}
	function settings($array) {
		global $billic, $db;
		if (empty($_POST['update'])) {
			echo '<form method="POST"><input type="hidden" name="billic_ajax_module" value="Tickets"><table class="table table-striped">';
			echo '<tr><th>Setting</th><th>Value</th></tr>';
			echo '<tr><td>Email Notifications</td><td><textarea name="Tickets_emails" class="form-control">' . safe(get_config('Tickets_emails')) . '</textarea><br>A list of emails to send support ticket notifications to. Place 1 email per line.</td></tr>';
			echo '<tr><td colspan="2"><inpu type="checkbox" name="Tickets_christmas" value="1"' . (get_config('Tickets_christmas') == 1 ? ' checked' : '') . '> Enable Santa hats over avatars during Christmas holidays?</td></tr>';
			echo '<tr><td colspan="2" align="center"><input type="submit" class="btn btn-default" name="update" value="Update &raquo;"></td></tr>';
			echo '</table></form>';
		} else {
			if (empty($billic->errors)) {
				set_config('Tickets_emails', $_POST['Tickets_emails']);
				set_config('Tickets_christmas', $_POST['Tickets_christmas']);
				$billic->status = 'updated';
			}
		}
	}
	function users_submodule($array) {
		global $billic, $db;
		echo '<form method="POST" action="/Admin/Tickets/"><input type="hidden" name="billic_ajax_module" value="Tickets">';
		echo 'With Selected: <button type="submit" class="btn btn-xs btn-primary" name="merge"><i class="icon-resize-down"></i> Merge</button>';
		echo '<table class="table table-striped"><tr><th><input type="checkbox" onclick="checkAll(this, \'ids\')" data-enpassusermodified="yes"></th><th>Ticket&nbsp;#</th><th>Queue</th><th>Subject</th><th>Priority</th><th>Status</th><th>Last Updated</th></tr>';
		$tickets = $db->q('SELECT * FROM `tickets` WHERE `userid` = ? ORDER BY `lastreply` DESC', $array['user']['id']);
		if (empty($tickets)) {
			echo '<tr><td colspan="20">User has no tickets</td></tr>';
		}
		foreach ($tickets as $ticket) {
			echo '<tr><td><input type="checkbox" name="ids['.$ticket['id'].']"></td><td><a href="/Admin/Tickets/ID/' . $ticket['id'] . '/">' . $ticket['id'] . '</a></td><td>' . $ticket['queue'] . '</td><td>' . ($ticket['adminunread'] == 1 ? '<b>' : '') . htmlentities($ticket['title']) . ($ticket['clientunread'] == 1 ? '</b>' : '') . '</td><td>' . $this->priority($ticket) . '</td><td>' . $this->status_label($ticket['status']) . '</td><td>' . $billic->time_ago($ticket['lastreply']) . ' ago</td></tr>';
		}
		echo '</table>';
		echo '</form>';
	}
	function api() {
		global $billic, $db;
		$billic->force_login();
		switch ($_POST['action']) {
			case 'list':
				if (!$billic->user_has_permission($billic->user, 'admin')) {
					err('You do not have permission');
				}
				if (empty($_POST['status'])) {
					$tickets = $db->q('SELECT * FROM `tickets` WHERE `status` = \'Customer-Reply\' OR `status` = \'Open\' OR `status` = \'In Progress\' ORDER BY `lastreply` DESC');
				} else {
					$tickets = $db->q('SELECT * FROM `tickets` WHERE `status` = ? ORDER BY `lastreply` DESC', $_POST['status']);
				}
				if ($_POST['name'] == 1) {
					foreach ($tickets as $k => $v) {
						$user = $db->q('SELECT `firstname`, `lastname`, `companyname` FROM `users` WHERE `id` = ?', $v['userid']);
						$user = $user[0];
						$tickets[$k]['name'] = $user['firstname'] . ' ' . $user['lastname'];
						if (!empty($user['companyname'])) {
							$tickets[$k]['name'].= ' (' . $user['companyname'] . ')';
						}
					}
				}
				if ($_POST['timeago'] == 1) {
					foreach ($tickets as $k => $v) {
						$tickets[$k]['timeago'] = $billic->time_ago($v['lastreply']);
					}
				}
				/*
				if ($_POST['color']==1) {
					foreach($tickets as $k => $v) {
						$color = '';
						if ($v['status']=='Open' || $v['status']=='Customer-Reply') {
								$color = '#a94442';
						}
						$tickets[$k]['color'] = $color;
					}
				}
				*/
				echo json_encode($tickets);
				exit;
			break;
		}
	}
}
