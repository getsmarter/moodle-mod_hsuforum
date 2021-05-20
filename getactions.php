<?php

/**
 * Forum Actions
 * Generates a json array of posts with their likes and thanks in a forum discussion
 *
 * @package   hsuforum_actions
 * @copyright 2014 Moodle Pty Ltd (http://moodle.com)
 * @author    Mikhail Janowski <mikhail@getsmarter.co.za>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->dirroot.'/mod/hsuforum/hsuforum_actions_lib.php');

$d = required_param('d', PARAM_INT); // Forum discussion ID

//To do: check capability
//$context = context_module::instance($cm->id);
//require_capability('mod/forum:replypost', $context);

$result = new stdClass();
$result->result = false; // set in case uncaught error happens
$result->content = 'Unknown error';

//Only allow to get actions if logged in
if(isloggedin()) {

    //Get posts in discussion
    $sql = "
    SELECT
        p.id,
        p.discussion
    FROM
        {hsuforum_posts} p
    WHERE
        p.discussion = $d
    ";
    $posts = $DB->get_records_sql($sql);

    hsuforum_populate_post_actions($posts);

    $result->result = true;
    $result->content = $posts;
}
else {
    $result->result = false;
    $result->content = 'Your session has timed out. Please login again.';
}

header('Content-type: application/json');
echo json_encode($result);
