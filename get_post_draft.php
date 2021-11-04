<?php

define('AJAX_SCRIPT', true);
require('../../config.php');

require_login();

global $DB;

$discussionid = required_param('discussionid', PARAM_TEXT);
$postid = required_param('postid', PARAM_TEXT);
$userid = required_param('userid', PARAM_TEXT);


try {
	$postexists = $DB->get_record('hsuforum_custom_drafts', array('postid' => $postid, 'discussionid' => $discussionid, 'userid' => $userid));

	if (!empty($postexists)) {
		echo $postexists->text;
	} 
} catch (Exception $e) {
	echo "";
}
die;