<?php 

use mod_hsuforum\controller\like;

require('../../config.php');
require_once($CFG->dirroot.'/mod/hsuforum/hsuforum_actions_lib.php');

$postid = optional_param('p', '', PARAM_INT); // Forum post ID
$action = required_param('action', PARAM_TEXT); // Action
$type = required_param('type', PARAM_TEXT);
$discussionid = optional_param('d', '', PARAM_INT);

switch ($type) {
	case 'get':
		if ($action == 'like' && !empty($discussionid)) {
			$like = new like();
			header('HTTP/1.1 200 OK');
			header('Content-type: application/json');
			echo json_encode($like->get_action($discussionid));
		}
		break;
	case 'add':
		if ($action == 'like' && !empty($postid)) {
			$like = new like();
			header('HTTP/1.1 200 OK');
			header('Content-type: application/json');
			echo json_encode($like->set_action($postid));
		}
		break;
	case 'remove':
		if ($action == 'like' && !empty($postid)) {
			$like = new like();
			header('HTTP/1.1 200 OK');
			header('Content-type: application/json');
			echo json_encode($like->delete_action($postid));
		}
		break;
	default:
		header('HTTP/1.1 404 Not Found');
		header('Content-type: application/json');
		echo json_encode('404 Action type not found');
}
