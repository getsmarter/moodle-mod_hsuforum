<?php
namespace mod_hsuforum\output;
 
defined('MOODLE_INTERNAL') || die();
require_once(dirname(dirname(__DIR__)).'/lib.php');

use context_module;
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

    /// Basic Validation checks
        /** Checks for valid course module
         * @TODO
         * See how to redirect or throw friendly errors in app (popups)
         */
        if (empty($cm) && !$cm = get_coursemodule_from_instance("hsuforum", $forum->id, $course->id)) {
            print_error('missingparameter');
        }

    /// Decide if current user is allowed to see ALL the current discussions or not
        /** First check the group stuff
         * @TODO
         * Handle group check properly later
         * Reference lib.php line :5421 to implement when required
         */
        $groupmode    = groups_get_activity_groupmode($cm, $course);
        $currentgroup = groups_get_activity_group($cm);
        // Handle add new discussion button being available on template
        $canstart = hsuforum_user_can_post_discussion($forum, $currentgroup, $groupmode, $cm, $context);

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
            $discussions = hsuforum_get_discussions($cm, $sortorder, $fullpost, null, $maxdiscussions, $getuserlastmodified, $page, $perpage, -1, false);
        } catch (Exception $e) {
            // @TODO handle exceptions properly in the app context
            print_r($e->getMessage());
        }

        // Get user profile pictures for a discussion - temp fix will need rework
        if ($discussions) {
            foreach ($discussions as $discussion) {
                $postuser = false;
                $discussion->profilesrc = false;
                $postuser = hsuforum_extract_postuser($discussion, $forum, context_module::instance($cm->id));
                if ($postuser) {
                    $postuser->user_picture->size = 100;
                    $discussion->profilesrc = $postuser->user_picture->get_url($PAGE)->out();
                }
            }
        }

    /// Build data array to output in the template
        $data = array(
            'cmid' => $cm->id,
            'cmname' => $cm->name,
            'discussioncount' => count($discussions),
            'discussions' => array_values($discussions),
            'discussionlabel' => count($discussions) >= 2 || count($discussions) == 0 ? 'discussions' : 'discussion',
        );

        return array(
            'templates' => array(
                array(
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_hsuforum/mobile_view_discussions', $data),
                ),
            ),
            'javascript' => '',
            'otherdata' => '',
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
        global $OUTPUT, $USER, $DB, $PAGE;

        // Check for valid discussion id
        if (!$args || !isset($args['discussionid'])) {
            print_r('No discussion id provided');
            // @TODO - handle ionic way to redirect back with error popup
        }

        $discussion = $DB->get_record('hsuforum_discussions', array('id' => $args['discussionid']), '*', MUST_EXIST);
        $course     = $DB->get_record('course', array('id' => $discussion->course), '*', MUST_EXIST);
        $forum      = $DB->get_record('hsuforum', array('id' => $discussion->forum), '*', MUST_EXIST);
        $cm         = get_coursemodule_from_instance('hsuforum', $forum->id, $course->id, false, MUST_EXIST);
        $modcontext = context_module::instance($cm->id);
        $postreplystatus = [];

    /// Validation checks
        // @TODO check for validation checks and or triggers as below
        // @TODO see if this event is needed for mobile app lib.php :84 and :184

    /// Handle posting of a reply
        // @TODO handle post and template form validation
        // Check to see if posting a reply
        if ($args && isset($args['post'])) {
            $canreply = hsuforum_user_can_post($forum, $discussion, $USER, $cm, $course, $modcontext);
            $postreplybody = isset($args['postreplybody']) && strlen($args['postreplybody']) ? (string) $args['postreplybody'] : false;
            $postreplystatus[$args['parentid']] = array('status' => 'pending', 'error' => false);

            if ($canreply && $postreplybody) {
                // Saving post to db
                $post = new \stdClass();
                try {
                    $post->discussion    = $discussion->id;
                    $post->parent        = $args['parentid'];
                    $post->userid        = $USER->id;
                    $post->created       = time();
                    $post->modified      = time();
                    $post->subject       = 'RE: ' . $discussion->name;
                    $post->message       = $postreplybody;
                    $post->messageformat = FORMAT_HTML;
                    $post->id = $DB->insert_record("hsuforum_posts", $post);
                } catch (Exception $e) {
                    $postreplystatus[$args['parentid']]['error']  = $e->getMessage();
                    $postreplystatus[$args['parentid']]['status'] = 'failed';
                }

                // Valididate post and change status
                if ($post && $post->id) {
                    $postreplystatus[$args['parentid']]['status'] = 'success';
                }
            } else {
                $postreplystatus[$args['parentid']]['status'] = 'failed';
                $postreplystatus[$args['parentid']]['error'] = 'User is not permitted to post replies or reply body empty';
            }
        }


    /// Getting firstpost and root replies for the firstpost
        // Note there can only be one post(when user created discussion) in a discussion and then additional posts are regarged as replies(api data structure reflects this concept). Very confusing...
            // Note that a reply(post) can have children which are replies on a reply essentially.
        $firstpost = false;
        $replies = false;

        $firstpostcondition = array('p.id' => $discussion->firstpost);
        $firstpostresult = hsuforum_get_all_discussion_posts($discussion->id, $firstpostcondition);

        if ($firstpostresult) {
            $firstpost = array_pop($firstpostresult);
            $postuser = hsuforum_extract_postuser($firstpost, $forum, context_module::instance($cm->id));
            $postuser->user_picture->size = 100;
            $firstpost->profilesrc = $postuser->user_picture->get_url($PAGE)->out();
        }

        $repliescondition = array('p.parent' => $discussion->firstpost);
        $replies = hsuforum_get_all_discussion_posts($discussion->id, $repliescondition);

        $data = array(
            'cmid' => $cm->id,
            'discussionid' => $discussion->id,
            'replies' => array_values($replies),
            'replycount' => count($replies),
            'replylabel' => count($replies) >= 2 || count($replies) == 0 ? 'replies' : 'reply',
            'firstpost' => $firstpost,
        );

        return array(
            'templates' => array(
                array(
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_hsuforum/mobile_view_discussion_posts', $data),
                ),
            ),
            'javascript' => '',
            'otherdata' => array(),
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
            'replies' => array_values($replies),
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
            'otherdata' => '',
            'files' => ''
        );
    }
}
