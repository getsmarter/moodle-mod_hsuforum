<?php

define('AJAX_SCRIPT', true);
require('../../config.php');

require_login();

global $DB;

$forumid = required_param('forumid', PARAM_INT);
// It is coercing the potential empty value into an empty string, so Elvis it to force null if genuinely empty
$discussionid = optional_param('discussionid', null, PARAM_RAW) ?: null;
$postid = optional_param('postid', null, PARAM_RAW) ?: null;
$userid = required_param('userid', PARAM_INT);
$text = required_param('text', PARAM_TEXT);

try {
    $postexists = $DB->get_record('hsuforum_custom_drafts', [
        'forumid' => $forumid,
        'postid' => $postid,
        'discussionid' => $discussionid,
        'userid' => $userid,
    ]);

    if (!empty($postexists)) {
        $postexists->text = $text;
        $postexists->timemodified = time();

        echo $DB->update_record('hsuforum_custom_drafts', $postexists);
    } else {
        $post = new stdClass();
        $post->forumid = $forumid;
        $post->discussionid = $discussionid;
        $post->postid = $postid;
        $post->text = $text;
        $post->userid = $userid;
        $post->timemodified = time();

        echo $DB->insert_record('hsuforum_custom_drafts', $post);
    }
} catch (Exception $e) {
    error_log("Hsuforum save_post_draft.php: {$e->getMessage()}");
}

die;
