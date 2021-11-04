<?php

define('AJAX_SCRIPT', true);
require('../../config.php');

require_login();

global $DB;

$discussionid = required_param('discussionid', PARAM_TEXT);
$postid = required_param('postid', PARAM_TEXT);
$text = required_param('text', PARAM_TEXT);
$userid = required_param('userid', PARAM_TEXT);


try {
	$postexists = $DB->get_record('hsuforum_custom_drafts', array('postid' => $postid, 'discussionid' => $discussionid, 'userid' => $userid));

	if (!empty($postexists)) {
		$postexists->text = $text;
		$postexists->timemodified = time();

		echo $DB->update_record('hsuforum_custom_drafts', $postexists);
	} else {
		$post = new stdClass();
		$post->discussionid = $discussionid;
		$post->postid = $postid;
		$post->text = $text;
		$post->userid = $userid;
		$post->timemodified = time();

		echo $DB->insert_record('hsuforum_custom_drafts', $post);
	}
} catch (Exception $e) {
	
}
die;