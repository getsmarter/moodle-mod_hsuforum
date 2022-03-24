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

    /**
     * @param $course_id
     * @return mixed
     * @throws dml_exception
     */
    public static function get_course_coach($course_id) {
        global $CFG;

        $context = context_course::instance($course_id);
        $role_id = get_config('local_mention_users', 'emailfromrole');
        if ($role_id == 'noreply') {
            $email = $CFG->noreplyaddress;
            return $email;
        } else {
            $users = get_role_users($role_id, $context);
            $user = current($users);
            return $user;
        }
    }

    /**
     * Function to get the user role on the current context, role short name if exists, if not,
     * it returns if the user is admin in the current context, blank if not admin.
     *
     * @param string $userid Current user id number.
     * @return string Current user's role shortname
     */
    static function get_user_role_on_current_context($userid) {
        global $COURSE;

        $coursecontext = context_course::instance($COURSE->id);
        $userrole = current(get_user_roles($coursecontext, $userid));

        if (!empty($userrole->shortname)) return $userrole->shortname;

        return (is_siteadmin()) ? 'admin' : get_user_role_out_of_context($userid);
    }

    public static function clear_discussion_draft(\mod_hsuforum\event\discussion_created $event) {
        global $DB;
        try {
            $savedpostmessage = $DB->get_record(
                'hsuforum_posts',
                [
                    'discussion' => $event->objectid,
                    'parent' => 0
                ],
                'message'
            );

            $selectsql = sprintf('%s = :text', $DB->sql_compare_text('text'));
            $DB->delete_records_select('hsuforum_custom_drafts', $selectsql, ['text' => $savedpostmessage->message]);
        } catch (\Exception $e) {
            error_log('mod_hsuforum: observer: ' . $e->getMessage());
        }
    }

    public static function clear_post_draft(\mod_hsuforum\event\post_created $event) {
        global $DB;
        try {
            $savedpostmessage = $DB->get_record('hsuforum_posts', ['id' => $event->objectid], 'message');
            $selectsql = sprintf('%s = :text', $DB->sql_compare_text('text'));
            $DB->delete_records_select('hsuforum_custom_drafts', $selectsql, ['text' => $savedpostmessage->message]);
        } catch (\Exception $e) {
            error_log('mod_hsuforum: observer: ' . $e->getMessage());
        }
    }
}
