<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Event observers used in forum.
 *
 * @package    mod_hsuforum
 * @copyright  2013 Rajesh Taneja <rajesh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Event observer for mod_hsuforum.
 */
class mod_hsuforum_observer {

    /**
     * Triggered via user_enrolment_deleted event.
     *
     * @param \core\event\user_enrolment_deleted $event
     */
    public static function user_enrolment_deleted(\core\event\user_enrolment_deleted $event) {
        global $DB;

        // NOTE: this has to be as fast as possible.
        // Get user enrolment info from event.
        $cp = (object)$event->other['userenrolment'];
        if ($cp->lastenrol) {
            $params = array('userid' => $cp->userid, 'courseid' => $cp->courseid);
            $forumselect = "IN (SELECT f.id FROM {hsuforum} f WHERE f.course = :courseid)";

            $DB->delete_records_select('hsuforum_digests', 'userid = :userid AND forum '.$forumselect, $params);
            $DB->delete_records_select('hsuforum_subscriptions', 'userid = :userid AND forum '.$forumselect, $params);
            $DB->delete_records_select('hsuforum_track_prefs', 'userid = :userid AND forumid '.$forumselect, $params);
            $DB->delete_records_select('hsuforum_read', 'userid = :userid AND forumid '.$forumselect, $params);
        }
    }

    /**
     * Observer for role_assigned event.
     *
     * @param \core\event\role_assigned $event
     * @return void
     */
    public static function role_assigned(\core\event\role_assigned $event) {
        global $CFG, $DB;

        $context = context::instance_by_id($event->contextid, MUST_EXIST);

        // If contextlevel is course then only subscribe user. Role assignment
        // at course level means user is enroled in course and can subscribe to forum.
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        // Forum lib required for the constant used below.
        require_once($CFG->dirroot . '/mod/hsuforum/lib.php');

        $userid = $event->relateduserid;
        $sql = "SELECT f.id, cm.id AS cmid
                  FROM {hsuforum} f
                  JOIN {course_modules} cm ON (cm.instance = f.id)
                  JOIN {modules} m ON (m.id = cm.module)
             LEFT JOIN {hsuforum_subscriptions} fs ON (fs.forum = f.id AND fs.userid = :userid)
                 WHERE f.course = :courseid
                   AND f.forcesubscribe = :initial
                   AND m.name = 'hsuforum'
                   AND fs.id IS NULL";
        $params = array('courseid' => $context->instanceid, 'userid' => $userid, 'initial' => HSUFORUM_INITIALSUBSCRIBE);

        $forums = $DB->get_records_sql($sql, $params);
        foreach ($forums as $forum) {
            // If user doesn't have allowforcesubscribe capability then don't subscribe.
            $modcontext = context_module::instance($forum->cmid);
            if (has_capability('mod/hsuforum:allowforcesubscribe', $modcontext, $userid)) {
                hsuforum_subscribe($userid, $forum->id, $modcontext);
            }
        }
    }

    /**
     * Observer for \core\event\course_module_created event.
     *
     * @param \core\event\course_module_created $event
     * @return void
     */
    public static function course_module_created(\core\event\course_module_created $event) {
        global $CFG;

        if ($event->other['modulename'] === 'hsuforum') {
            // Include the forum library to make use of the hsuforum_instance_created function.
            require_once($CFG->dirroot . '/mod/hsuforum/lib.php');

            $forum = $event->get_record_snapshot('hsuforum', $event->other['instanceid']);
            hsuforum_instance_created($event->get_context(), $forum);
        }
    }

    /**
     * @param \mod_hsuforum\event\assessable_uploaded $event
     * @throws dml_exception
     * @throws moodle_exception
     */
    public static function email_mention_hsu(\mod_hsuforum\event\assessable_uploaded $event) {
        global $DB;

        $other = (object)$event->other;
        $content = str_replace('&nbsp;', ' ', $other->content);

        $id_array = self::parse_id($content);

        foreach ($id_array as $id) {
            if(strpos($id, ',') !== false) {
                $all_ids = explode(',', $id);
                foreach ($all_ids as $id_all) {
                    $taggeduser = $DB->get_record('user', array('id' => $id_all));
                    $role = self::get_user_role_on_current_context($taggeduser->id);

                    // Skip if the user to be notified is not a student.
                    if($role != 'student') {
                        continue;
                    }

                    $taggedusername = $taggeduser->firstname;
                    if (strpos($content, 'all') !== false ) {
                        $discussion_id = $other->discussionid;
                        $post_id = $event->objectid;
                        $course_id = $event->courseid;

                        $course_name = $DB->get_field("course", "fullname", array("id"=>$course_id));
                        $from_user = $DB->get_record("user", array("id"=>$event->userid));

                        $link = $_SERVER['HTTP_HOST'] . '/mod/hsuforum/discuss.php?d=' . $discussion_id . '&postid' . $post_id . '#p' . $post_id;
                        $subject = get_config('local_mention_users', 'defaultproperties_subject');
                        $subject = str_replace("{course_fullname}", $course_name, $subject);
                        $course_coach = self::get_course_coach($course_id);
                        $body = get_config('local_mention_users', 'defaultproperties_body');
                        $body = str_replace("{student_first_name}", $taggedusername, $body);
                        $body = str_replace("{coach_first_name}", $course_coach->firstname, $body);
                        $body = str_replace("{post_link}", $link, $body);
                        $body = str_replace("{message_text}", $content, $body);
                        $bodyhtml = text_to_html($body);

                        $eventdata = new \core\message\message();
                        $eventdata->component          = 'local_getsmarter_communication';
                        $eventdata->name               = 'hsuforum_mentions';
                        $eventdata->userfrom           = isset($from_user) && !empty($from_user) ? $from_user->id : -10;
                        $eventdata->userto             = $id_all;
                        $eventdata->subject            = $subject;
                        $eventdata->courseid           = $event->courseid;
                        $eventdata->fullmessage        = $body;
                        $eventdata->fullmessageformat  = FORMAT_HTML;
                        $eventdata->fullmessagehtml    = $bodyhtml;
                        $eventdata->notification       = 1;
                        $eventdata->replyto            = '';
                        $eventdata->smallmessage       = $subject;

                        $contexturl = new moodle_url('/mod/hsuforum/discuss.php', array('d' => $discussion_id, 'postid' => $post_id), 'p' . $post_id);
                        $eventdata->contexturl = $contexturl->out();
                        $eventdata->contexturlname = (isset($discussion->name) ? $discussion->name : '');

                        try {
                            message_send($eventdata);
                        } catch (Exception $e) {
                            error_log($e);
                        }
                    }
                }
            } else {
                $taggeduser = $DB->get_record('user', array('id' => $id));
                $taggedusername = $taggeduser->firstname;

                if (strpos($content, $taggedusername) !== false ) {
                    $discussion_id = $other->discussionid;
                    $post_id = $event->objectid;
                    $course_id = $event->courseid;

                    $course_name = $DB->get_field("course", "fullname", array("id"=>$course_id));
                    $from_user = $DB->get_record("user", array("id"=>$event->userid));

                    $link = $_SERVER['HTTP_HOST'] . '/mod/hsuforum/discuss.php?d=' . $discussion_id . '&postid=' . $post_id . '#p' . $post_id;
                    $subject = get_config('local_mention_users', 'defaultproperties_subject');
                    $subject = str_replace("{course_fullname}", $course_name, $subject);
                    $course_coach = self::get_course_coach($course_id);
                    $body = get_config('local_mention_users', 'defaultproperties_body');
                    $body = str_replace("{student_first_name}", $taggedusername, $body);
                    $body = str_replace("{coach_first_name}", $course_coach->firstname, $body);
                    $body = str_replace("{post_link}", $link, $body);
                    $body = str_replace("{message_text}", $content, $body);
                    $bodyhtml = text_to_html($body);

                    $eventdata = new \core\message\message();
                    $eventdata->component          = 'local_getsmarter_communication';
                    $eventdata->name               = 'hsuforum_mentions';
                    $eventdata->userfrom           = isset($from_user) && !empty($from_user) ? $from_user->id : -10;
                    $eventdata->userto             = $id;
                    $eventdata->subject            = $subject;
                    $eventdata->courseid           = $event->courseid;
                    $eventdata->fullmessage        = $body;
                    $eventdata->fullmessageformat  = FORMAT_HTML;
                    $eventdata->fullmessagehtml    = $bodyhtml;
                    $eventdata->notification       = 1;
                    $eventdata->replyto            = '';
                    $eventdata->smallmessage       = $subject;

                    $contexturl = new moodle_url('/mod/hsuforum/discuss.php', array('d' => $discussion_id, 'postid' => $post_id), 'p' . $post_id);
                    $eventdata->contexturl = $contexturl->out();
                    $eventdata->contexturlname = (isset($discussion->name) ? $discussion->name : '');

                    try {
                        message_send($eventdata);
                    } catch (Exception $e) {
                        error_log($e);
                    }
                }
            }
        }
    }

    /**
     * @param $content
     * @return array
     */
    public static function parse_id($content) {
        $string_array = explode('userid="',$content);
        $id_array = array();

        for ($x = 1; $x < count($string_array); $x++) {
            $string = $string_array[$x];
            $id = explode('">', $string)[0];
            array_push($id_array, $id);
        }

        return $id_array;
    }

}
