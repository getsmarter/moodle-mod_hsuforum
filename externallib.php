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
 * External forum API
 *
 * @package    mod_hsuforum
 * @copyright  2012 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/externallib.php");

class mod_hsuforum_external extends external_api {

    /**
     * Describes the parameters for get_forum.
     *
     * @return external_external_function_parameters
     * @since Moodle 2.5
     */
    public static function get_forums_by_courses_parameters() {
        return new external_function_parameters (
            array(
                'courseids' => new external_multiple_structure(new external_value(PARAM_INT, 'course ID',
                        VALUE_REQUIRED, '', NULL_NOT_ALLOWED), 'Array of Course IDs', VALUE_DEFAULT, array()),
            )
        );
    }

    /**
     * Returns a list of forums in a provided list of courses,
     * if no list is provided all forums that the user can view
     * will be returned.
     *
     * @param array $courseids the course ids
     * @return array the forum details
     * @since Moodle 2.5
     */
    public static function get_forums_by_courses($courseids = array()) {
        global $CFG;

        require_once($CFG->dirroot . "/mod/hsuforum/lib.php");

        $params = self::validate_parameters(self::get_forums_by_courses_parameters(), array('courseids' => $courseids));

        if (empty($params['courseids'])) {
            $params['courseids'] = array_keys(enrol_get_my_courses());
        }

        // Array to store the forums to return.
        $arrforums = array();
        $warnings = array();

        // Ensure there are courseids to loop through.
        if (!empty($params['courseids'])) {

            list($courses, $warnings) = external_util::validate_courses($params['courseids']);

            // Get the forums in this course. This function checks users visibility permissions.
            $forums = get_all_instances_in_courses("hsuforum", $courses);
            foreach ($forums as $forum) {

                $course = $courses[$forum->course];
                $cm = get_coursemodule_from_instance('hsuforum', $forum->id, $course->id);
                $context = context_module::instance($cm->id);

                // Skip forums we are not allowed to see discussions.
                if (!has_capability('mod/hsuforum:viewdiscussion', $context)) {
                    continue;
                }

                $forum->name = external_format_string($forum->name, $context->id);
                // Format the intro before being returning using the format setting.
                list($forum->intro, $forum->introformat) = external_format_text($forum->intro, $forum->introformat,
                                                                                $context->id, 'mod_hsuforum', 'intro', 0);
                // Discussions count. This function does static request cache.
                $forum->numdiscussions = hsuforum_count_discussions($forum, $cm, $course);
                $forum->cmid = $forum->coursemodule;
                $forum->cancreatediscussions = hsuforum_user_can_post_discussion($forum, null, -1, $cm, $context);

                // Add the forum to the array to return.
                $arrforums[$forum->id] = $forum;
            }
        }
        return $arrforums;
    }

    /**
     * Describes the get_forum return value.
     *
     * @return external_single_structure
     * @since Moodle 2.5
     */
    public static function get_forums_by_courses_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id' => new external_value(PARAM_INT, 'Forum id'),
                    'course' => new external_value(PARAM_INT, 'Course id'),
                    'type' => new external_value(PARAM_TEXT, 'The forum type'),
                    'name' => new external_value(PARAM_RAW, 'Forum name'),
                    'intro' => new external_value(PARAM_RAW, 'The forum intro'),
                    'introformat' => new external_format_value('intro'),
                    'assessed' => new external_value(PARAM_INT, 'Aggregate type'),
                    'assesstimestart' => new external_value(PARAM_INT, 'Assess start time'),
                    'assesstimefinish' => new external_value(PARAM_INT, 'Assess finish time'),
                    'scale' => new external_value(PARAM_INT, 'Scale'),
                    'maxbytes' => new external_value(PARAM_INT, 'Maximum attachment size'),
                    'maxattachments' => new external_value(PARAM_INT, 'Maximum number of attachments'),
                    'forcesubscribe' => new external_value(PARAM_INT, 'Force users to subscribe'),
                    'rsstype' => new external_value(PARAM_INT, 'RSS feed for this activity'),
                    'rssarticles' => new external_value(PARAM_INT, 'Number of RSS recent articles'),
                    'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                    'warnafter' => new external_value(PARAM_INT, 'Post threshold for warning'),
                    'blockafter' => new external_value(PARAM_INT, 'Post threshold for blocking'),
                    'blockperiod' => new external_value(PARAM_INT, 'Time period for blocking'),
                    'completiondiscussions' => new external_value(PARAM_INT, 'Student must create discussions'),
                    'completionreplies' => new external_value(PARAM_INT, 'Student must post replies'),
                    'completionposts' => new external_value(PARAM_INT, 'Student must post discussions or replies'),
                    'cmid' => new external_value(PARAM_INT, 'Course module id'),
                    'showrecent' => new external_value(PARAM_INT, 'Show recent posts on course page'),
                    'showsubstantive' => new external_value(PARAM_INT, 'Show toggle to mark posts as substantive'),
                    'showbookmark' => new external_value(PARAM_INT, 'Show toggle to bookmark posts'),
                    'allowprivatereplies' => new external_value(PARAM_INT, 'Allow private replies'),
                    'anonymous' => new external_value(PARAM_INT, 'Allow anonymous posts'),
                    'gradetype' => new external_value(PARAM_INT, 'Gradetype'),
                    'numdiscussions' => new external_value(PARAM_INT, 'Number of discussions'),
                    'cancreatediscussions' => new external_value(PARAM_BOOL, 'If the user can create discussions', VALUE_OPTIONAL),
                ), 'hsuforum'
            )
        );
    }

    /**
     * Describes the parameters for get_forum_discussions.
     *
     * @return external_external_function_parameters
     * @since Moodle 2.5
     */
    public static function get_forum_discussions_parameters() {
        return new external_function_parameters (
            array(
                'forumids' => new external_multiple_structure(new external_value(PARAM_INT, 'forum ID',
                        VALUE_REQUIRED, '', NULL_NOT_ALLOWED), 'Array of Forum IDs', VALUE_REQUIRED),
                'limitfrom' => new external_value(PARAM_INT, 'limit from', VALUE_DEFAULT, 0),
                'limitnum' => new external_value(PARAM_INT, 'limit number', VALUE_DEFAULT, 0)
            )
        );
    }

    /**
     * Returns a list of forum discussions as well as a summary of the discussion
     * in a provided list of forums.
     *
     * @param array $forumids the forum ids
     * @param int $limitfrom limit from SQL data
     * @param int $limitnum limit number SQL data
     *
     * @return array the forum discussion details
     * @since Moodle 2.5
     */
    public static function get_forum_discussions($forumids, $limitfrom = 0, $limitnum = 0) {
        global $CFG, $DB;

        require_once($CFG->dirroot . "/mod/hsuforum/lib.php");

        // Validate the parameter.
        $params = self::validate_parameters(self::get_forum_discussions_parameters(),
            array(
                'forumids'  => $forumids,
                'limitfrom' => $limitfrom,
                'limitnum'  => $limitnum,
            ));
        $forumids  = $params['forumids'];
        $limitfrom = $params['limitfrom'];
        $limitnum  = $params['limitnum'];

        // Array to store the forum discussions to return.
        $arrdiscussions = array();
        // Keep track of the users we have looked up in the DB.
        $arrusers = array();

        // Loop through them.
        foreach ($forumids as $id) {
            // Get the forum object.
            $forum = $DB->get_record('hsuforum', array('id' => $id), '*', MUST_EXIST);

            $course = get_course($forum->course);

            $modinfo = get_fast_modinfo($course);
            $forums  = $modinfo->get_instances_of('hsuforum');
            $cm = $forums[$forum->id];

            // Get the module context.
            $modcontext = context_module::instance($cm->id);

            // Validate the context.
            self::validate_context($modcontext);

            require_capability('mod/hsuforum:viewdiscussion', $modcontext);

            $order = 'pinned DESC, timemodified DESC';
            if ($discussions = hsuforum_get_discussions($cm, $order, true, [$limitfrom, $limitnum])) {

                // Check if they can view full names.
                $canviewfullname = has_capability('moodle/site:viewfullnames', $modcontext);
                foreach ($discussions as $discussion) {
                    // This function checks capabilities, timed discussions, groups and qanda forums posting.
                    if (!hsuforum_user_can_see_discussion($forum, $discussion, $modcontext)) {
                        continue;
                    }
                    $usernamefields = user_picture::fields();
                    // If we don't have the users details then perform DB call.
                    if (empty($arrusers[$discussion->userid])) {
                        $arrusers[$discussion->userid] = $DB->get_record('user', array('id' => $discussion->userid),
                                $usernamefields, MUST_EXIST);
                    }
                    // Create object to return.
                    $return = new stdClass();
                    $return->id = (int) $discussion->discussion;
                    $return->course = $cm->course;
                    $return->forum = $forum->id;
                    $return->name = $discussion->name;
                    $return->userid = $discussion->userid;
                    $return->groupid = $discussion->groupid;
                    $return->assessed = $discussion->assessed;
                    $return->timemodified = (int) $discussion->timemodified;
                    $return->usermodified = $discussion->usermodified;
                    $return->timestart = $discussion->timestart;
                    $return->timeend = $discussion->timeend;
                    $return->firstpost = (int) $discussion->id;
                    $return->firstuserfullname = fullname($arrusers[$discussion->userid], $canviewfullname);
                    $return->firstuserimagealt = $arrusers[$discussion->userid]->imagealt;
                    $return->firstuserpicture = $arrusers[$discussion->userid]->picture;
                    $return->firstuseremail = $arrusers[$discussion->userid]->email;
                    $return->subject = $discussion->subject;
                    $return->numunread = '';
                    $return->numunread = (int) $discussion->unread;

                    // Check if there are any replies to this discussion.
                    if (!is_null($discussion->replies)) {
                        $return->numreplies = (int) $discussion->replies;
                        $return->lastpost = (int) $discussion->lastpostid;
                    } else { // No replies, so the last post will be the first post.
                        $return->numreplies = 0;
                        $return->lastpost = (int) $discussion->firstpost;
                    }
                    // Get the last post as well as the user who made it.
                    $lastpost = $DB->get_record('hsuforum_posts', array('id' => $return->lastpost), '*', MUST_EXIST);
                    if (empty($arrusers[$lastpost->userid])) {
                        $arrusers[$lastpost->userid] = $DB->get_record('user', array('id' => $lastpost->userid),
                                $usernamefields, MUST_EXIST);
                    }
                    $return->lastuserid = $lastpost->userid;
                    $return->lastuserfullname = fullname($arrusers[$lastpost->userid], $canviewfullname);
                    $return->lastuserimagealt = $arrusers[$lastpost->userid]->imagealt;
                    $return->lastuserpicture = $arrusers[$lastpost->userid]->picture;
                    $return->lastuseremail = $arrusers[$lastpost->userid]->email;
                    // Add the discussion statistics to the array to return.
                    $arrdiscussions[$return->id] = (array) $return;
                }
                $discussions->close();
            }
        }

        return $arrdiscussions;
    }

    /**
     * Describes the get_forum_discussions return value.
     *
     * @return external_single_structure
     * @since Moodle 2.5
     */
    public static function get_forum_discussions_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id' => new external_value(PARAM_INT, 'Forum id'),
                    'course' => new external_value(PARAM_INT, 'Course id'),
                    'forum' => new external_value(PARAM_INT, 'The forum id'),
                    'name' => new external_value(PARAM_TEXT, 'Discussion name'),
                    'userid' => new external_value(PARAM_INT, 'User id'),
                    'groupid' => new external_value(PARAM_INT, 'Group id'),
                    'assessed' => new external_value(PARAM_INT, 'Is this assessed?'),
                    'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                    'usermodified' => new external_value(PARAM_INT, 'The id of the user who last modified'),
                    'timestart' => new external_value(PARAM_INT, 'Time discussion can start'),
                    'timeend' => new external_value(PARAM_INT, 'Time discussion ends'),
                    'firstpost' => new external_value(PARAM_INT, 'The first post in the discussion'),
                    'firstuserfullname' => new external_value(PARAM_TEXT, 'The discussion creators fullname'),
                    'firstuserimagealt' => new external_value(PARAM_TEXT, 'The discussion creators image alt'),
                    'firstuserpicture' => new external_value(PARAM_INT, 'The discussion creators profile picture'),
                    'firstuseremail' => new external_value(PARAM_TEXT, 'The discussion creators email'),
                    'subject' => new external_value(PARAM_TEXT, 'The discussion subject'),
                    'numreplies' => new external_value(PARAM_TEXT, 'The number of replies in the discussion'),
                    'numunread' => new external_value(PARAM_TEXT, 'The number of unread posts, blank if this value is
                        not available due to forum settings.'),
                    'lastpost' => new external_value(PARAM_INT, 'The id of the last post in the discussion'),
                    'lastuserid' => new external_value(PARAM_INT, 'The id of the user who made the last post'),
                    'lastuserfullname' => new external_value(PARAM_TEXT, 'The last person to posts fullname'),
                    'lastuserimagealt' => new external_value(PARAM_TEXT, 'The last person to posts image alt'),
                    'lastuserpicture' => new external_value(PARAM_INT, 'The last person to posts profile picture'),
                    'lastuseremail' => new external_value(PARAM_TEXT, 'The last person to posts email'),
                ), 'discussion'
            )
        );
    }

    /**
     * Describes the parameters for get_forum_discussion_posts.
     *
     * @return external_external_function_parameters
     * @since Moodle 2.7
     */
    public static function get_forum_discussion_posts_parameters() {
        return new external_function_parameters (
            array(
                'discussionid' => new external_value(PARAM_INT, 'discussion ID', VALUE_REQUIRED),
            )
        );
    }

    /**
     * Returns a list of forum posts for a discussion
     *
     * @param int $discussionid the post ids
     *
     * @return array the forum post details
     * @since Moodle 2.7
     */
    public static function get_forum_discussion_posts($discussionid) {
        global $CFG, $DB, $USER, $PAGE;

        $posts = array();
        $warnings = array();

        // Validate the parameter.
        $params = self::validate_parameters(
            self::get_forum_discussion_posts_parameters(),
            array('discussionid' => $discussionid)
        );

        // Compact/extract functions are not recommended.
        $discussionid   = $params['discussionid'];

        $discussion = $DB->get_record('hsuforum_discussions', array('id' => $discussionid), '*', MUST_EXIST);
        $forum = $DB->get_record('hsuforum', array('id' => $discussion->forum), '*', MUST_EXIST);
        $course = $DB->get_record('course', array('id' => $forum->course), '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('hsuforum', $forum->id, $course->id, false, MUST_EXIST);

        // Validate the module context. It checks everything that affects the module visibility (including groupings, etc..).
        $modcontext = context_module::instance($cm->id);
        self::validate_context($modcontext);

        // This require must be here, see mod/hsuforum/discuss.php.
        require_once($CFG->dirroot . "/mod/hsuforum/lib.php");

        // Check they have the view forum capability.
        require_capability('mod/hsuforum:viewdiscussion', $modcontext, null, true, 'noviewdiscussionspermission', 'forum');

        if (! $post = hsuforum_get_post_full($discussion->firstpost)) {
            throw new moodle_exception('notexists', 'hsuforum');
        }

        // This function check groups, qanda, timed discussions, etc.
        if (!hsuforum_user_can_see_post($forum, $discussion, $post, null, $cm)) {
            throw new moodle_exception('noviewdiscussionspermission', 'hsuforum');
        }

        $canviewfullname = has_capability('moodle/site:viewfullnames', $modcontext);

        // We will add this field in the response.
        $canreply = hsuforum_user_can_post($forum, $discussion, $USER, $cm, $course, $modcontext);

        $allposts = hsuforum_get_all_discussion_posts($discussion->id);

        foreach ($allposts as $pid => $post) {

            if (!hsuforum_user_can_see_post($forum, $discussion, $post, null, $cm)) {
                $warning = array();
                $warning['item'] = 'post';
                $warning['itemid'] = $post->id;
                $warning['warningcode'] = '1';
                $warning['message'] = 'You can\'t see this post';
                $warnings[] = $warning;
                continue;
            }

            // Function hsuforum_get_all_discussion_posts adds postread field.
            // Note that the value returned can be a boolean or an integer. The WS expects a boolean.
            if (empty($post->postread)) {
                $post->postread = false;
            } else {
                $post->postread = true;
            }

            $post->canreply = $canreply;
            if (!empty($post->children)) {
                $post->children = array_keys($post->children);
            } else {
                $post->children = array();
            }

            $user = new stdclass();
            $user = username_load_fields_from_object($user, $post);
            $allposts[$pid]->userfullname = fullname($user, $canviewfullname);

            $posts[] = $post;
        }

        $result = array();
        $result['posts'] = $posts;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the get_forum_discussion_posts return value.
     *
     * @return external_single_structure
     * @since Moodle 2.7
     */
    public static function get_forum_discussion_posts_returns() {
        return new external_single_structure(
            array(
                'posts' => new external_multiple_structure(
                        new external_single_structure(
                            array(
                                'id' => new external_value(PARAM_INT, 'Post id'),
                                'discussion' => new external_value(PARAM_INT, 'Discussion id'),
                                'parent' => new external_value(PARAM_INT, 'Parent id'),
                                'userid' => new external_value(PARAM_INT, 'User id'),
                                'created' => new external_value(PARAM_INT, 'Creation time'),
                                'modified' => new external_value(PARAM_INT, 'Time modified'),
                                'mailed' => new external_value(PARAM_INT, 'Mailed?'),
                                'subject' => new external_value(PARAM_TEXT, 'The post subject'),
                                'message' => new external_value(PARAM_RAW, 'The post message'),
                                'messageformat' => new external_value(PARAM_INT, 'The post message format'),
                                'messagetrust' => new external_value(PARAM_INT, 'Can we trust?'),
                                'attachment' => new external_value(PARAM_RAW, 'Attachments'),
                                'totalscore' => new external_value(PARAM_INT, 'The post message total score'),
                                'mailnow' => new external_value(PARAM_INT, 'Mail now?'),
                                'children' => new external_multiple_structure(new external_value(PARAM_INT, 'children post id')),
                                'canreply' => new external_value(PARAM_BOOL, 'The user can reply to posts?'),
                                'postread' => new external_value(PARAM_BOOL, 'The post was read'),
                                'userfullname' => new external_value(PARAM_TEXT, 'Post author full name')
                            ), 'post'
                        )
                    ),
                'warnings' => new external_warnings()
            )
        );
    }

    /**
     * Describes the parameters for get_forum_discussions_paginated.
     *
     * @return external_external_function_parameters
     * @since Moodle 2.8
     */
    public static function get_forum_discussions_paginated_parameters() {
        return new external_function_parameters (
            array(
                'forumid' => new external_value(PARAM_INT, 'hsuforum instance id', VALUE_REQUIRED),
                'sortby' => new external_value(PARAM_ALPHA,
                    'sort by this element: id, timemodified, timestart or timeend', VALUE_DEFAULT, 'timemodified'),
                'sortdirection' => new external_value(PARAM_ALPHA, 'sort direction: ASC or DESC', VALUE_DEFAULT, 'DESC'),
                'page' => new external_value(PARAM_INT, 'current page', VALUE_DEFAULT, -1),
                'perpage' => new external_value(PARAM_INT, 'items per page', VALUE_DEFAULT, 0),
            )
        );
    }

    /**
     * Returns a list of forum discussions optionally sorted and paginated.
     *
     * @param int $forumid the forum instance id
     * @param string $sortby sort by this element (id, timemodified, timestart or timeend)
     * @param string $sortdirection sort direction: ASC or DESC
     * @param int $page page number
     * @param int $perpage items per page
     *
     * @return array the forum discussion details including warnings
     * @since Moodle 2.8
     */
    public static function get_forum_discussions_paginated($forumid, $sortby = 'timemodified', $sortdirection = 'DESC',
                                                    $page = -1, $perpage = 0) {
        global $CFG, $DB, $USER, $PAGE;

        require_once($CFG->dirroot . "/mod/hsuforum/lib.php");

        $warnings = array();
        $discussions = array();

        $params = self::validate_parameters(self::get_forum_discussions_paginated_parameters(),
            array(
                'forumid' => $forumid,
                'sortby' => $sortby,
                'sortdirection' => $sortdirection,
                'page' => $page,
                'perpage' => $perpage
            )
        );

        // Compact/extract functions are not recommended.
        $forumid        = $params['forumid'];
        $sortby         = $params['sortby'];
        $sortdirection  = $params['sortdirection'];
        $page           = $params['page'];
        $perpage        = $params['perpage'];

        $sortallowedvalues = array('id', 'timemodified', 'timestart', 'timeend');
        if (!in_array($sortby, $sortallowedvalues)) {
            throw new invalid_parameter_exception('Invalid value for sortby parameter (value: ' . $sortby . '),' .
                'allowed values are: ' . implode(',', $sortallowedvalues));
        }

        $sortdirection = strtoupper($sortdirection);
        $directionallowedvalues = array('ASC', 'DESC');
        if (!in_array($sortdirection, $directionallowedvalues)) {
            throw new invalid_parameter_exception('Invalid value for sortdirection parameter (value: ' . $sortdirection . '),' .
                'allowed values are: ' . implode(',', $directionallowedvalues));
        }

        $forum = $DB->get_record('hsuforum', array('id' => $forumid), '*', MUST_EXIST);
        $course = $DB->get_record('course', array('id' => $forum->course), '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('hsuforum', $forum->id, $course->id, false, MUST_EXIST);

        // Validate the module context. It checks everything that affects the module visibility (including groupings, etc..).
        $modcontext = context_module::instance($cm->id);
        self::validate_context($modcontext);

        // Check they have the view forum capability.
        require_capability('mod/hsuforum:viewdiscussion', $modcontext, null, true, 'noviewdiscussionspermission', 'hsuforum');

        $sort = 'd.pinned DESC' . $sortby . ' ' . $sortdirection;
        $alldiscussions = hsuforum_get_discussions($cm, $sort, true, -1, -1, true, $page, $perpage, HSUFORUM_POSTS_ALL_USER_GROUPS);


        if ($alldiscussions) {
            $canviewfullname = has_capability('moodle/site:viewfullnames', $modcontext);

            // Get the unreads array, this takes a forum id and returns data for all discussions.
            $unreads = hsuforum_get_discussions_unread($cm);

            // The forum function returns the replies for all the discussions in a given forum.
            $replies = hsuforum_count_discussion_replies($forumid, $sort, -1, $page, $perpage);

            foreach ($alldiscussions as $discussion) {

                // This function checks for qanda forums.
                // Note that the hsuforum_get_discussions returns as id the post id, not the discussion id so we need to do this.
                $discussionrec = clone $discussion;
                $discussionrec->id = $discussion->discussion;
                if (!hsuforum_user_can_see_discussion($forum, $discussionrec, $modcontext)) {
                    $warning = array();
                    // Function hsuforum_get_discussions returns forum_posts ids not forum_discussions ones.
                    $warning['item'] = 'post';
                    $warning['itemid'] = $discussion->id;
                    $warning['warningcode'] = '1';
                    $warning['message'] = 'You can\'t see this discussion';
                    $warnings[] = $warning;
                    continue;
                }

                $discussion->numunread = 0;
                if (isset($unreads[$discussion->discussion])) {
                    $discussion->numunread = (int) $unreads[$discussion->discussion];
                }

                $discussion->numreplies = 0;
                if (!empty($replies[$discussion->discussion])) {
                    $discussion->numreplies = (int) $replies[$discussion->discussion]->replies;
                }

                $picturefields = explode(',', user_picture::fields());

                // Load user objects from the results of the query.
                $user = new stdclass();
                $user->id = $discussion->userid;
                $user = username_load_fields_from_object($user, $discussion, null, $picturefields);
                // Preserve the id, it can be modified by username_load_fields_from_object.
                $user->id = $discussion->userid;
                $discussion->userfullname = fullname($user, $canviewfullname);

                $userpicture = new user_picture($user);
                $userpicture->size = 1; // Size f1.
                $discussion->userpictureurl = $userpicture->get_url($PAGE)->out(false);

                $usermodified = new stdclass();
                $usermodified->id = $discussion->usermodified;
                $usermodified = username_load_fields_from_object($usermodified, $discussion, 'um', $picturefields);
                // Preserve the id (it can be overwritten due to the prefixed $picturefields).
                $usermodified->id = $discussion->usermodified;
                $discussion->usermodifiedfullname = fullname($usermodified, $canviewfullname);

                $userpicture = new user_picture($usermodified);
                $userpicture->size = 1; // Size f1.
                $discussion->usermodifiedpictureurl = $userpicture->get_url($PAGE)->out(false);

                // Rewrite embedded images URLs.
                list($discussion->message, $discussion->messageformat) =
                    external_format_text($discussion->message, $discussion->messageformat,
                                            $modcontext->id, 'mod_hsuforum', 'post', $discussion->id);

                // List attachments.
                if (!empty($discussion->attachment)) {
                    $discussion->attachments = array();

                    $fs = get_file_storage();
                    if ($files = $fs->get_area_files($modcontext->id, 'mod_hsuforum', 'attachment',
                                                        $discussion->id, "filename", false)) {
                        foreach ($files as $file) {
                            $filename = $file->get_filename();

                            $discussion->attachments[] = array(
                                'filename' => $filename,
                                'mimetype' => $file->get_mimetype(),
                                'fileurl'  => file_encode_url($CFG->wwwroot.'/webservice/pluginfile.php',
                                                '/'.$modcontext->id.'/mod_hsuforum/attachment/'.$discussion->id.'/'.$filename)
                            );
                        }
                    }
                }

                $discussions[] = $discussion;
            }
        }

        $result = array();
        $result['discussions'] = $discussions;
        $result['warnings'] = $warnings;
        return $result;

    }

    /**
     * Describes the get_forum_discussions_paginated return value.
     *
     * @return external_single_structure
     * @since Moodle 2.8
     */
    public static function get_forum_discussions_paginated_returns() {
        return new external_single_structure(
            array(
                'discussions' => new external_multiple_structure(
                        new external_single_structure(
                            array(
                                'id' => new external_value(PARAM_INT, 'Post id'),
                                'name' => new external_value(PARAM_TEXT, 'Discussion name'),
                                'groupid' => new external_value(PARAM_INT, 'Group id'),
                                'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                                'usermodified' => new external_value(PARAM_INT, 'The id of the user who last modified'),
                                'timestart' => new external_value(PARAM_INT, 'Time discussion can start'),
                                'timeend' => new external_value(PARAM_INT, 'Time discussion ends'),
                                'discussion' => new external_value(PARAM_INT, 'Discussion id'),
                                'parent' => new external_value(PARAM_INT, 'Parent id'),
                                'userid' => new external_value(PARAM_INT, 'User who started the discussion id'),
                                'created' => new external_value(PARAM_INT, 'Creation time'),
                                'modified' => new external_value(PARAM_INT, 'Time modified'),
                                'mailed' => new external_value(PARAM_INT, 'Mailed?'),
                                'subject' => new external_value(PARAM_TEXT, 'The post subject'),
                                'message' => new external_value(PARAM_RAW, 'The post message'),
                                'messageformat' => new external_format_value('message'),
                                'messagetrust' => new external_value(PARAM_INT, 'Can we trust?'),
                                'attachment' => new external_value(PARAM_RAW, 'Has attachments?'),
                                'attachments' => new external_multiple_structure(
                                    new external_single_structure(
                                        array (
                                            'filename' => new external_value(PARAM_FILE, 'file name'),
                                            'mimetype' => new external_value(PARAM_RAW, 'mime type'),
                                            'fileurl'  => new external_value(PARAM_URL, 'file download url')
                                        )
                                    ), 'attachments', VALUE_OPTIONAL
                                ),
                                'totalscore' => new external_value(PARAM_INT, 'The post message total score'),
                                'mailnow' => new external_value(PARAM_INT, 'Mail now?'),
                                'userfullname' => new external_value(PARAM_TEXT, 'Post author full name'),
                                'usermodifiedfullname' => new external_value(PARAM_TEXT, 'Post modifier full name'),
                                'userpictureurl' => new external_value(PARAM_URL, 'Post author picture.'),
                                'usermodifiedpictureurl' => new external_value(PARAM_URL, 'Post modifier picture.'),
                                'numreplies' => new external_value(PARAM_TEXT, 'The number of replies in the discussion'),
                                'numunread' => new external_value(PARAM_INT, 'The number of unread discussions.'),
                                'pinned' => new external_value(PARAM_BOOL, 'Is the discussion pinned')
                            ), 'post'
                        )
                    ),
                'warnings' => new external_warnings()
            )
        );
    }
    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 2.9
     */
    public static function view_forum_parameters() {
        return new external_function_parameters(
            array(
                'forumid' => new external_value(PARAM_INT, 'forum instance id')
            )
        );
    }

    /**
     * Trigger the course module viewed event and update the module completion status.
     *
     * @param int $forumid the forum instance id
     * @return array of warnings and status result
     * @since Moodle 2.9
     * @throws moodle_exception
     */
    public static function view_forum($forumid) {
        global $DB, $CFG;
        require_once($CFG->dirroot . "/mod/hsuforum/lib.php");

        $params = self::validate_parameters(self::view_forum_parameters(),
                                            array(
                                                'forumid' => $forumid
                                            ));
        $warnings = array();
        $discussions = array();

        // Request and permission validation.
        $forum = $DB->get_record('hsuforum', array('id' => $params['forumid']), 'id', MUST_EXIST);
        list($course, $cm) = get_course_and_cm_from_instance($forum, 'hsuforum');

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        require_capability('mod/hsuforum:viewdiscussion', $context, null, true, 'noviewdiscussionspermission', 'forum');

        // Call the hsuforum/lib API.
        hsuforum_view($forum, $course, $cm, $context);

        $result = array();
        $result['status'] = true;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 2.9
     */
    public static function view_forum_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_BOOL, 'status: true if success'),
                'warnings' => new external_warnings()
            )
        );
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 2.9
     */
    public static function view_forum_discussion_parameters() {
        return new external_function_parameters(
            array(
                'discussionid' => new external_value(PARAM_INT, 'discussion id')
            )
        );
    }

    /**
     * Trigger the discussion viewed event.
     *
     * @param int $discussionid the discussion id
     * @return array of warnings and status result
     * @since Moodle 2.9
     * @throws moodle_exception
     */
    public static function view_forum_discussion($discussionid) {
        global $DB, $CFG;
        require_once($CFG->dirroot . "/mod/hsuforum/lib.php");

        $params = self::validate_parameters(self::view_forum_discussion_parameters(),
                                            array(
                                                'discussionid' => $discussionid
                                            ));
        $warnings = array();

        $discussion = $DB->get_record('hsuforum_discussions', array('id' => $params['discussionid']), '*', MUST_EXIST);
        $forum = $DB->get_record('hsuforum', array('id' => $discussion->forum), '*', MUST_EXIST);
        list($course, $cm) = get_course_and_cm_from_instance($forum, 'hsuforum');

        // Validate the module context. It checks everything that affects the module visibility (including groupings, etc..).
        $modcontext = context_module::instance($cm->id);
        self::validate_context($modcontext);

        require_capability('mod/hsuforum:viewdiscussion', $modcontext, null, true, 'noviewdiscussionspermission', 'forum');

        // Call the hsuforum/lib API.
        hsuforum_discussion_view($modcontext, $forum, $discussion);

        $result = array();
        $result['status'] = true;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 2.9
     */
    public static function view_forum_discussion_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_BOOL, 'status: true if success'),
                'warnings' => new external_warnings()
            )
        );
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 3.0
     */
    public static function add_discussion_post_parameters() {
        return new external_function_parameters(
            array(
                'postid' => new external_value(PARAM_INT, 'the post id we are going to reply to
                                                (can be the initial discussion post'),
                'subject' => new external_value(PARAM_TEXT, 'new post subject'),
                'message' => new external_value(PARAM_RAW, 'new post message (only html format allowed)'),
                'draftid' => new external_value(PARAM_INT, 'The file upload draftid', VALUE_DEFAULT, 0),
                'attachment' => new external_value(PARAM_INT, 'attachment added', VALUE_DEFAULT, 0),
                'options' => new external_multiple_structure (
                    new external_single_structure(
                        array(
                            'name' => new external_value(PARAM_ALPHANUM,
                                        'The allowed keys (value format) are:
                                        discussionsubscribe (bool); subscribe to the discussion?, default to true
                            '),
                            'value' => new external_value(PARAM_RAW, 'the value of the option,
                                                            this param is validated in the external function.'
                        )
                    )
                ), 'Options', VALUE_DEFAULT, array())
            )
        );
    }

    /**
     * Create new posts into an existing discussion.
     *
     * @param int $postid the post id we are going to reply to
     * @param string $subject new post subject
     * @param string $message new post message (only html format allowed)
     * @param int $draftid mobile file draft id
     * @param array $options optional settings
     * @return array of warnings and the new post id
     * @since Moodle 3.0
     * @throws moodle_exception
     */
    public static function add_discussion_post($postid, $subject, $message, $draftid = 0, $attachment = 0, $options = array()) {
        global $DB, $CFG, $USER;
        require_once($CFG->dirroot . "/mod/hsuforum/lib.php");
        require_once($CFG->dirroot . "/mod/hsuforum/mobilelib.php");

        $params = self::validate_parameters(self::add_discussion_post_parameters(),
                                            array(
                                                'postid' => $postid,
                                                'subject' => $subject,
                                                'message' => $message,
                                                'draftid' => $draftid,
                                                'attachment' => $attachment,
                                                'options' => $options
                                            ));
        // Validate options.
        $options = array(
            'discussionsubscribe' => true
        );
        foreach ($params['options'] as $option) {
            $name = trim($option['name']);
            switch ($name) {
                case 'discussionsubscribe':
                    $value = clean_param($option['value'], PARAM_BOOL);
                    break;
                default:
                    throw new moodle_exception('errorinvalidparam', 'webservice', '', $name);
            }
            $options[$name] = $value;
        }

        $warnings = array();

        if (!$parent = hsuforum_get_post_full($params['postid'])) {
            throw new moodle_exception('invalidparentpostid', 'forum');
        }

        if (!$discussion = $DB->get_record("hsuforum_discussions", array("id" => $parent->discussion))) {
            throw new moodle_exception('notpartofdiscussion', 'forum');
        }

        // Request and permission validation.
        $forum = $DB->get_record('hsuforum', array('id' => $discussion->forum), '*', MUST_EXIST);
        list($course, $cm) = get_course_and_cm_from_instance($forum, 'hsuforum');

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        if (!hsuforum_user_can_post($forum, $discussion, $USER, $cm, $course, $context)) {
            throw new moodle_exception('nopostforum', 'forum');
        }

        $thresholdwarning = hsuforum_check_throttling($forum, $cm);
        hsuforum_check_blocking_threshold($thresholdwarning);

        // Create the post.
        $post = new stdClass();
        $post->discussion = $discussion->id;
        $post->parent = $parent->id;
        $post->subject = $params['subject'];
        $post->message = fixpostbodywithtaggedlinks($params['message']);
        $post->messageformat = FORMAT_HTML;   // Force formatting for now.
        $post->messagetrust = trusttext_trusted($context);
        $post->itemid = 0;
        $post->reveal = 0;
        $post->flags = 0;
        $post->privatereply = 0;
        $post->attachment = $attachment;
        $post->draftid = $draftid;

        if ($postid = hsuforum_add_new_post($post, null)) {

            $post->id = $postid;

            // Trigger events and completion.
            $params = array(
                'context' => $context,
                'objectid' => $post->id,
                'other' => array(
                    'discussionid' => $discussion->id,
                    'forumid' => $forum->id,
                    'forumtype' => $forum->type,
                )
            );
            $event = \mod_hsuforum\event\post_created::create($params);
            $event->add_record_snapshot('hsuforum_posts', $post);
            $event->add_record_snapshot('hsuforum_discussions', $discussion);
            $event->trigger();

            // Update completion state.
            $completion = new completion_info($course);
            if ($completion->is_enabled($cm) &&
                    ($forum->completionreplies || $forum->completionposts)) {
                $completion->update_state($cm, COMPLETION_COMPLETE);
            }

            $settings = new stdClass();
            $settings->discussionsubscribe = $options['discussionsubscribe'];
            hsuforum_post_subscription($settings, $forum, $discussion);
        } else {
            throw new moodle_exception('couldnotadd', 'forum');
        }

        $result = array();
        $result['postid'] = $postid;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.0
     */
    public static function add_discussion_post_returns() {
        return new external_single_structure(
            array(
                'postid' => new external_value(PARAM_INT, 'new post id'),
                'warnings' => new external_warnings()
            )
        );
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 3.0
     */
    public static function add_discussion_parameters() {
        return new external_function_parameters(
            array(
                'forumid' => new external_value(PARAM_INT, 'Forum instance ID'),
                'subject' => new external_value(PARAM_TEXT, 'New Discussion subject'),
                'message' => new external_value(PARAM_RAW, 'New Discussion message (only html format allowed)'),
                'groupid' => new external_value(PARAM_INT, 'The group, default to -1', VALUE_DEFAULT, -1),
                'draftid' => new external_value(PARAM_INT, 'The file upload draftid', VALUE_DEFAULT, 0),
                'attachment' => new external_value(PARAM_INT, 'attachment upload', VALUE_DEFAULT, 0),
                'options' => new external_multiple_structure (
                    new external_single_structure(
                        array(
                            'name' => new external_value(PARAM_ALPHANUM,
                                        'The allowed keys (value format) are:
                                        discussionsubscribe (bool); subscribe to the discussion?, default to true
                                        discussionpinned    (bool); is the discussion pinned, default to false
                            '),
                            'value' => new external_value(PARAM_RAW, 'The value of the option,
                                                            This param is validated in the external function.'
                        )
                    )
                ), 'Options', VALUE_DEFAULT, array())
            )
        );
    }

    /**
     * Add a new discussion into an existing forum.
     *
     * @param int $forumid the forum instance id
     * @param string $subject new discussion subject
     * @param string $message new discussion message (only html format allowed)
     * @param int $groupid the user course group
     * @param array $options optional settings
     * @return array of warnings and the new discussion id
     * @since Moodle 3.0
     * @throws moodle_exception
     */
    public static function add_discussion($forumid, $subject, $message, $groupid = -1, $draftid = 0, $attachment = 0, $options = array()) {
        global $DB, $CFG;
        require_once($CFG->dirroot . "/mod/hsuforum/lib.php");
        require_once($CFG->dirroot . "/mod/hsuforum/mobilelib.php");

        $params = self::validate_parameters(self::add_discussion_parameters(),
                                            array(
                                                'forumid' => $forumid,
                                                'subject' => $subject,
                                                'message' => $message,
                                                'groupid' => $groupid,
                                                'draftid' => $draftid,
                                                'attachment' => $attachment,
                                                'options' => $options
                                            ));
        // Validate options.
        $options = array(
            'discussionsubscribe' => true,
            'discussionpinned' => false
        );
        foreach ($params['options'] as $option) {
            $name = trim($option['name']);
            switch ($name) {
                case 'discussionsubscribe':
                    $value = clean_param($option['value'], PARAM_BOOL);
                    break;
                case 'discussionpinned':
                    $value = clean_param($option['value'], PARAM_BOOL);
                    break;
                default:
                    throw new moodle_exception('errorinvalidparam', 'webservice', '', $name);
            }
            $options[$name] = $value;
        }

        $warnings = array();

        // Request and permission validation.
        $forum = $DB->get_record('hsuforum', array('id' => $params['forumid']), '*', MUST_EXIST);
        list($course, $cm) = get_course_and_cm_from_instance($forum, 'hsuforum');

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        // Normalize group.
        if (!groups_get_activity_groupmode($cm)) {
            // Groups not supported, force to -1.
            $groupid = -1;
        } else {
            // Check if we receive an empty value for groupid,
            // in this case, get the group for the user in the activity.
            if (empty($params['groupid'])) {
                $groupid = groups_get_activity_group($cm);
            } else {
                // Here we rely in the group passed, hsuforum_user_can_post_discussion will validate the group.
                $groupid = $params['groupid'];
            }
        }

        if (!hsuforum_user_can_post_discussion($forum, $groupid, -1, $cm, $context)) {
            throw new moodle_exception('cannotcreatediscussion', 'forum');
        }

        $thresholdwarning = hsuforum_check_throttling($forum, $cm);
        hsuforum_check_blocking_threshold($thresholdwarning);

        // Create the discussion.
        $discussion = new stdClass();
        $discussion->course = $course->id;
        $discussion->forum = $forum->id;
        $discussion->message = fixpostbodywithtaggedlinks($params['message']);
        $discussion->messageformat = FORMAT_HTML;   // Force formatting for now.
        $discussion->messagetrust = trusttext_trusted($context);
        $discussion->itemid = 0;
        $discussion->groupid = $groupid;
        $discussion->mailnow = 0;
        $discussion->subject = $params['subject'];
        $discussion->name = $discussion->subject;
        $discussion->timestart = 0;
        $discussion->timeend = 0;
        $discussion->reveal = 0;
        $discussion->attachment = $attachment;
        $discussion->mobiledraftid = $draftid;

        if (has_capability('mod/hsuforum:pindiscussions', $context) && $options['discussionpinned']) {
            $discussion->pinned = HSUFORUM_DISCUSSION_PINNED;
        } else {
            $discussion->pinned = HSUFORUM_DISCUSSION_UNPINNED;
        }

        if ($discussionid = hsuforum_add_discussion($discussion)) {

            $discussion->id = $discussionid;

            // Trigger events and completion.

            $params = array(
                'context' => $context,
                'objectid' => $discussion->id,
                'other' => array(
                    'forumid' => $forum->id,
                )
            );
            $event = \mod_hsuforum\event\discussion_created::create($params);
            $event->add_record_snapshot('hsuforum_discussions', $discussion);
            $event->trigger();

            $completion = new completion_info($course);
            if ($completion->is_enabled($cm) &&
                    ($forum->completiondiscussions || $forum->completionposts)) {
                $completion->update_state($cm, COMPLETION_COMPLETE);
            }

            $settings = new stdClass();
            $settings->discussionsubscribe = $options['discussionsubscribe'];
            hsuforum_post_subscription($settings, $forum, $discussion);
        } else {
            throw new moodle_exception('couldnotadd', 'forum');
        }

        $result = array();
        $result['discussionid'] = $discussionid;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.0
     */
    public static function add_discussion_returns() {
        return new external_single_structure(
            array(
                'discussionid' => new external_value(PARAM_INT, 'New Discussion ID'),
                'warnings' => new external_warnings()
            )
        );
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 3.0
     */
    public static function like_post_parameters() {
        return new external_function_parameters(
            array(
                'postid' => new external_value(PARAM_INT, 'Post ID'),
            )
        );
    }

    /**
     * Like a post in a forum
     *
     * @param int $postid the post id
     * @return array new like id
     * @throws moodle_exception
     */
    public static function like_post($postid) {
        global $DB, $USER, $CFG;
        require_once($CFG->dirroot . "/mod/hsuforum/lib.php");
        require_once($CFG->dirroot . "/mod/hsuforum/mobilelib.php");

        $params = self::validate_parameters(self::like_post_parameters(), array('postid'=>$postid));
        $transaction = $DB->start_delegated_transaction();

        try {
            if (!userlikedpost($params['postid'], $USER->id)) {
                $like = new \stdClass();
                $like->postid = $params['postid'];
                $like->userid = $USER->id;
                $like->action = "like";
                $like->created = time();
                $DB->insert_record('hsuforum_actions', $like);
            } else {
                $DB->delete_records('hsuforum_actions', array('postid' => $params['postid'], 'userid' => $USER->id));
            }
        } catch (Exception $e) {
            throw new coding_exception($e->getMessage());
        }

        $transaction->allow_commit();

        $result = array();
        $result['likeid'] = $like->id;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.0
     */
    public static function like_post_returns() {
        return new external_single_structure(
            array(
                'likeid' => new external_value(PARAM_INT, 'Like action id'),
            )
        );
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 3.0
     */
    public static function toggle_discussion_subscription_parameters() {
        return new external_function_parameters(
            array(
                'discussionid' => new external_value(PARAM_INT, 'Discussion ID'),
            )
        );
    }

    /**
     * Subscribe to a discussion in a forum
     *
     * @param int $discussionid the discussion id
     * @return array new subscription id
     * @throws moodle_exception
     */
    public static function toggle_discussion_subscription($discussionid) {
        global $DB, $CFG, $USER;
        require_once($CFG->dirroot . "/mod/hsuforum/lib.php");
        require_once($CFG->dirroot . "/mod/hsuforum/mobilelib.php");

        $params = self::validate_parameters(self::toggle_discussion_subscription_parameters(), array('discussionid'=>$discussionid));
        $transaction = $DB->start_delegated_transaction();

        try {
            if (!user_subscribed($params['discussionid'], $USER->id)) {
                $subscribtion = new \stdClass();
                $subscribtion->discussion = $params['discussionid'];
                $subscribtion->userid = $USER->id;
                $DB->insert_record('hsuforum_subscriptions_disc', $subscribtion);
            } else {
                $DB->delete_records('hsuforum_subscriptions_disc', array('discussion' => $params['discussionid'], 'userid' => $USER->id));
            }
        } catch (Exception $e) {
            throw new coding_exception($e->getMessage());
        }

        $transaction->allow_commit();

        $result = array();
        $result['subscriptionid'] = $subscribtion->id;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.0
     */
    public static function toggle_discussion_subscription_returns() {
        return new external_single_structure(
            array(
                'subscriptionid' => new external_value(PARAM_INT, 'subscription id'),
            )
        );
    }

}
