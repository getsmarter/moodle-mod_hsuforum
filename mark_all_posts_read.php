<?php

define('AJAX_SCRIPT', true);
require('../../config.php');

require_login();

$discussionid = required_param('discussionid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);

global $DB;

$config = get_config('hsuforum');
$now = time();
$cutoffdate = $now - ($config->oldpostdays * 24 * 3600);

if (!empty($config)) {
    try {
        if ($DB->record_exists('hsuforum_discussions', array('id' => $discussionid))) {
            $sql = "INSERT INTO {hsuforum_read} (userid, postid, discussionid, forumid, firstread, lastread)
            SELECT ?, p.id, p.discussion, d.forum, ?, ?
              FROM {hsuforum_posts} p
                   JOIN {hsuforum_discussions} d ON d.id = p.discussion
             WHERE p.discussion = ? AND p.modified >= ?";

            echo $DB->execute($sql, array($userid, $now, $now, $discussionid, $cutoffdate));

        }
    } catch (Exception $e) {
        error_log("Hsuforum hsuforum_mark_all_read: " .$e->getMessage());
    }
}
