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
        $allowedgroups          = array_values(groups_get_activity_allowed_groups($cm));

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
            'discussions' => array_values($discussions),
            'discussionlabel' => count($discussions) >= 2 || count($discussions) == 0 ? 'discussions' : 'discussion',
            'showgroupsections' => $showgroupsections
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
            print_r('No discussion id provided');
            // @TODO - handle ionic way to redirect back with error popup
        }

        $discussion = $DB->get_record('hsuforum_discussions', array('id' => $args['discussionid']), '*', MUST_EXIST);
        $course     = $DB->get_record('course', array('id' => $discussion->course), '*', MUST_EXIST);
        $forum      = $DB->get_record('hsuforum', array('id' => $discussion->forum), '*', MUST_EXIST);
        $cm         = get_coursemodule_from_instance('hsuforum', $forum->id, $course->id, false, MUST_EXIST);
        $modcontext = context_module::instance($cm->id);
        $canreply   = hsuforum_user_can_post($forum, $discussion, $USER, $cm, $course, $modcontext);
        $postreplystatus = [];

    /// Validation checks
        // @TODO check for validation checks and or triggers as below
        // @TODO see if this event is needed for mobile app lib.php :84 and :184

    /// Handle posting of a reply
        // @TODO handle post and template form validation
        // Check to see if posting a reply
        if ($args && isset($args['post'])) {
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

                // Valididate post, change status and send mail to tagged users
                if ($post && $post->id) {
                    $postreplystatus[$args['parentid']]['status'] = 'success';
                    // send mention emails on valid post
                    $mailtousers = gettaggedusers($postreplybody);
                    if (count($mailtousers)) {
                        $coursecoach = local_mention_users_observer::get_course_coach($course->id);
                        $link = $_SERVER['HTTP_HOST'] . '/mod/hsuforum/discuss.php?d=' . $discussion->id . '#p' . $post_id;
                        local_mention_users_observer::send_email_to_students($mailtousers, $course->fullname, $coursecoach, $link, $postreplybody);
                    }
                }
            } else {
                $postreplystatus[$args['parentid']]['status'] = 'failed';
                $postreplystatus[$args['parentid']]['error'] = 'User is not permitted to post replies or reply body empty';
            }
        }

    /// Handle posting of a liketoggle action
    if ($args && isset($args['liketoggle'])) {
        $likestatus = userlikedpost($args['parentid'], $args['userid']);

        if (!$likestatus) {
            $like = new \stdClass();
            $like->postid = $args['parentid'];
            $like->userid = $args['userid'];
            $like->action = "like";
            $like->created = time();

            $DB->insert_record('hsuforum_actions', $like);
        } else {
            $DB->delete_records('hsuforum_actions', array('postid' => $args['parentid'], 'userid' => $args['userid']));
        }
    }

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
        }

    /// Getting tagable users
        $tagusers = [];
        $tagusers = get_allowed_tag_users($forum->id, $discussion->groupid, 1);
        $tagusers = ($tagusers->result && count($tagusers->content)) ? build_allowed_tag_users($tagusers->content) : [];
        $showtaguserul = count($tagusers) ? true : false;

    /// Getting javascript file for injection
        $tagusersfile = $CFG->dirroot . '/mod/hsuforum/tagusers.js';
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
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_hsuforum/mobile_view_discussion_posts', $data),
                ),
            ),
            'javascript' => $tagusersjs,
            'otherdata' => array(
                'replies' => json_encode(array_values($replies)),
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
        global $OUTPUT, $USER, $DB;

        $cm                = get_coursemodule_from_id('hsuforum', $args['cmid']);
        $modcontext        = context_module::instance($cm->id);
        $forum             = $DB->get_record('hsuforum', array('id' => $cm->instance));
        $postsuccess       = false;
        $allowedgroups     = false;
        $showgroupsections = false;

        // Check if group mode apply and getting instance allowed groups
        if ((int) $cm->groupmode > 0) {
            $allowedgroups = array_values(groups_get_activity_allowed_groups($cm));
            $showgroupsections = true;
        }

        if ((int) $cm->groupmode == 2) {
            $groupstopostto = [];
            // Note: all groups are returned when in visible groups mode so we must manually filter.
            foreach ($allowedgroups as $groupid => $group) {
                if (hsuforum_user_can_post_discussion($forum, $groupid, -1, $cm, $modcontext)) {
                    $groupstopostto[] = $group;
                }
            }
            // Replace original allowed groups with filtered one based on permissions
            $allowedgroups = $groupstopostto;
        }
        // Add new discussion if data posted - @TODO this will need to be looked at again.
           // Refreshing the page calls the last post against that page thus below might not work as expected
           // Need to find a way to update the last post action to clear form flags
        if ($args['newdiscussionpost']) {

            $discussiontitle = isset($args['discussiontitle']) && strlen($args['discussiontitle']) ? (string) $args['discussiontitle'] : false;
            $discussionbody = isset($args['discussionbody']) && strlen($args['discussionbody']) ? (string) $args['discussionbody'] : '';
            $groupid = isset($args['groupid']) && (int) $args['groupid'] > 0 ? $args['groupid'] : -1;

            if ($discussiontitle) {
                // Save discussion to posts table
                $newdiscussion = new \stdClass();
                try {
                    $newdiscussion->discussion    = 0;
                    $newdiscussion->parent        = 0;
                    $newdiscussion->userid        = $USER->id;
                    $newdiscussion->created       = time();
                    $newdiscussion->modified      = time();
                    $newdiscussion->subject       = $discussiontitle;
                    $newdiscussion->message       = $discussionbody;
                    $newdiscussion->messageformat = FORMAT_HTML;
                    $newdiscussion->forum         = $forum->id;
                    $newdiscussion->course        = $cm->course;
                    $newdiscussion->attachments   = null;
                    $newdiscussion->id = $DB->insert_record("hsuforum_posts", $newdiscussion);
                } catch (Exception $e) {
                    print_r($e->getMessage());
                }

                //  Now save to discussions table and link to first post above
                if ($newdiscussion->id) {
                    try {
                        $newdiscussion->name         = $newdiscussion->subject;
                        $newdiscussion->firstpost    = $newdiscussion->id;
                        $newdiscussion->timemodified = time();
                        $newdiscussion->usermodified = $newdiscussion->userid;
                        $newdiscussion->userid       = $USER->id;
                        $newdiscussion->groupid      = $groupid;
                        $newdiscussion->assessed     = 0;
                        $newdiscussion->discussion = $DB->insert_record("hsuforum_discussions", $newdiscussion);

                        // Finally, set the pointer on the post.
                        $DB->set_field("hsuforum_posts", "discussion", $newdiscussion->discussion, array("id"=>$newdiscussion->id));

                        hsuforum_mark_post_read($newdiscussion->userid, $newdiscussion, $newdiscussion->forum);

                        // Let Moodle know that assessable content is uploaded (eg for plagiarism detection)
                        if (!empty($cm->id)) {
                            hsuforum_trigger_content_uploaded_event($newdiscussion, $cm, 'hsuforum_add_discussion');
                        }
                    } catch (Exception $e) {
                        print_r($e->getMessage());
                    }
                }

                // Valididate post and change newdiscussionpost flag to prevent duplicate form submits
                if ($newdiscussion->discussion && $newdiscussion->id) {
                    $postsuccess = true;
                    // @TODO fix unsetting the post args properly
                    unset($args['newdiscussionpost']);
                }

            } else {
                print_r('No discussion title provided');
            }
        }


        $returnarray = array(
            'templates' => array(
                array(
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_hsuforum/mobile_add_discussion', array(
                        'cmid' => $args['cmid'], 
                        'showgroupsections' => $showgroupsections)
                    ),
                ),
            ),
            'javascript' => '',
            'otherdata' => array(
                'groupsections' => $showgroupsections ? json_encode($allowedgroups) : false,
                'groupselection' => count($allowedgroups) ? $allowedgroups[0]->id : false,
                'discussiontitle' => '',
            ),
            'files' => ''
        );

        return $postsuccess ? mobile::forum_discussions_view($args) : $returnarray;
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
