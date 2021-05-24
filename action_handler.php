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
			header('Content-type: application/json');
			echo json_encode($like->get_action($discussionid));
		}
		break;
	case 'add':
		if ($action == 'like' && !empty($postid)) {
			$like = new like($postid);
			header('Content-type: application/json');
			echo json_encode($result);
		}
		break;
	case 'remove':
		if ($action == 'like' && !empty($postid)) {
			$like = new like($postid);
			header('Content-type: application/json');
			echo json_encode($result);
		}
		break;
	default:
		header('Content-type: application/json');
		echo json_encode('404');
}
