<?php

/**
 * Forum Actions
 * Remove an action from the database
 *
 * @package   hsuforum_actions
 * @copyright 2014 Moodle Pty Ltd (http://moodle.com)
 * @author    Mikhail Janowski <mikhail@getsmarter.co.za>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->dirroot.'/mod/hsuforum/hsuforum_actions_lib.php');

$p = required_param('p', PARAM_INT); // Forum post ID
$action = required_param('action', PARAM_TEXT); // Action

//To do: check capability
//$context = context_module::instance($cm->id);
//require_capability('mod/forum:replypost', $context);

$result = new stdClass();
$result->result = false; // set in case uncaught error happens
$result->content = 'Unknown error';

//Only allow to remove action if logged in
if(isloggedin()) {

    if($action == 'like' || $action == 'thanks') {

        $deleted = $DB->delete_records('hsuforum_actions', array('postid' => $p, 'userid' => $USER->id, 'action' => $action));

        //Get post to return
        $sql = "
        SELECT
            p.id,
            p.discussion
        FROM
            {hsuforum_posts} p
        WHERE
            p.id = $p
        ";

        $post = $DB->get_records_sql($sql);

        hsuforum_populate_post_actions($post);

        $result->result = true;
        $result->content = $post;
    }
    else {
        $result->result = false;
        $result->content = 'Invalid action';
    }
}
else {
    $result->result = false;
    $result->content = 'Your session has timed out. Please login again.';
}

header('Content-type: application/json');
echo json_encode($result);
