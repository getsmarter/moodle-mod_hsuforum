<?php

define('AJAX_SCRIPT', true);
require('../../config.php');

require_login();

global $DB;

$forumid = required_param('forumid', PARAM_INT);
// It is coercing the potential empty value into an empty string, so Elvis it to force null if genuinely empty
$discussionid = optional_param('discussionid', null, PARAM_RAW) ?: null;
$postid = optional_param('postid', null, PARAM_RAW) ?: null;
$userid = required_param('userid', PARAM_TEXT);
$outputtext = '';

try {
    $postexists = $DB->get_record('hsuforum_custom_drafts', [
        'forumid' => $forumid,
        'postid' => $postid,
        'discussionid' => $discussionid,
        'userid' => $userid,
    ]);

    if (!empty($postexists)) {
        $outputtext = $postexists->text;
    }
} catch (Exception $e) {
    error_log("Hsuforum get_post_draft.php: {$e->getMessage()}");
}

echo $outputtext;
die;
