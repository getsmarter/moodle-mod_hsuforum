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

// SQL query
$sql = "
SELECT
    *
FROM
    {hsuforum_posts} p
INNER JOIN
    {hsuforum_actions} a
ON
    p.id = a.postid
WHERE
    p.discussion = $d
";

//Execute query
$results = $DB->get_records_sql($sql);


echo '<pre>'.print_r($results, true).'</pre>';

