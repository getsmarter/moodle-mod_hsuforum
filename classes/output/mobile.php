<?php
namespace mod_hsuforum\output;
 
defined('MOODLE_INTERNAL') || die();
require_once(dirname(dirname(__DIR__)).'/lib.php');
require_once(dirname(dirname(__DIR__)).'/mobilelib.php');

use context_module;
use moodle_url;
use local_mention_users_observer;
/**
 * The mod_hsuforum mobile app compatibility.
 *
 * @package	mod_hsuforum
 * @copyright  2018 GetSmarter {@link http://www.getsmarter.co.za}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {

    /**
     * Returns the hsuforum discussion view for a given forum.
     * Note use as much logic and functions from view.php as possible (view.php uses renderer.php and lib.php to build view)
     * @param  array $args Arguments from tool_mobile_get_content WS
     * @return array HTML, javascript and otherdata
     */
    public static function forum_discussions_view($args) {
        global $OUTPUT, $USER, $DB, $PAGE;

        $args    = (object) $args;
        $cm      = get_coursemodule_from_id('hsuforum', $args->cmid);
        $forum   = $DB->get_record('hsuforum', array('id' => $cm->instance));
        $context = context_module::instance($cm->id);
        $course  = $DB->get_record('course', array('id' => $cm->course));
        $courseroleassignments = hsuforum_get_course_roles_and_assignments($course->id);

    /// Basic Validation checks
        /** Checks for valid course module
         * @TODO
         * See how to redirect or throw friendly errors in app (popups)
         */
        if (empty($cm) && !$cm = get_coursemodule_from_instance("hsuforum", $forum->id, $course->id)) {
            print_error('missingparameter');
        }

    /// Group permission logic
        $showgroupsections      = false;
        $allowedgroups          = false;
        $currentgroup           = groups_get_activity_group($cm);
        $groupmode              = groups_get_activity_groupmode($cm, $course);
        $canstart               = hsuforum_user_can_post_discussion($forum, $currentgroup, $groupmode, $cm, $context);
        $allgroups              = groups_get_all_groups($cm->course, 0, $cm->groupingid);
        $allowedgroups          = groups_get_activity_allowed_groups($cm, $USER->id);

        if (count($allowedgroups) && (int) $cm->groupmode > 0) {
            $showgroupsections = true;
        }

    /// Get all the recent discussions we're allowed to see
        /**
         * @TODO
         * Get pagination working when required
         * Reference lib.php line :5399 to implement when required
         * UX requirements need to be defined in terms of pagination and limits on the page (infinite scroll maybe?)
         */
        $sortorder = hsuforum_get_default_sort_order();
        $getuserlastmodified = true;
        $fullpost = true;
        $maxdiscussions = -1;
        $page = -1;
        $perpage = 20;
        $discussions = false;

        try {
            $discussions = hsuforum_get_discussions($cm, $sortorder, $fullpost, null, $maxdiscussions, $getuserlastmodified, $page, $perpage, HSUFORUM_POSTS_ALL_USER_GROUPS, false);
        } catch (Exception $e) {
            // @TODO handle exceptions properly in the app context
            print_r($e->getMessage());
        }

        // Get user profile pictures for a discussion and group names
        if ($discussions) {
            foreach ($discussions as $discussion) {
                $postuser = false;
                $discussion->profilesrc = false;
                $discussion->groupname = false;
                // Getting group names
                if ($allgroups && array_key_exists($discussion->groupid, $allgroups)) {
                    $discussion->groupname = $allgroups[$discussion->groupid]->name;
                } elseif ($discussion->groupid == -1) {
                    $discussion->groupname = 'All groups';
                }
                // Getting user picture
                $postuser = hsuforum_extract_postuser($discussion, $forum, context_module::instance($cm->id));
                if ($postuser) {
                    $postuser->user_picture->size = 100;
                    $discussion->profilesrc = $postuser->user_picture->get_url($PAGE)->out();
                    $discussion->postuserid = $postuser->id;
                }
                // Getting footer stats
                $stats = get_discussion_footer_stats($discussion, $forum->id);
                $discussion->views = $stats['views'];
                $discussion->contribs = $stats['contribs'];
                $discussion->createdfiltered = $stats['createdfiltered'];
                $discussion->latestpost = strlen($stats['latestpost']) ? $stats['latestpost'] : false;

                // Getting role colors
                switch (true) {
                    case in_array($postuser->id, $courseroleassignments['htutor']):
                    case in_array($postuser->id, $courseroleassignments['tutor']):
                    case in_array($postuser->id, $courseroleassignments['gsmanager']):
                        $discussion->rolecolor = '#333';
                        break;
                    case in_array($postuser->id, $courseroleassignments['smanager']):
                        $discussion->rolecolor = '#f42684';
                        break;
                    case (in_array($postuser->id, $courseroleassignments['student'])) && ($postuser->id == $USER->id):
                        $discussion->rolecolor = '#bbb';
                        break;
                    default:
                        $discussion->rolecolor = false;
                        break;
                }

                // Setting discussion labels/strings
                $discussion->viewslabel = ($stats['views'] == 0) || ($stats['views'] > 1) ? get_string('views', 'hsuforum') : get_string('view', 'hsuforum');
                $discussion->contribslabel = ($stats['contribs'] == 0) || ($stats['contribs'] > 1) ? get_string('contributors', 'hsuforum') : get_string('contributor', 'hsuforum');
                $discussion->subscribedlabel = $discussion->subscriptionid ? get_string('toggled:subscribe', 'hsuforum') : get_string('toggle:subscribe', 'hsuforum');
                $discussion->replylabel = ($discussion->replies == 0) || ($discussion->replies > 1) ? get_string('replies', 'hsuforum') : get_string('reply', 'hsuforum');
            }
        }

        /// Setting additional labels
        // @todo - convert additional lables to an array then pass to context var if we get to many labels
        $discussionlabel = count($discussions) >= 2 || count($discussions) == 0 ? get_string('discussions', 'hsuforum') : get_string('discussion', 'hsuforum');
        $unreadlabel = get_string('unread', 'hsuforum');
        $postedbylabel = get_string('postedby', 'hsuforum');

        // Build data array to output in the template
        $data = array(
            'cmid' => $cm->id,
            'cmname' => $cm->name,
            'canstart' => $canstart,
            'courseid' => $course->id,
            'unreadlabel' => $unreadlabel,
            'discussionlabel' => $discussionlabel,
            'postedbylabel' => $postedbylabel,
            'discussioncount' => count($discussions),
            'showgroupsections' => $showgroupsections,
        );

        return array(
            'templates' => array(
                array(
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_hsuforum/mobile_view_discussions', $data),
                ),
            ),
            'javascript' => '',
            'otherdata' => array(
                'allowedgroups' => json_encode($allowedgroups),
                'discussions' => json_encode(array_values($discussions)),
                'forum' => json_encode($forum),
            ),
            'files' => ''
        );
    }

    /**
     * Renders firstpost entry in the header and replies for a given discussion in a forum.
     * Note use as much logic and functions from discuss.php as possible
     * @param array $args Arguments from tool_mobile_get_content WS
     * @return array HTML, javascript and otherdata
     */
    public static function view_discussion($args) {
        global $OUTPUT, $USER, $DB, $PAGE, $CFG;

        // Check for valid discussion id
        if (!$args || !isset($args['discussionid'])) {
            throw new coding_exception('No discussion id provided');
        }

        $discussion            = $DB->get_record('hsuforum_discussions', array('id' => $args['discussionid']), '*', MUST_EXIST);
        $course                = $DB->get_record('course', array('id' => $discussion->course), '*', MUST_EXIST);
        $forum                 = $DB->get_record('hsuforum', array('id' => $discussion->forum), '*', MUST_EXIST);
        $cm                    = get_coursemodule_from_instance('hsuforum', $forum->id, $course->id, false, MUST_EXIST);
        $modcontext            = context_module::instance($cm->id);
        $canreply              = hsuforum_user_can_post($forum, $discussion, $USER, $cm, $course, $modcontext);
        $courseroleassignments = hsuforum_get_course_roles_and_assignments($course->id);
        $unreadpostids         = [];
        $attachmentclass       = new \mod_hsuforum\attachments($forum, $modcontext);

    /// Getting firstpost and root replies for the firstpost
        // Note there can only be one post(when user created discussion) in a discussion and then additional posts are regarged as replies(api data structure reflects this concept). Very confusing...
            // Note that a reply(post) can have children which are replies on a reply essentially.
        $firstpost = false;
        $replies = false;

        $firstpostcondition = array('p.id' => $discussion->firstpost);
        $firstpostresult = hsuforum_get_all_discussion_posts($discussion->id, $firstpostcondition, $USER->id);

        // Populating firstpost with virtual props needed for template
        if ($firstpostresult) {
            $firstpost = array_pop($firstpostresult);

            // Getting all nested unread ids for root post in discussion
            $readpostids = hsuforum_get_unread_nested_postids($discussion->id, $firstpost->id, $USER->id);

            // Avatar section
            $postuser = hsuforum_extract_postuser($firstpost, $forum, context_module::instance($cm->id));
            $postuser->user_picture->size = 100;
            $firstpost->profilesrc = $postuser->user_picture->get_url($PAGE)->out();
            $firstpost->postuserid = $postuser->id;

            // Like section
            $firstpost->likes = array_values(getpostlikes($firstpost));
            $firstpost->likecount = count($firstpost->likes);
            if ($firstpost->likecount) {
                $firstpost->likedescription = getlikedescription($firstpost->likes);
            }

            // Getting firstpost ribbon stats
            $stats = get_discussion_banner_stats($discussion, $firstpost, $forum->id);
            $firstpost->replies = $stats['replies'];
            $firstpost->views = $stats['views'];
            $firstpost->createdfiltered = strlen($stats['createdfiltered']) ? $stats['createdfiltered'] : false;
            $firstpost->latestpost = strlen($stats['latestpost']) ? $stats['latestpost'] : false;
            $firstpost->contribs = $stats['contribs'];

            // Set ribbon labels
            $firstpost->viewslabel = ($stats['views'] == 0) || ($stats['views'] > 1) ? get_string('views', 'hsuforum') : get_string('view', 'hsuforum');
            $firstpost->contribslabel = ($stats['contribs'] == 0) || ($stats['contribs'] > 1) ? get_string('contributors', 'hsuforum') : get_string('contributor', 'hsuforum');
            $firstpost->replylabel = ($firstpost->replies == 0) || ($firstpost->replies > 1) ? get_string('replies', 'hsuforum') : get_string('reply', 'hsuforum');
            $firstpost->likelabel = userlikedpost($firstpost->id, $USER->id) ? get_string('unlike', 'hsuforum') : get_string('like', 'hsuforum');

            // Getting attachments files
            $filesraw = $attachmentclass->get_attachments($firstpost->id);
            $firstpost->files = [];
            foreach ($filesraw as $file) {
                $fileobj = new \stdClass;
                $fileobj->id = $file->get_itemid();
                $fileobj->filename = $file->get_filename();
                $fileobj->filepath = $file->get_filepath();
                $fileobj->fileurl = moodle_url::make_pluginfile_url(
                    $modcontext->id, 'mod_hsuforum', "attachment", $fileobj->id, '/', $fileobj->filename)->out(false);
                $fileobj->filesize = $file->get_filesize();
                $fileobj->timemodified = $file->get_timemodified();
                $fileobj->mimetype = $file->get_mimetype();
                $fileobj->isexternalfile = $file->get_repository_type();
    
                array_push($firstpost->files, $fileobj);
            }
        }

        $repliesparams = array('p.parent' => $discussion->firstpost);
        $replies = hsuforum_get_all_discussion_posts($discussion->id, $repliesparams);

        // Populating replies with virtual props needed for template
        foreach ($replies as &$reply) {
            // Avatar section
            $postuser = hsuforum_extract_postuser($reply, $forum, context_module::instance($cm->id));
            $postuser->user_picture->size = 100;
            $reply->profilesrc = $postuser->user_picture->get_url($PAGE)->out();
            $reply->postuserid = $postuser->id;

            // Like section
            $reply->likes = array_values(getpostlikes($reply));
            $reply->likecount = count($reply->likes);
            if ($reply->likecount) {
                $reply->likedescription = getlikedescription($reply->likes);
            }
            $reply->created = hsuforum_relative_time($reply->created);
            $reply->likelabel = userlikedpost($reply->id, $USER->id) ? get_string('unlike', 'hsuforum') : get_string('like', 'hsuforum');
            $reply->textareaid = "textarea_id".$reply->id;
            $reply->postformid = "postform_id".$reply->id;

            // Blank reply post section
            $reply->replybody = ' ';

            // Check for unread reply posts and updating the unreadpostids array
            if (!in_array($reply->id, $readpostids)) {
                $reply->unread = true;
                array_push($unreadpostids, $reply->id);
            }

            // Getting role colors
            switch (true) {
                case in_array($postuser->id, $courseroleassignments['htutor']):
                case in_array($postuser->id, $courseroleassignments['tutor']):
                case in_array($postuser->id, $courseroleassignments['gsmanager']):
                    $reply->rolecolor = '#333';
                    break;
                case in_array($postuser->id, $courseroleassignments['smanager']):
                    $reply->rolecolor = '#f42684';
                    break;
                case (in_array($postuser->id, $courseroleassignments['student'])) && ($postuser->id == $USER->id):
                    $reply->rolecolor = '#bbb';
                    break;
                default:
                    $reply->rolecolor = false;
                    break;
            }

            // Getting attachments files
            $filesraw = $attachmentclass->get_attachments($reply->id);
            $reply->files = [];
            foreach ($filesraw as $file) {
                $fileobj = new \stdClass;
                $fileobj->id = $file->get_itemid();
                $fileobj->filename = $file->get_filename();
                $fileobj->filepath = $file->get_filepath();
                $fileobj->fileurl = moodle_url::make_pluginfile_url(
                    $modcontext->id, 'mod_hsuforum', "attachment", $fileobj->id, '/', $fileobj->filename)->out(false);
                $fileobj->filesize = $file->get_filesize();
                $fileobj->timemodified = $file->get_timemodified();
                $fileobj->mimetype = $file->get_mimetype();
                $fileobj->isexternalfile = $file->get_repository_type();
    
                array_push($reply->files, $fileobj);
            }

            // Check for nested replies
            $reply->havereplies = hsuforum_count_replies($reply, $children=true);
        }

    /// Getting tagable users
        $tagusers = [];
        $tagusers = get_allowed_tag_users($forum->id, $discussion->groupid, 1);
        $tagusers = ($tagusers->result && count($tagusers->content)) ? build_allowed_tag_users($tagusers->content) : [];
        $showtaguserul = count($tagusers) ? true : false;

    /// Getting javascript file for injection
        $tagusersfile = $CFG->dirroot . '/mod/hsuforum/mention_users.js';
        $handle = fopen($tagusersfile, "r");
        $tagusersjs = fread($handle, filesize($tagusersfile));
        fclose($handle);

    /// Setting additional labels
        // @todo - convert additional lables to an array then pass to context var if we get to many labels
        $replylabel = count($replies) >= 2 || count($replies) == 0 ? get_string('replies', 'hsuforum') : get_string('reply', 'hsuforum');
        $replyfromlabel = get_string('replyfrom', 'hsuforum');
        $unreadlabel = get_string('unread', 'hsuforum');

    /// Handling Events
        hsuforum_discussion_view($modcontext, $forum, $discussion);

    /// Marking unread posts as read
        // Root post
        hsuforum_tp_add_read_record($USER->id, $firstpost->id);
        // Nested replies on parent
        if (count($unreadpostids)) {
            hsuforum_mark_posts_read($USER, $unreadpostids);
        }

        $data = array(
            'courseid'       => $course->id,
            'cmid'           => $cm->id,
            'discussionid'   => $discussion->id,
            'discussionname' => $discussion->name,
            'replycount'     => count($replies),
            'replylabel'     => $replylabel,
            'replyfromlabel' => $replyfromlabel,
            'unreadlabel'    => $unreadlabel,
            'firstpost'      => $firstpost,
            'canreply'       => $cm->groupmode == 0 ? true : $canreply,
            'showtaguserul'  => $showtaguserul,
            'tagusers'       => $tagusers,
        );

        return array(
            'templates' => array(
                array(
                    'id'   => 'main',
                    'html' => $OUTPUT->render_from_template('mod_hsuforum/mobile_view_discussion_posts', $data),
                ),
            ),
            'javascript'        => $tagusersjs,
            'otherdata'         => array(
                'replies'       => json_encode(array_values($replies)),
                'firstpost'     => json_encode($firstpost),
                'sectionbody'   => '',
            ),
            'files' => ''
        );
    }

    /**
     * Handle post discussion forms
     * @param array $args Arguments from tool_mobile_get_content WS
     * @return array HTML, javascript and otherdata
     */
    public static function add_discussion($args) {
        global $OUTPUT, $USER, $DB, $CFG;

        $cm                = get_coursemodule_from_id('hsuforum', $args['cmid']);
        $modcontext        = context_module::instance($cm->id);
        $forum             = $DB->get_record('hsuforum', array('id' => $cm->instance));
        $allowedgroups     = array_values(groups_get_activity_allowed_groups($cm));
        $allgroups         = groups_get_all_groups($cm->course, 0, $cm->groupingid);
        $showgroupsections = false;

        // Check user group permissions and build allowed group arr for select box.
        // Visible or separate group
        if ((int) $cm->groupmode > 0) {
            $groupstopostto = [];
            foreach ($allgroups as $groupid => $group) {
                if (hsuforum_user_can_post_discussion($forum, $groupid, -1, $cm, $modcontext)) {
                    $groupstopostto[] = $group;
                }
            }
            // Check if user can post to all groups
            if (count($groupstopostto) === count($allgroups)) {
                $all_participants = new \stdClass;
                $all_participants->id = '-1';
                $all_participants->name = 'All Participants';
                array_unshift($groupstopostto, $all_participants);
            }
            // Replace original allowed groups with filtered one based on permissions
            $allowedgroups = $groupstopostto;
        // No group will post to all groups
        } else {
            $allowedgroups = [];
        }

        // Check if we will show the select box on template
        $showgroupsections = count($allowedgroups) ? true : false;

        // Getting tagable users
        $tagusers = [];
        $tagusers = get_allowed_tag_users($forum->id, $discussion->groupid, 1);
        $tagusers = ($tagusers->result && count($tagusers->content)) ? build_allowed_tag_users($tagusers->content) : [];
        $showtaguserul = count($tagusers) ? true : false;

        // Getting javascript file for injection
        $tagusersfile = $CFG->dirroot . '/mod/hsuforum/mention_users.js';
        $handle = fopen($tagusersfile, "r");
        $tagusersjs = fread($handle, filesize($tagusersfile));
        fclose($handle);

        return array(
            'templates' => array(
                array(
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_hsuforum/mobile_add_discussion', array(
                        'cmid' => $args['cmid'], 
                        'showgroupsections' => $showgroupsections,
                        'showtaguserul'     => $showtaguserul,
                        'tagusers'          => $tagusers,
                        'forumid'           => $forum->id,
                        )
                    ),
                ),
            ),
            'javascript' => $tagusersjs,
            'otherdata' => array(
                'groupsections' => json_encode($allowedgroups),
                'groupselection' => (is_array($allowedgroups) && count($allowedgroups)) ? $allowedgroups[0]->id : -1,
                'discussiontitle' => '',
            ),
            'files' => ''
        );
    }

    /**
     * Renders a given post replies
     * @param array $args Arguments from tool_mobile_get_content WS
     * @return array HTML, javascript and otherdata
     */
    public static function view_post_replies($args) {
        global $OUTPUT, $USER, $DB, $PAGE, $CFG;

        // Check for valid discussion id
        if (!$args || !isset($args['postid'])) {
            throw new coding_exception('No post id provided');
        }

        $postid                = $args['postid'];
        $discussion            = $DB->get_record('hsuforum_discussions', array('id' => $args['discussionid']), '*', MUST_EXIST);
        $course                = $DB->get_record('course', array('id' => $discussion->course), '*', MUST_EXIST);
        $forum                 = $DB->get_record('hsuforum', array('id' => $discussion->forum), '*', MUST_EXIST);
        $cm                    = get_coursemodule_from_instance('hsuforum', $forum->id, $course->id, false, MUST_EXIST);
        $modcontext            = context_module::instance($cm->id);
        $canreply              = hsuforum_user_can_post($forum, $discussion, $USER, $cm, $course, $modcontext);
        $courseroleassignments = hsuforum_get_course_roles_and_assignments($course->id);
        $havechildren          = isset($args['havechildren']) ? $args['havechildren'] : false;
        $unreadpostids         = [];

    /// Getting all nested unread ids for root post in discussion
        $readpostids = hsuforum_get_unread_nested_postids($discussion->id, $postid, $USER->id);

    /// Getting replies for the post
        $repliesparams = array('p.parent' => $postid);
        $replies = [];

    /// Build replies structure where posts deeper that second level will be nested as children in the secondlevelpost
        if ($havechildren > 0) {
            // @TODO - we can flatten this array at some point to facilitate for pagination
            $filteredchildrenidarr = hsuforum_get_discussion_post_hierarchy($discussion->id);

            if (isset($filteredchildrenidarr[$discussion->firstpost][$postid]["secondlevelposts"])) {
                $secondlevelposts = $filteredchildrenidarr[$discussion->firstpost][$postid]["secondlevelposts"];
                foreach ($secondlevelposts as $post) {
                    $replies[] = hsuforum_get_post_full($post['id']);
                    if (count($post['children'])) {
                        foreach ($post['children'] as $child) {
                            if ($childpost = hsuforum_get_post_full($child['id'])) {
                                $childpost->depth = $child['depth'];
                                $replies[] = $childpost;
                            }
                        }
                    }
                }
            }
        } else {
            $replies = hsuforum_get_all_discussion_posts($discussion->id, $repliesparams);
        }

    /// Populating replies with virtual props needed for template
        foreach ($replies as &$reply) {
            // Avatar section
            $postuser = hsuforum_extract_postuser($reply, $forum, context_module::instance($cm->id));
            $postuser->user_picture->size = 100;
            $reply->profilesrc = $postuser->user_picture->get_url($PAGE)->out();
            $reply->postuserid = $postuser->id;

            // Like section
            $reply->likes = array_values(getpostlikes($reply));
            $reply->likecount = count($reply->likes);
            if ($reply->likecount) {
                $reply->likedescription = getlikedescription($reply->likes);
            }
            $reply->created = hsuforum_relative_time($reply->created);
            $reply->likelabel = userlikedpost($reply->id, $USER->id) ? get_string('unlike', 'hsuforum') : get_string('like', 'hsuforum');
            $reply->textareaid = "textarea_id".$reply->id;
            $reply->postformid = "postform_id".$reply->id;

            // Blank reply post section
            $reply->replybody = ' ';

            // Check for unread reply posts and updating the unreadpostids array
            if (!in_array($reply->id, $readpostids)) {
                $reply->unread = true;
                array_push($unreadpostids, $reply->id);
            }

            // Getting role colors
            switch (true) {
                case in_array($postuser->id, $courseroleassignments['htutor']):
                case in_array($postuser->id, $courseroleassignments['tutor']):
                case in_array($postuser->id, $courseroleassignments['gsmanager']):
                    $reply->rolecolor = '#333';
                    break;
                case in_array($postuser->id, $courseroleassignments['smanager']):
                    $reply->rolecolor = '#f42684';
                    break;
                case (in_array($postuser->id, $courseroleassignments['student'])) && ($postuser->id == $USER->id):
                    $reply->rolecolor = '#bbb';
                    break;
                default:
                    $reply->rolecolor = false;
                    break;
            }
        }

    /// Getting tagable users
        $tagusers = [];
        $tagusers = get_allowed_tag_users($forum->id, $discussion->groupid, 1);
        $tagusers = ($tagusers->result && count($tagusers->content)) ? build_allowed_tag_users($tagusers->content) : [];
        $showtaguserul = count($tagusers) ? true : false;

    /// Getting javascript file for injection
        $tagusersfile = $CFG->dirroot . '/mod/hsuforum/mention_users.js';
        $handle = fopen($tagusersfile, "r");
        $tagusersjs = fread($handle, filesize($tagusersfile));
        fclose($handle);

    /// Setting additional labels
        // @todo - convert additional lables to an array then pass to context var if we get to many labels
        $replylabel = count($replies) >= 2 || count($replies) == 0 ? get_string('replies', 'hsuforum') : get_string('reply', 'hsuforum');
        $replyfromlabel = get_string('replyfrom', 'hsuforum');
        $unreadlabel = get_string('unread', 'hsuforum');

    /// Marking unread posts as read
        hsuforum_tp_add_read_record($USER->id, $postid);
        if (count($unreadpostids)) {
            hsuforum_mark_posts_read($USER, $unreadpostids);
        }

        $data = array(
            'courseid'       => $course->id,
            'cmid'           => $cm->id,
            'discussionid'   => $discussion->id,
            'replycount'     => count($replies),
            'replylabel'     => $replylabel,
            'replyfromlabel' => $replyfromlabel,
            'unreadlabel'    => $unreadlabel,
            'canreply'       => $cm->groupmode == 0 ? true : $canreply,
            'showtaguserul'  => $showtaguserul,
            'tagusers'       => $tagusers,
        );

        return array(
            'templates' => array(
                array(
                    'id'   => 'main',
                    'html' => $OUTPUT->render_from_template('mod_hsuforum/mobile_view_post_replies', $data),
                ),
            ),
            'javascript'        => $tagusersjs,
            'otherdata'         => array(
                'replies'       => json_encode(array_values($replies)),
                'sectionbody'   => '',
            ),
            'files' => ''
        );
    }
}
