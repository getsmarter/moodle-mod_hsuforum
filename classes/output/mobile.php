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

                // Valididate post and change status
                if ($post && $post->id) {
                    $postreplystatus[$args['parentid']]['status'] = 'success';
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
        }

        $data = array(
            'cmid' => $cm->id,
            'discussionid' => $discussion->id,
            'replies' => array_values($replies),
            'replycount' => count($replies),
            'replylabel' => count($replies) >= 2 || count($replies) == 0 ? 'replies' : 'reply',
            'firstpost' => $firstpost,
            'canreply' => $cm->groupmode == 0 ? true : $canreply,
        );

        return array(
            'templates' => array(
                array(
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_hsuforum/mobile_view_discussion_posts', $data),
                ),
            ),
            'javascript' => '
// Function to reset children styles
                function reset_children_styles(elements, child_type) {
                    return elements.querySelectorAll(child_type).forEach(function(element) {
                        element.style.display = "block";
                    });
                }


// Function to build profile link
                function create_profile_link(text_area_text, profile_string, id, at_position_start, at_position_end) {
                        let old_textarea_string = "";
                        let beginning_string = "";
                        let replace_string = "";
                        let end_string = "";

                        old_textarea_string = text_area_text;

                        beginning_string = (at_position_start > 0) ? old_textarea_string.slice(0, at_position_start -1) : " ";
// @TODO fix link here
                        replace_string = "<a href=/user/view.php?id=" + id + ">" + profile_string + "</a>";
                        end_string = old_textarea_string.slice(at_position_end, old_textarea_string.length);

                        return beginning_string + replace_string + end_string;
                }


                function init() {
// 1. Remember to add @ check event listeners to all text_areas on the screen with unique ids if nessesary
// 2. Might only need one ul but position the ul where the textarea is (will reduce lines)
                    let ul_active = false;
                    let at_position_start = 0;
                    let at_position_end = 0;
                    let searchstring = "";
                    let text_area = document.querySelector("#javatest");
                    let filter_element = document.querySelector(".tribute-container");
                    let filter_li_elements = filter_element.querySelectorAll("li");

                    if (text_area) {
                        text_area.addEventListener("input", function(e) {
                            // 1. Check for @key being pressed to mark ul as active
                            if (e.data == "@") {
                                // 2. Trigger true flag for ul active (remember to bind id to this)
                                ul_active = true;
                                // 3. Key caret index position to determine how many chars pressed
                                at_position_start = window.getSelection().anchorOffset;
                            }

                            if (ul_active) {
                                filter_element.style.display = "block";
                                at_position_end = window.getSelection().anchorOffset;
// Filter elements
                                // Dont filter for "shift" and "@"
                                if (e.data != "@") {
                                    if (filter_li_elements) {
                                        // Handle backspace on search. Input event recognize @ as null
                                        if (e.data == null) {
                                            reset_children_styles(filter_element, "li");
                                            searchstring = searchstring.substring(0, searchstring.length - 1);
                                        } else {
                                            searchstring += e.data;
                                        }

                                        filter_li_elements.forEach(function(element) {
                                            let element_text = element.innerHTML.toLowerCase()
                                            if (element_text.indexOf(searchstring) == -1) {
                                                element.style.display = "none";
                                            }
                                        });
                                    }
                                }

// Remove ul once backspace before @ or space pressed and reset params
                                if (at_position_end < at_position_start || e.keyCode == 32) {
                                    ul_active = false;
                                    filter_element.style.display = "none";
                                    searchstring = "";
                                    reset_children_styles(filter_element, "li");
                                }
                            }
                        });
                    }

// Click events for li elements
                    if (filter_li_elements) {
                        // Events for list items on click
                        filter_li_elements.forEach(function(element) {
                            element.addEventListener("touchstart", function(e) {

                                text_area.innerHTML = create_profile_link(text_area.innerHTML, e.target.innerText, e.target.id, at_position_start, at_position_end);
                // @TODO create destroy function
                                ul_active = false;
                                filter_element.style.display = "none";
                                searchstring = "";
                                reset_children_styles(filter_element, "li");
                            });
                        });
                    }

// END OF INIT FUNCTION
                }

            setTimeout(function() { console.log("DOM is available now"); init() });',
            'otherdata' => array(),
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
