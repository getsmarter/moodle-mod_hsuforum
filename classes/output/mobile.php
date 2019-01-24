<?php
namespace mod_hsuforum\output;
 
defined('MOODLE_INTERNAL') || die();
require_once(dirname(dirname(__DIR__)).'/lib.php');
require_once(dirname(dirname(__DIR__)).'/mobilelib.php');

use context_module;
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
                }
            }
        }

        // Build data array to output in the template
        $data = array(
            'cmid' => $cm->id,
            'cmname' => $cm->name,
            'discussioncount' => count($discussions),
            'discussionlabel' => count($discussions) >= 2 || count($discussions) == 0 ? 'discussions' : 'discussion',
            'showgroupsections' => $showgroupsections,
            'canstart' => $canstart,
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

        $discussion = $DB->get_record('hsuforum_discussions', array('id' => $args['discussionid']), '*', MUST_EXIST);
        $course     = $DB->get_record('course', array('id' => $discussion->course), '*', MUST_EXIST);
        $forum      = $DB->get_record('hsuforum', array('id' => $discussion->forum), '*', MUST_EXIST);
        $cm         = get_coursemodule_from_instance('hsuforum', $forum->id, $course->id, false, MUST_EXIST);
        $modcontext = context_module::instance($cm->id);
        $canreply   = hsuforum_user_can_post($forum, $discussion, $USER, $cm, $course, $modcontext);
        $postreplystatus = [];

    /// Getting firstpost and root replies for the firstpost
        // Note there can only be one post(when user created discussion) in a discussion and then additional posts are regarged as replies(api data structure reflects this concept). Very confusing...
            // Note that a reply(post) can have children which are replies on a reply essentially.
        $firstpost = false;
        $replies = false;

        $firstpostcondition = array('p.id' => $discussion->firstpost);
        $firstpostresult = hsuforum_get_all_discussion_posts($discussion->id, $firstpostcondition);

        // Populating firstpost with virtual props needed for template
        if ($firstpostresult) {
            // Avatar section
            $firstpost = array_pop($firstpostresult);
            $postuser = hsuforum_extract_postuser($firstpost, $forum, context_module::instance($cm->id));
            $postuser->user_picture->size = 100;
            $firstpost->profilesrc = $postuser->user_picture->get_url($PAGE)->out();
            // Like section
            $firstpost->likes = array_values(getpostlikes($firstpost));
            $firstpost->likecount = count($firstpost->likes);
            if ($firstpost->likecount) {
                $firstpost->likedescription = getlikedescription($firstpost->likes);
            }
            $firstpost->firstpostliked = userlikedpost($firstpost->id, $USER->id) ? 'Unlike' : 'Like';
        }

        $repliescondition = array('p.parent' => $discussion->firstpost);
        $replies = hsuforum_get_all_discussion_posts($discussion->id, $repliescondition);

        // Populating replies with virtual props needed for template
        foreach ($replies as &$reply) {
            // Avatar section
            $postuser = hsuforum_extract_postuser($reply, $forum, context_module::instance($cm->id));
            $postuser->user_picture->size = 100;
            $reply->profilesrc = $postuser->user_picture->get_url($PAGE)->out();
            // Like section
            $reply->likes = array_values(getpostlikes($reply));
            $reply->likecount = count($reply->likes);
            if ($reply->likecount) {
                $reply->likedescription = getlikedescription($reply->likes);
            }
            $reply->liked = userlikedpost($reply->id, $USER->id) ? 'Unlike' : 'Like';
            $reply->textareaid = "textarea_id".$reply->id;
            $reply->postformid = "postform_id".$reply->id;
            // Blank reply post section
            $reply->replybody = ' ';
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

        $data = array(
            'cmid'         => $cm->id,
            'discussionid' => $discussion->id,
            'replycount'   => count($replies),
            'replylabel'   => count($replies) >= 2 || count($replies) == 0 ? 'replies' : 'reply',
            'firstpost'    => $firstpost,
            'canreply'     => $cm->groupmode == 0 ? true : $canreply,
            'showtaguserul'=> $showtaguserul,
            'tagusers'     => $tagusers,
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
        global $OUTPUT, $USER, $DB, $PAGE;

        // Check for valid discussion id
        if (!$args || (!isset($args['postid']) && !isset($args['discussionid'])) ) {
            print_r('No discussion id or post id provided');
            // @TODO - handle ionic way to redirect back with error popup
        }

        // Setting up init variables
        $postid = $args['postid'];
        $discussion = $DB->get_record('hsuforum_discussions', array('id' => $args['discussionid']), '*', MUST_EXIST);

        // Getting replies for the post
        $repliescondition = array('p.parent' => $postid);
        $replies = hsuforum_get_all_discussion_posts($discussion->id, $repliescondition);

        $data = array(
            'replycount' => count($replies),
            'replylabel' => count($replies) >= 2 || count($replies) == 0 ? 'replies' : 'reply',
        );

        return array(
            'templates' => array(
                array(
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_hsuforum/mobile_view_post_replies', $data),
                ),
            ),
            'javascript' => '',
            'otherdata' => array(
                'replies' => json_encode(array_values($replies)),
            ),
            'files' => ''
        );
    }
}
