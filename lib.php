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
 * @package   mod_hsuforum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright Copyright (c) 2012 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @author Mark Nielsen
 */

defined('MOODLE_INTERNAL') || die();

/** Include required files */
require_once($CFG->libdir.'/filelib.php');
require_once($CFG->libdir.'/deprecatedlib.php');
require_once($CFG->dirroot.'/user/selector/lib.php');

/// CONSTANTS ///////////////////////////////////////////////////////////


define('HSUFORUM_CHOOSESUBSCRIBE', 0);
define('HSUFORUM_FORCESUBSCRIBE', 1);
define('HSUFORUM_INITIALSUBSCRIBE', 2);
define('HSUFORUM_DISALLOWSUBSCRIBE',3);

define ('HSUFORUM_GRADETYPE_NONE', 0);
define ('HSUFORUM_GRADETYPE_MANUAL', 1);
define ('HSUFORUM_GRADETYPE_RATING', 2);

define('HSUFORUM_MAILED_PENDING', 0);
define('HSUFORUM_MAILED_SUCCESS', 1);
define('HSUFORUM_MAILED_ERROR', 2);

if (!defined('HSUFORUM_CRON_USER_CACHE')) {
    /** Defines how many full user records are cached in forum cron. */
    define('HSUFORUM_CRON_USER_CACHE', 5000);
}

/**
 * HSUFORUM_POSTS_ALL_USER_GROUPS - All the posts in groups where the user is enrolled.
 */
define('HSUFORUM_POSTS_ALL_USER_GROUPS', -2);
define('HSUFORUM_DISCUSSION_PINNED', 1);
define('HSUFORUM_DISCUSSION_UNPINNED', 0);

define('HSUFORUM_POSTS_NO_GROUPS', 0);
define('HSUFORUM_POSTS_SEPARATE_GROUPS', 1);
define('HSUFORUM_POSTS_VISIBLE_GROUPS', 2);

/// STANDARD FUNCTIONS ///////////////////////////////////////////////////////////

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @param stdClass $forum add forum instance
 * @param mod_hsuforum_mod_form $mform
 * @return int intance id
 */
function hsuforum_add_instance($forum, $mform = null) {
    global $CFG, $DB;

    $forum->timemodified = time();

    if ($forum->gradetype != HSUFORUM_GRADETYPE_MANUAL) {
        foreach ($forum as $name => $value) {
            if (strpos($name, 'advancedgradingmethod_') !== false) {
                $forum->$name = '';
            }
        }
    }

    if (empty($forum->assessed)) {
        $forum->assessed = 0;
    }

    if (empty($forum->ratingtime) or empty($forum->assessed)) {
        $forum->assesstimestart  = 0;
        $forum->assesstimefinish = 0;
    }

    $forum->id = $DB->insert_record('hsuforum', $forum);
    $modcontext = context_module::instance($forum->coursemodule);

    if ($forum->type == 'single') {  // Create related discussion.
        $discussion = new stdClass();
        $discussion->course        = $forum->course;
        $discussion->forum         = $forum->id;
        $discussion->name          = $forum->name;
        $discussion->assessed      = $forum->assessed;
        $discussion->message       = $forum->intro;
        $discussion->messageformat = $forum->introformat;
        $discussion->messagetrust  = trusttext_trusted(context_course::instance($forum->course));
        $discussion->mailnow       = false;
        $discussion->groupid       = -1;
        $discussion->reveal        =  0;

        $message = '';

        $discussion->id = hsuforum_add_discussion($discussion, null, $message);

        if ($mform and $draftid = file_get_submitted_draft_itemid('introeditor')) {
            // Ugly hack - we need to copy the files somehow.
            $discussion = $DB->get_record('hsuforum_discussions', array('id'=>$discussion->id), '*', MUST_EXIST);
            $post = $DB->get_record('hsuforum_posts', array('id'=>$discussion->firstpost), '*', MUST_EXIST);

            $options = array('subdirs'=>true); // Use the same options as intro field!
            $post->message = file_save_draft_area_files($draftid, $modcontext->id, 'mod_hsuforum', 'post', $post->id, $options, $post->message);
            $DB->set_field('hsuforum_posts', 'message', $post->message, array('id'=>$post->id));
        }
    }

    hsuforum_grade_item_update($forum);

    return $forum->id;
}

/**
 * Handle changes following the creation of a forum instance.
 * This function is typically called by the course_module_created observer.
 *
 * @param object $context the forum context
 * @param stdClass $forum The forum object
 * @return void
 */
function hsuforum_instance_created($context, $forum) {
    if ($forum->forcesubscribe == HSUFORUM_INITIALSUBSCRIBE) {
        $users = hsuforum_get_potential_subscribers($context, 0, 'u.id, u.email');
        foreach ($users as $user) {
            hsuforum_subscribe($user->id, $forum->id, $context);
        }
    }
}

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @global object
 * @param object $forum forum instance (with magic quotes)
 * @return bool success
 */
function hsuforum_update_instance($forum, $mform) {
    global $DB, $OUTPUT, $USER;

    $forum->timemodified = time();
    $forum->id           = $forum->instance;

    if ($forum->gradetype != HSUFORUM_GRADETYPE_MANUAL) {
        foreach ($forum as $name => $value) {
            if (strpos($name, 'advancedgradingmethod_') !== false) {
                $forum->$name = '';
            }
        }
    }
    if (empty($forum->assessed)) {
        $forum->assessed = 0;
    }

    if (empty($forum->ratingtime) or empty($forum->assessed)) {
        $forum->assesstimestart  = 0;
        $forum->assesstimefinish = 0;
    }

    $oldforum = $DB->get_record('hsuforum', array('id'=>$forum->id));

    // MDL-3942 - if the aggregation type or scale (i.e. max grade) changes then recalculate the grades for the entire forum
    // if  scale changes - do we need to recheck the ratings, if ratings higher than scale how do we want to respond?
    // for count and sum aggregation types the grade we check to make sure they do not exceed the scale (i.e. max score) when calculating the grade
    if (($oldforum->assessed<>$forum->assessed) or ($oldforum->scale<>$forum->scale)) {
        hsuforum_update_grades($forum); // recalculate grades for the forum
    }

    if ($forum->type == 'single') {  // Update related discussion and post.
        $discussions = $DB->get_records('hsuforum_discussions', array('forum'=>$forum->id), 'timemodified ASC');
        if (!empty($discussions)) {
            if (count($discussions) > 1) {
                echo $OUTPUT->notification(get_string('warnformorepost', 'hsuforum'));
            }
            $discussion = array_pop($discussions);
        } else {
            // try to recover by creating initial discussion - MDL-16262
            $discussion = new stdClass();
            $discussion->course          = $forum->course;
            $discussion->forum           = $forum->id;
            $discussion->name            = $forum->name;
            $discussion->assessed        = $forum->assessed;
            $discussion->message         = $forum->intro;
            $discussion->messageformat   = $forum->introformat;
            $discussion->messagetrust    = true;
            $discussion->mailnow         = false;
            $discussion->groupid         = -1;
            $discussion->reveal          = 0;

            $message = '';

            hsuforum_add_discussion($discussion, null, $message);

            if (! $discussion = $DB->get_record('hsuforum_discussions', array('forum'=>$forum->id))) {
                print_error('cannotadd', 'hsuforum');
            }
        }
        if (! $post = $DB->get_record('hsuforum_posts', array('id'=>$discussion->firstpost))) {
            print_error('cannotfindfirstpost', 'hsuforum');
        }

        $cm         = get_coursemodule_from_instance('hsuforum', $forum->id);
        $modcontext = context_module::instance($cm->id, MUST_EXIST);

        $post = $DB->get_record('hsuforum_posts', array('id'=>$discussion->firstpost), '*', MUST_EXIST);
        $post->subject       = $forum->name;
        $post->message       = $forum->intro;
        $post->messageformat = $forum->introformat;
        $post->messagetrust  = trusttext_trusted($modcontext);
        $post->modified      = $forum->timemodified;
        $post->userid        = $USER->id;    // MDL-18599, so that current teacher can take ownership of activities.

        if ($mform and $draftid = file_get_submitted_draft_itemid('introeditor')) {
            // Ugly hack - we need to copy the files somehow.
            $options = array('subdirs'=>true); // Use the same options as intro field!
            $post->message = file_save_draft_area_files($draftid, $modcontext->id, 'mod_hsuforum', 'post', $post->id, $options, $post->message);
        }

        $DB->update_record('hsuforum_posts', $post);
        $discussion->name = $forum->name;
        $DB->update_record('hsuforum_discussions', $discussion);
    }

    $DB->update_record('hsuforum', $forum);

    $modcontext = context_module::instance($forum->coursemodule);
    if (($forum->forcesubscribe == HSUFORUM_INITIALSUBSCRIBE) && ($oldforum->forcesubscribe <> $forum->forcesubscribe)) {
        $users = hsuforum_get_potential_subscribers($modcontext, 0, 'u.id, u.email', '');
        foreach ($users as $user) {
            hsuforum_subscribe($user->id, $forum->id, $modcontext);
        }
    }

    hsuforum_grade_item_update($forum);

    return true;
}


/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @global object
 * @param int $id forum instance id
 * @return bool success
 */
function hsuforum_delete_instance($id) {
    global $DB;

    if (!$forum = $DB->get_record('hsuforum', array('id'=>$id))) {
        return false;
    }
    if (!$cm = get_coursemodule_from_instance('hsuforum', $forum->id)) {
        return false;
    }
    if (!$course = $DB->get_record('course', array('id'=>$cm->course))) {
        return false;
    }

    $context = context_module::instance($cm->id);

    // now get rid of all files
    $fs = get_file_storage();
    $fs->delete_area_files($context->id);

    $result = true;

    if ($discussions = $DB->get_records('hsuforum_discussions', array('forum'=>$forum->id))) {
        foreach ($discussions as $discussion) {
            if (!hsuforum_delete_discussion($discussion, true, $course, $cm, $forum)) {
                $result = false;
            }
        }
    }

    if (!$DB->delete_records('hsuforum_digests', array('forum' => $forum->id))) {
        $result = false;
    }

    if (!$DB->delete_records('hsuforum_subscriptions', array('forum'=>$forum->id))) {
        $result = false;
    }

    hsuforum_delete_read_records_for_forum($forum->id);

    if (!$DB->delete_records('hsuforum', array('id'=>$forum->id))) {
        $result = false;
    }

    hsuforum_grade_item_delete($forum);

    return $result;
}


/**
 * Indicates API features that the forum supports.
 *
 * @uses FEATURE_GROUPS
 * @uses FEATURE_GROUPINGS
 * @uses FEATURE_MOD_INTRO
 * @uses FEATURE_COMPLETION_TRACKS_VIEWS
 * @uses FEATURE_COMPLETION_HAS_RULES
 * @uses FEATURE_GRADE_HAS_GRADE
 * @uses FEATURE_GRADE_OUTCOMES
 * @param string $feature
 * @return mixed True if yes (some features may use other values)
 */
function hsuforum_supports($feature) {
    global $CFG;

    switch($feature) {
        case FEATURE_GROUPS:                  return true;
        case FEATURE_GROUPINGS:               return true;
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_COMPLETION_HAS_RULES:    return true;
        case FEATURE_GRADE_HAS_GRADE:         return true;
        case FEATURE_GRADE_OUTCOMES:          return true;
        case FEATURE_RATE:                    return true;
        case FEATURE_BACKUP_MOODLE2:          return true;
        case FEATURE_SHOW_DESCRIPTION:        return true;
        case FEATURE_ADVANCED_GRADING:        return (!empty($CFG->mod_hsuforum_grading_interface));
        case FEATURE_PLAGIARISM:              return true;

        default: return null;
    }
}

/**
 * Lists all gradable areas for the advanced grading methods
 *
 * @return array
 */
function hsuforum_grading_areas_list() {
    return array('posts' => get_string('posts', 'hsuforum'));
}

/**
 * Obtains the automatic completion state for this forum based on any conditions
 * in forum settings.
 *
 * @global object
 * @global object
 * @param object $course Course
 * @param object $cm Course-module
 * @param int $userid User ID
 * @param bool $type Type of comparison (or/and; can be used as return value if no conditions)
 * @return bool True if completed, false if not. (If no conditions, then return
 *   value depends on comparison type)
 */
function hsuforum_get_completion_state($course,$cm,$userid,$type) {
    global $DB;

    // Get forum details
    if (!($forum=$DB->get_record('hsuforum',array('id'=>$cm->instance)))) {
        throw new Exception("Can't find forum {$cm->instance}");
    }

    $result=$type; // Default return value

    $postcountparams=array('userid'=>$userid,'forumid'=>$forum->id);
    $postcountsql="
SELECT
    COUNT(1)
FROM
    {hsuforum_posts} fp
    INNER JOIN {hsuforum_discussions} fd ON fp.discussion=fd.id
WHERE
    fp.userid=:userid AND fd.forum=:forumid";

    if ($forum->completiondiscussions) {
        $value = $forum->completiondiscussions <=
                 $DB->count_records('hsuforum_discussions',array('forum'=>$forum->id,'userid'=>$userid));
        if ($type == COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }
    if ($forum->completionreplies) {
        $value = $forum->completionreplies <=
                 $DB->get_field_sql( $postcountsql.' AND fp.parent<>0',$postcountparams);
        if ($type==COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }
    if ($forum->completionposts) {
        $value = $forum->completionposts <= $DB->get_field_sql($postcountsql,$postcountparams);
        if ($type == COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }

    return $result;
}

/**
 * Create a message-id string to use in the custom headers of forum notification emails
 *
 * message-id is used by email clients to identify emails and to nest conversations
 *
 * @param int $postid The ID of the forum post we are notifying the user about
 * @param int $usertoid The ID of the user being notified
 * @param string $hostname The server's hostname
 * @return string A unique message-id
 */
function hsuforum_get_email_message_id($postid, $usertoid, $hostname) {
    return '<'.hash('sha256',$postid.'to'.$usertoid).'@'.$hostname.'>';
}

/**
 * Removes properties from user record that are not necessary
 * for sending post notifications.
 * @param stdClass $user
 * @return void, $user parameter is modified
 */
function hsuforum_cron_minimise_user_record(stdClass $user) {

    // We store large amount of users in one huge array,
    // make sure we do not store info there we do not actually need
    // in mail generation code or messaging.

    unset($user->institution);
    unset($user->department);
    unset($user->address);
    unset($user->city);
    unset($user->url);
    unset($user->currentlogin);
    unset($user->description);
    unset($user->descriptionformat);
}

/**
 * Function to get a posts parents
 */
function hsu_forum_get_post_parents($postid, $discussionid) {
    global $DB;

    $posts = $DB->get_records_menu('hsuforum_posts', array('discussion' => $discussionid), '', 'id, parent');

    if (!empty($posts)) {
        $currentparent = $postid;
        $postparentarray = array();
        while ($currentparent != 0) {
            $postparentarray[$currentparent] = $posts[$currentparent];

            $currentparent = $posts[$currentparent];
        }
    }

    return $postparentarray;
}

/**
 * Function to be run periodically according to the scheduled task.
 *
 * Finds all posts that have yet to be mailed out, and mails them
 * out to all subscribers as well as other maintance tasks.
 *
 * NOTE: Since 2.7.2 this function is run by scheduled task rather
 * than standard cron.
 *
 * @todo MDL-44734 The function will be split up into seperate tasks.
 */
function hsuforum_cron() {
    global $CFG, $USER, $DB, $PAGE;

    $site = get_site();

    $config = get_config('hsuforum');

    // The main renderers.
    $htmlout = $PAGE->get_renderer('mod_hsuforum', 'email', 'htmlemail');
    $textout = $PAGE->get_renderer('mod_hsuforum', 'email', 'textemail');
    $htmldigestfullout = $PAGE->get_renderer('mod_hsuforum', 'emaildigestfull', 'htmlemail');
    $textdigestfullout = $PAGE->get_renderer('mod_hsuforum', 'emaildigestfull', 'textemail');
    $htmldigestbasicout = $PAGE->get_renderer('mod_hsuforum', 'emaildigestbasic', 'htmlemail');
    $textdigestbasicout = $PAGE->get_renderer('mod_hsuforum', 'emaildigestbasic', 'textemail');

    // All users that are subscribed to any post that needs sending,
    // please increase $CFG->extramemorylimit on large sites that
    // send notifications to a large number of users.
    $users = array();
    $userscount = 0; // Cached user counter - count($users) in PHP is horribly slow!!!

    // Status arrays.
    $mailcount  = array();
    $errorcount = array();

    // Caches.
    $discussions     = array();
    $forums          = array();
    $courses         = array();
    $coursemodules   = array();
    $subscribedusers = array();
    $discussionsubscribers = array();
    $messageinboundhandlers = array();

    require_once(__DIR__.'/repository/discussion.php');
    $discussionrepo = new hsuforum_repository_discussion();

    // Posts older than 2 days will not be mailed.  This is to avoid the problem where
    // cron has not been running for a long time, and then suddenly people are flooded
    // with mail from the past few weeks or months
    $timenow   = time();
    $endtime   = $timenow - $CFG->maxeditingtime;
    $starttime = $endtime - 48 * 3600;   // Two days earlier

    // Get the list of forum subscriptions for per-user per-forum maildigest settings.
    $digestsset = $DB->get_recordset('hsuforum_digests', null, '', 'id, userid, forum, maildigest');
    $digests = array();
    foreach ($digestsset as $thisrow) {
        if (!isset($digests[$thisrow->forum])) {
            $digests[$thisrow->forum] = array();
        }
        $digests[$thisrow->forum][$thisrow->userid] = $thisrow->maildigest;
    }
    $digestsset->close();

    // Create the generic messageinboundgenerator.
    $messageinboundgenerator = new \core\message\inbound\address_manager();
    $messageinboundgenerator->set_handler('\mod_hsuforum\message\inbound\reply_handler');

    if ($posts = hsuforum_get_unmailed_posts($starttime, $endtime, $timenow)) {
        // Mark them all now as being mailed.  It's unlikely but possible there
        // might be an error later so that a post is NOT actually mailed out,
        // but since mail isn't crucial, we can accept this risk.  Doing it now
        // prevents the risk of duplicated mails, which is a worse problem.

        if (!hsuforum_mark_old_posts_as_mailed($endtime)) {
            mtrace('Errors occurred while trying to mark some posts as being mailed.');
            return false;  // Don't continue trying to mail them, in case we are in a cron loop
        }

        // checking post validity, and adding users to loop through later
        foreach ($posts as $pid => $post) {

            $discussionid = $post->discussion;
            if (!isset($discussions[$discussionid])) {
                if ($discussion = $DB->get_record('hsuforum_discussions', array('id'=> $post->discussion))) {
                    $discussions[$discussionid] = $discussion;
                } else {
                    mtrace('Could not find discussion '.$discussionid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            $forumid = $discussions[$discussionid]->forum;
            if (!isset($forums[$forumid])) {
                if ($forum = $DB->get_record('hsuforum', array('id' => $forumid))) {
                    $forums[$forumid] = $forum;
                } else {
                    mtrace('Could not find forum '.$forumid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            $courseid = $forums[$forumid]->course;
            if (!isset($courses[$courseid])) {
                if ($course = $DB->get_record('course', array('id' => $courseid))) {
                    $courses[$courseid] = $course;
                } else {
                    mtrace('Could not find course '.$courseid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            if (!isset($coursemodules[$forumid])) {
                if ($cm = get_coursemodule_from_instance('hsuforum', $forumid, $courseid)) {
                    $coursemodules[$forumid] = $cm;
                } else {
                    mtrace('Could not find course module for forum '.$forumid);
                    unset($posts[$pid]);
                    continue;
                }
            }

            // Save the Inbound Message datakey here to reduce DB queries later.
            $messageinboundgenerator->set_data($pid);
            $messageinboundhandlers[$pid] = $messageinboundgenerator->fetch_data_key();

            // Caching subscribed users of each forum.
            if (!isset($subscribedusers[$forumid])) {
                $modcontext = context_module::instance($coursemodules[$forumid]->id);
                if ($subusers = hsuforum_subscribed_users($courses[$courseid], $forums[$forumid], 0, $modcontext, "u.*")) {
                    foreach ($subusers as $postuser) {
                        // this user is subscribed to this forum
                        $subscribedusers[$forumid][$postuser->id] = $postuser->id;
                        $userscount++;
                        if ($userscount > HSUFORUM_CRON_USER_CACHE) {
                            // Store minimal user info.
                            $minuser = new stdClass();
                            $minuser->id = $postuser->id;
                            $users[$postuser->id] = $minuser;
                        } else {
                            // Cache full user record.
                            hsuforum_cron_minimise_user_record($postuser);
                            $users[$postuser->id] = $postuser;
                        }
                    }
                    // Release memory.
                    unset($subusers);
                    unset($postuser);
                }
            }

            // caching subscribed users of each discussion
            if (!isset($discussionsubscribers[$discussionid])) {
                $modcontext = context_module::instance($coursemodules[$forumid]->id);
                if ($subusers = $discussionrepo->get_subscribed_users($forums[$forumid], $discussions[$discussionid], $modcontext, 0, 'u.*', array(), 'u.email ASC')) {
                    // Get a list of the users subscribed to discussions in the hsuforum.
                    foreach ($subusers as $postuser) {
                        unset($postuser->description); // not necessary
                        // the user is subscribed to this discussion
                        $discussionsubscribers[$discussionid][$postuser->id] = $postuser->id;
                        // this user is a user we have to process later
                        $users[$postuser->id] = $postuser;
                    }
                }
            }

            $mailcount[$pid] = 0;
            $errorcount[$pid] = 0;
        }
    }

    if ($users && $posts) {

        $urlinfo = parse_url($CFG->wwwroot);
        $hostname = $urlinfo['host'];

        foreach ($users as $userto) {
            // Terminate if processing of any account takes longer than 2 minutes.
            core_php_time_limit::raise(120);

            mtrace('Processing user ' . $userto->id);
            // Init user caches - we keep the cache for one cycle only, otherwise it could consume too much memory.
            if (isset($userto->username)) {
                $userto = clone($userto);
            } else {
                $userto = $DB->get_record('user', array('id' => $userto->id));
                hsuforum_cron_minimise_user_record($userto);
            }
            $userto->viewfullnames = array();
            $userto->canpost       = array();
            $userto->markposts     = array();

            // Set this so that the capabilities are cached, and environment matches receiving user.
            cron_setup_user($userto);

            // Reset the caches.
            foreach ($coursemodules as $forumid => $unused) {
                $coursemodules[$forumid]->cache       = new stdClass();
                $coursemodules[$forumid]->cache->caps = array();
                unset($coursemodules[$forumid]->uservisible);
            }

            foreach ($posts as $pid => $post) {

                $discussion = $discussions[$post->discussion];
                $forum      = $forums[$discussion->forum];
                $course     = $courses[$forum->course];
                $cm         =& $coursemodules[$forum->id];

                // Do some checks  to see if we can bail out now.

                // Only active enrolled users are in the list of subscribers.
                // This does not necessarily mean that the user is subscribed to the forum or to the discussion though.
                if (!isset($subscribedusers[$forum->id][$userto->id])) {
                    if (!isset($discussionsubscribers[$post->discussion][$userto->id])) {
                        continue; // user does not subscribe to this forum
                    }
                }

                // Don't send email if the forum is Q&A and the user has not posted.
                // Initial topics are still mailed.
                if ($forum->type == 'qanda' && !hsuforum_get_user_posted_time($discussion->id, $userto->id) && $pid != $discussion->firstpost) {
                    mtrace('Did not email ' . $userto->id . ' because user has not posted in discussion');
                    continue;
                }

                // Don't send email if the post is a mention for userto
                $id_array = mention_id_array($post->message);
                if (in_array($userto->id, $id_array)) {
                    continue;
                }

                // Get info about the sending user.
                if (array_key_exists($post->userid, $users)) {
                    // We might know the user already.
                    $userfrom = $users[$post->userid];
                    if (!isset($userfrom->idnumber)) {
                        // Minimalised user info, fetch full record.
                        $userfrom = $DB->get_record('user', array('id' => $userfrom->id));
                        hsuforum_cron_minimise_user_record($userfrom);
                    }

                } else if ($userfrom = $DB->get_record('user', array('id' => $post->userid))) {
                    hsuforum_cron_minimise_user_record($userfrom);
                    // Fetch only once if possible, we can add it to user list, it will be skipped anyway.
                    if ($userscount <= HSUFORUM_CRON_USER_CACHE) {
                        $userscount++;
                        $users[$userfrom->id] = $userfrom;
                    }
                } else {
                    mtrace('Could not find user ' . $post->userid . ', author of post ' . $post->id . '. Unable to send message.');
                    continue;
                }

                // Note: If we want to check that userto and userfrom are not the same person this is probably the spot to do it.

                // Setup global $COURSE properly - needed for roles and languages.
                cron_setup_user($userto, $course);

                // Fill caches.
                if (!isset($userto->viewfullnames[$forum->id])) {
                    $modcontext = context_module::instance($cm->id);
                    $userto->viewfullnames[$forum->id] = has_capability('moodle/site:viewfullnames', $modcontext);
                }
                if (!isset($userto->canpost[$discussion->id])) {
                    $modcontext = context_module::instance($cm->id);
                    $userto->canpost[$discussion->id] = hsuforum_user_can_post($forum, $discussion, $userto, $cm, $course, $modcontext);
                }
                if (!isset($userfrom->groups[$forum->id])) {
                    if (!isset($userfrom->groups)) {
                        $userfrom->groups = array();
                        if (isset($users[$userfrom->id])) {
                            $users[$userfrom->id]->groups = array();
                        }
                    }
                    $userfrom->groups[$forum->id] = groups_get_all_groups($course->id, $userfrom->id, $cm->groupingid);
                    if (isset($users[$userfrom->id])) {
                        $users[$userfrom->id]->groups[$forum->id] = $userfrom->groups[$forum->id];
                    }
                }

                // Make sure groups allow this user to see this email.
                if ($discussion->groupid > 0 and $groupmode = groups_get_activity_groupmode($cm, $course)) {
                    // Groups are being used.
                    if (!groups_group_exists($discussion->groupid)) {
                        // Can't find group - be safe and don't this message.
                        continue;
                    }

                    if (!groups_is_member($discussion->groupid) and !has_capability('moodle/site:accessallgroups', $modcontext)) {
                        // Do not send posts from other groups when in SEPARATEGROUPS or VISIBLEGROUPS.
                        continue;
                    }
                }

                // Make sure we're allowed to see the post.
                if (!hsuforum_user_can_see_post($forum, $discussion, $post, null, $cm)) {
                    mtrace('User ' . $userto->id .' can not see ' . $post->id . '. Not sending message.');
                    continue;
                }

                // OK so we need to send the email.

                // Does the user want this post in a digest?  If so postpone it for now.
                $maildigest = hsuforum_get_user_maildigest_bulk($digests, $userto, $forum->id);

                if ($maildigest > 0) {
                    // This user wants the mails to be in digest form.
                    $queue = new stdClass();
                    $queue->userid       = $userto->id;
                    $queue->discussionid = $discussion->id;
                    $queue->postid       = $post->id;
                    $queue->timemodified = $post->created;
                    $DB->insert_record('hsuforum_queue', $queue);
                    continue;
                }


                // Prepare to actually send the post now, and build up the content.

                $cleanforumname = str_replace('"', "'", strip_tags(format_string($forum->name)));

                $userfrom->customheaders = array (
                    // Headers to make emails easier to track.
                    'List-Id: "'        . $cleanforumname . '" <moodlehsuforum' . $forum->id . '@' . $hostname.'>',
                    'List-Help: '       . $CFG->wwwroot . '/mod/hsuforum/view.php?f=' . $forum->id,
                    'Message-ID: '      . hsuforum_get_email_message_id($post->id, $userto->id, $hostname),
                    'X-Course-Id: '     . $course->id,
                    'X-Course-Name: '   . format_string($course->fullname, true),

                    // Headers to help prevent auto-responders.
                    'Precedence: Bulk',
                    'X-Auto-Response-Suppress: All',
                    'Auto-Submitted: auto-generated',
               );

                if ($post->parent) {
                    // This post is a reply, so add headers for threading (see MDL-22551)
                    $userfrom->customheaders[] = 'In-Reply-To: ' . hsuforum_get_email_message_id($post->parent, $userto->id, $hostname);
                    $userfrom->customheaders[] = 'References: ' . hsuforum_get_email_message_id($post->parent, $userto->id, $hostname);
                }

                $shortname = format_string($course->shortname, true, array('context' => context_course::instance($course->id)));

                // Generate a reply-to address from using the Inbound Message handler.
                $replyaddress = null;
                if ($userto->canpost[$discussion->id] && array_key_exists($post->id, $messageinboundhandlers) && empty($post->privatereply)) {
                    $messageinboundgenerator->set_data($post->id, $messageinboundhandlers[$post->id]);
                    $replyaddress = $messageinboundgenerator->generate($userto->id);
                }

                if (!isset($userto->canpost[$discussion->id])) {
                    $canreply = hsuforum_user_can_post($forum, $discussion, $userto, $cm, $course, $modcontext);
                } else {
                    $canreply = $userto->canpost[$discussion->id];
                }
                if (!empty($post->privatereply)) {
                    $canreply = false;
                }

                $postuser = hsuforum_anonymize_user($userfrom, $forum, $post);

                $data = new \mod_hsuforum\output\hsuforum_post_email(
                        $course,
                        $cm,
                        $forum,
                        $discussion,
                        $post,
                        $postuser,
                        $userto,
                        $canreply
                    );

                if (!isset($userto->viewfullnames[$forum->id])) {
                    $data->viewfullnames = has_capability('moodle/site:viewfullnames', $modcontext, $userto->id);
                } else {
                    $data->viewfullnames = $userto->viewfullnames[$forum->id];
                }

                $a = new stdClass();
                $a->courseshortname = $data->get_coursename();
                $a->forumname = $cleanforumname;
                $a->subject = $data->get_subject();
                $postsubject = html_to_text(get_string('postmailsubject', 'hsuforum', $a), 0);

                $postfullmessage = get_string('mobileappnotification', 'hsuforum');
                $postfullmessage = str_replace("{student_full_name}", fullname($postuser), $postfullmessage);
                $postfullmessage = str_replace("{course_short_name}", $a->courseshortname, $postfullmessage);
                $postfullmessage = str_replace("{hsuforum_name}", $cleanforumname, $postfullmessage);
                $postfullmessage = str_replace("{discussion_name}", $discussion->name, $postfullmessage);

                // Send the post now!

                mtrace('Sending ', '');

                $eventdata = new \core\message\message();
                $eventdata->component        = 'mod_hsuforum';
                $eventdata->name             = 'posts';
                $eventdata->userfrom         = $postuser;
                $eventdata->userto           = $userto;
                $eventdata->subject          = $postsubject;
                $eventdata->fullmessage      = $postfullmessage;
                $eventdata->fullmessageformat = FORMAT_PLAIN;
                $eventdata->fullmessagehtml  = $htmlout->render($data);
                $eventdata->notification = 1;
                $eventdata->replyto = $replyaddress;
                if (!empty($replyaddress)) {
                    // Add extra text to email messages if they can reply back.
                    $textfooter = "\n\n" . get_string('replytopostbyemail', 'mod_hsuforum');
                    $htmlfooter = html_writer::tag('p', get_string('replytopostbyemail', 'mod_hsuforum'));
                    $additionalcontent = array('fullmessage' => array('footer' => $textfooter),
                                     'fullmessagehtml' => array('footer' => $htmlfooter));
                    $eventdata->set_additional_content('email', $additionalcontent);
                }

                // If hsuforum_replytouser is not set then send mail using the noreplyaddress.
                if (empty($config->replytouser)) {
                    $eventdata->userfrom = core_user::get_noreply_user();
                }

                $smallmessagestrings = new stdClass();
                $smallmessagestrings->user = fullname($postuser);
                $smallmessagestrings->forumname = "$shortname: " . format_string($forum->name,true) . ": ".$discussion->name;
                $smallmessagestrings->message = $post->message;

                // Make sure strings are in message recipients language.
                $eventdata->smallmessage = get_string_manager()->get_string('smallmessage', 'hsuforum', $smallmessagestrings, $userto->lang);

                $contexturl = new moodle_url('/mod/hsuforum/discuss.php', array('d' => $discussion->id), 'p' . $post->id);
                $eventdata->contexturl = $contexturl->out();
                $eventdata->contexturlname = $discussion->name;

                $parentidsarray = hsu_forum_get_post_parents($post->id, $discussion->id);

                $customdata = array('courseid' => $course->id, 'cmid' => $cm->id, 'discussionid' => $discussion->id, 'postid' => $post->id, 'postparents' => $parentidsarray);

                $eventdata->customdata = $customdata;

                $mailresult = message_send($eventdata);
                if (!$mailresult) {
                    mtrace("Error: mod/hsuforum/lib.php hsuforum_cron(): Could not send out mail for id $post->id to user $userto->id".
                         " ($userto->email) .. not trying again.");
                    $errorcount[$post->id]++;
                } else {
                    $mailcount[$post->id]++;
                    $cutoffdate = $timenow - ($config->oldpostdays*24*60*60);
                    if ($post->modified < $cutoffdate) {
                        $userto->markposts[$post->id] = $post->id;
                    }
                }

                mtrace('post ' . $post->id . ': ' . $post->subject);
            }

            // Mark processed posts as read.
            hsuforum_mark_posts_read($userto, $userto->markposts);
            unset($userto);
        }
    }

    if ($posts) {
        foreach ($posts as $post) {
            mtrace($mailcount[$post->id]." users were sent post $post->id, '$post->subject'");
            if ($errorcount[$post->id]) {
                $DB->set_field('hsuforum_posts', 'mailed', HSUFORUM_MAILED_ERROR, array('id' => $post->id));
            }
        }
    }

    // Release some memory.
    unset($subscribedusers);
    unset($mailcount);
    unset($errorcount);

    cron_setup_user();

    $sitetimezone = core_date::get_server_timezone();

    // Now see if there are any digest mails waiting to be sent, and if we should send them

    mtrace('Starting digest processing...');

    core_php_time_limit::raise(300); // Terminate if not able to fetch all digests in 5 minutes

    if (!isset($config->digestmailtimelast)) {    // To catch the first time.
        set_config('digestmailtimelast', 0, 'hsuforum');
        $config->digestmailtimelast = 0;
    }

    $timenow = time();
    $digesttime = usergetmidnight($timenow, $sitetimezone) + ($config->digestmailtime * 3600);

    // Delete any really old ones (normally there shouldn't be any)
    $weekago = $timenow - (7 * 24 * 3600);
    $DB->delete_records_select('hsuforum_queue', "timemodified < ?", array($weekago));
    mtrace ('Cleaned old digest records');

    if ($config->digestmailtimelast < $digesttime and $timenow > $digesttime) {

        mtrace('Sending forum digests: '.userdate($timenow, '', $sitetimezone));

        $digestposts_rs = $DB->get_recordset_select('hsuforum_queue', "timemodified < ?", array($digesttime));

        if ($digestposts_rs->valid()) {

            // We have work to do
            $usermailcount = 0;

            //caches - reuse the those filled before too
            $discussionposts = array();
            $userdiscussions = array();

            foreach ($digestposts_rs as $digestpost) {
                if (!isset($posts[$digestpost->postid])) {
                    if ($post = $DB->get_record('hsuforum_posts', array('id' => $digestpost->postid))) {
                        $posts[$digestpost->postid] = $post;
                    } else {
                        continue;
                    }
                }
                $discussionid = $digestpost->discussionid;
                if (!isset($discussions[$discussionid])) {
                    if ($discussion = $DB->get_record('hsuforum_discussions', array('id' => $discussionid))) {
                        $discussions[$discussionid] = $discussion;
                    } else {
                        continue;
                    }
                }
                $forumid = $discussions[$discussionid]->forum;
                if (!isset($forums[$forumid])) {
                    if ($forum = $DB->get_record('hsuforum', array('id' => $forumid))) {
                        $forums[$forumid] = $forum;
                    } else {
                        continue;
                    }
                }

                $courseid = $forums[$forumid]->course;
                if (!isset($courses[$courseid])) {
                    if ($course = $DB->get_record('course', array('id' => $courseid))) {
                        $courses[$courseid] = $course;
                    } else {
                        continue;
                    }
                }

                if (!isset($coursemodules[$forumid])) {
                    if ($cm = get_coursemodule_from_instance('hsuforum', $forumid, $courseid)) {
                        $coursemodules[$forumid] = $cm;
                    } else {
                        continue;
                    }
                }
                $userdiscussions[$digestpost->userid][$digestpost->discussionid] = $digestpost->discussionid;
                $discussionposts[$digestpost->discussionid][$digestpost->postid] = $digestpost->postid;
            }
            $digestposts_rs->close(); /// Finished iteration, let's close the resultset

            // Data collected, start sending out emails to each user
            foreach ($userdiscussions as $userid => $thesediscussions) {

                core_php_time_limit::raise(120); // terminate if processing of any account takes longer than 2 minutes

                cron_setup_user();

                mtrace(get_string('processingdigest', 'hsuforum', $userid), '... ');

                // First of all delete all the queue entries for this user
                $DB->delete_records_select('hsuforum_queue', "userid = ? AND timemodified < ?", array($userid, $digesttime));

                // Init user caches - we keep the cache for one cycle only,
                // otherwise it would unnecessarily consume memory.
                if (array_key_exists($userid, $users) and isset($users[$userid]->username)) {
                    $userto = clone($users[$userid]);
                } else {
                    $userto = $DB->get_record('user', array('id' => $userid));
                    hsuforum_cron_minimise_user_record($userto);
                }
                $userto->viewfullnames = array();
                $userto->canpost       = array();
                $userto->markposts     = array();

                // Override the language and timezone of the "current" user, so that
                // mail is customised for the receiver.
                cron_setup_user($userto);

                $postsubject = get_string('digestmailsubject', 'hsuforum', format_string($site->shortname, true));

                $headerdata = new stdClass();
                $headerdata->sitename = format_string($site->fullname, true);
                $headerdata->userprefs = $CFG->wwwroot.'/user/edit.php?id='.$userid.'&amp;course='.$site->id;

                $posttext = get_string('digestmailheader', 'hsuforum', $headerdata)."\n\n";
                $headerdata->userprefs = '<a target="_blank" href="'.$headerdata->userprefs.'">'.get_string('digestmailprefs', 'hsuforum').'</a>';

                $posthtml = "<head>";
/*                foreach ($CFG->stylesheets as $stylesheet) {
                    //TODO: MDL-21120
                    $posthtml .= '<link rel="stylesheet" type="text/css" href="'.$stylesheet.'" />'."\n";
                }*/
                $posthtml .= "</head>\n<body id=\"email\">\n";
                $posthtml .= '<p>'.get_string('digestmailheader', 'hsuforum', $headerdata).'</p><br /><hr size="1" noshade="noshade" />';

                foreach ($thesediscussions as $discussionid) {

                    core_php_time_limit::raise(120);   // to be reset for each post

                    $discussion = $discussions[$discussionid];
                    $forum      = $forums[$discussion->forum];
                    $course     = $courses[$forum->course];
                    $cm         = $coursemodules[$forum->id];

                    //override language
                    cron_setup_user($userto, $course);

                    // Fill caches
                    if (!isset($userto->viewfullnames[$forum->id])) {
                        $modcontext = context_module::instance($cm->id);
                        $userto->viewfullnames[$forum->id] = has_capability('moodle/site:viewfullnames', $modcontext);
                    }
                    if (!isset($userto->canpost[$discussion->id])) {
                        $modcontext = context_module::instance($cm->id);
                        $userto->canpost[$discussion->id] = hsuforum_user_can_post($forum, $discussion, $userto, $cm, $course, $modcontext);
                    }

                    $strforums      = get_string('forums', 'hsuforum');
                    $canunsubscribe = ! hsuforum_is_forcesubscribed($forum);
                    $canreply       = $userto->canpost[$discussion->id];
                    $shortname = format_string($course->shortname, true, array('context' => context_course::instance($course->id)));

                    $posttext .= "\n \n";
                    $posttext .= '=====================================================================';
                    $posttext .= "\n \n";
                    $posttext .= "$shortname -> $strforums -> ".format_string($forum->name,true);
                    if ($discussion->name != $forum->name) {
                        $posttext  .= " -> ".format_string($discussion->name,true);
                    }
                    $posttext .= "\n";
                    $posttext .= $CFG->wwwroot.'/mod/hsuforum/discuss.php?d='.$discussion->id;
                    $posttext .= "\n";

                    $posthtml .= "<p><font face=\"sans-serif\">".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/course/view.php?id=$course->id\">$shortname</a> -> ".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/mod/hsuforum/index.php?id=$course->id\">$strforums</a> -> ".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/mod/hsuforum/view.php?f=$forum->id\">".format_string($forum->name,true)."</a>";
                    if ($discussion->name == $forum->name) {
                        $posthtml .= "</font></p>";
                    } else {
                        $posthtml .= " -> <a target=\"_blank\" href=\"$CFG->wwwroot/mod/hsuforum/discuss.php?d=$discussion->id\">".format_string($discussion->name,true)."</a></font></p>";
                    }
                    $posthtml .= '<p>';

                    $postsarray = $discussionposts[$discussionid];
                    sort($postsarray);

                    foreach ($postsarray as $postid) {
                        $post = $posts[$postid];

                        if (array_key_exists($post->userid, $users)) { // we might know him/her already
                            $userfrom = $users[$post->userid];
                            if (!isset($userfrom->idnumber)) {
                                $userfrom = $DB->get_record('user', array('id' => $userfrom->id));
                                hsuforum_cron_minimise_user_record($userfrom);
                            }

                        } else if ($userfrom = $DB->get_record('user', array('id' => $post->userid))) {
                            hsuforum_cron_minimise_user_record($userfrom);
                            if ($userscount <= HSUFORUM_CRON_USER_CACHE) {
                                $userscount++;
                                $users[$userfrom->id] = $userfrom;
                            }

                        } else {
                            mtrace('Could not find user '.$post->userid);
                            continue;
                        }

                        if (!isset($userfrom->groups[$forum->id])) {
                            if (!isset($userfrom->groups)) {
                                $userfrom->groups = array();
                                if (isset($users[$userfrom->id])) {
                                    $users[$userfrom->id]->groups = array();
                                }
                            }
                            $userfrom->groups[$forum->id] = groups_get_all_groups($course->id, $userfrom->id, $cm->groupingid);
                            if (isset($users[$userfrom->id])) {
                                $users[$userfrom->id]->groups[$forum->id] = $userfrom->groups[$forum->id];
                            }
                        }

                        // Headers to help prevent auto-responders.
                        $userfrom->customheaders = array(
                                "Precedence: Bulk",
                                'X-Auto-Response-Suppress: All',
                                'Auto-Submitted: auto-generated',
                            );

                        $maildigest = hsuforum_get_user_maildigest_bulk($digests, $userto, $forum->id);
                        if (!isset($userto->canpost[$discussion->id])) {
                            $canreply = hsuforum_user_can_post($forum, $discussion, $userto, $cm, $course, $modcontext);
                        } else {
                            $canreply = $userto->canpost[$discussion->id];
                        }
                        if (!empty($post->privatereply)) {
                            $canreply = false;
                        }

                        $postuser = hsuforum_anonymize_user($userfrom, $forum, $post);

                        $data = new \mod_hsuforum\output\hsuforum_post_email(
                                $course,
                                $cm,
                                $forum,
                                $discussion,
                                $post,
                                $postuser,
                                $userto,
                                $canreply
                            );

                        if (!isset($userto->viewfullnames[$forum->id])) {
                            $data->viewfullnames = has_capability('moodle/site:viewfullnames', $modcontext, $userto->id);
                        } else {
                            $data->viewfullnames = $userto->viewfullnames[$forum->id];
                        }
                        if ($maildigest == 2) {
                            // Subjects and link only.
                             $posttext .= $textdigestbasicout->render($data);
                             $posthtml .= $htmldigestbasicout->render($data);
                        } else {
                            // The full treatment
                            $posttext .= $textdigestfullout->render($data);
                            $posthtml .= $htmldigestfullout->render($data);

                            // Create an array of postid's for this user to mark as read.
                            $cutoffdate = $timenow - ($config->oldpostdays*24*60*60);
                            if ($post->modified < $cutoffdate) {
                                $userto->markposts[$post->id] = $post->id;
                            }
                        }
                    }
                    $footerlinks = array();
                    if ($canunsubscribe) {
                        $footerlinks[] = "<a href=\"$CFG->wwwroot/mod/hsuforum/subscribe.php?id=$forum->id\">" . get_string("unsubscribe", "hsuforum") . "</a>";
                    } else {
                        $footerlinks[] = get_string("everyoneissubscribed", "hsuforum");
                    }
                    $footerlinks[] = "<a href='{$CFG->wwwroot}/mod/hsuforum/index.php?id={$forum->course}'>" . get_string("digestmailpost", "hsuforum") . '</a>';
                    $posthtml .= "\n<div class='mdl-right'><font size=\"1\">" . implode('&nbsp;', $footerlinks) . '</font></div>';
                    $posthtml .= '<hr size="1" noshade="noshade" /></p>';
                }
                $posthtml .= '</body>';

                if (empty($userto->mailformat) || $userto->mailformat != 1) {
                    // This user DOESN'T want to receive HTML
                    $posthtml = '';
                }

                $attachment = $attachname='';
                // Directly email forum digests rather than sending them via messaging, use the
                // site shortname as 'from name', the noreply address will be used by email_to_user.
                $mailresult = email_to_user($userto, $site->shortname, $postsubject, $posttext, $posthtml, $attachment, $attachname);

                if (!$mailresult) {
                    mtrace("ERROR: mod/hsuforum/cron.php: Could not send out digest mail to user $userto->id ".
                        "($userto->email)... not trying again.");
                } else {
                    mtrace("success.");
                    $usermailcount++;

                    // Mark post as read
                    hsuforum_mark_posts_read($userto, $userto->markposts);
                }
            }
        }
    /// We have finishied all digest emails, update hsuforum digestmailtimelast
        set_config('digestmailtimelast', $timenow, 'hsuforum');
        $config->digestmailtimelast = $timenow;
    }

    cron_setup_user();

    if (!empty($usermailcount)) {
        mtrace(get_string('digestsentusers', 'hsuforum', $usermailcount));
    }

    if (!empty($config->lastreadclean)) {
        $timenow = time();
        if ($config->lastreadclean + (24*3600) < $timenow) {
            set_config('lastreadclean', $timenow, 'hsuforum');
            mtrace('Removing old forum read tracking info...');
            hsuforum_tp_clean_read_records();
        }
    } else {
        $timenow = time();
        set_config('lastreadclean', $timenow, 'hsuforum');
    }


    return true;
}

/**
 *
 * @param object $course
 * @param object $user
 * @param object $mod TODO this is not used in this function, refactor
 * @param object $forum
 * @return object A standard object with 2 variables: info (number of posts for this user) and time (last modified)
 */
function hsuforum_user_outline($course, $user, $mod, $forum) {
    global $CFG;
    require_once("$CFG->libdir/gradelib.php");
    $grades = grade_get_grades($course->id, 'mod', 'hsuforum', $forum->id, $user->id);
    if (empty($grades->items[0]->grades)) {
        $grade = false;
    } else {
        $grade = reset($grades->items[0]->grades);
    }

    $count = hsuforum_count_user_posts($forum->id, $user->id);

    if ($count && $count->postcount > 0) {
        $result = new stdClass();
        $result->info = get_string("numposts", "hsuforum", $count->postcount);
        $result->time = $count->lastpost;
        if ($grade) {
            $result->info .= ', ' . get_string('grade') . ': ' . $grade->str_long_grade;
        }
        return $result;
    } else if ($grade) {
        $result = new stdClass();
        $result->info = get_string('grade') . ': ' . $grade->str_long_grade;

        //datesubmitted == time created. dategraded == time modified or time overridden
        //if grade was last modified by the user themselves use date graded. Otherwise use date submitted
        //TODO: move this copied & pasted code somewhere in the grades API. See MDL-26704
        if ($grade->usermodified == $user->id || empty($grade->datesubmitted)) {
            $result->time = $grade->dategraded;
        } else {
            $result->time = $grade->datesubmitted;
        }

        return $result;
    }
    return NULL;
}


/**
 * @global object
 * @global object
 * @param object $coure
 * @param object $user
 * @param object $mod
 * @param object $forum
 */
function hsuforum_user_complete($course, $user, $mod, $forum) {
    global $CFG,$PAGE, $OUTPUT;
    require_once("$CFG->libdir/gradelib.php");

    $grades = grade_get_grades($course->id, 'mod', 'hsuforum', $forum->id, $user->id);
    if (!empty($grades->items[0]->grades)) {
        $grade = reset($grades->items[0]->grades);
        echo $OUTPUT->container(get_string('grade').': '.$grade->str_long_grade);
        if ($grade->str_feedback) {
            echo $OUTPUT->container(get_string('feedback').': '.$grade->str_feedback);
        }
    }

    if ($posts = hsuforum_get_user_posts($forum->id, $user->id)) {

        if (!$cm = get_coursemodule_from_instance('hsuforum', $forum->id, $course->id)) {
            print_error('invalidcoursemodule');
        }
        $discussions = hsuforum_get_user_involved_discussions($forum->id, $user->id);

        foreach ($posts as $post) {
            if (!isset($discussions[$post->discussion])) {
                continue;
            }
            $discussion = $discussions[$post->discussion];

            $renderer = $PAGE->get_renderer('mod_hsuforum');
            echo $renderer->post($cm, $discussion, $post, false, null, false);
        }
    } else {
        echo "<p>".get_string("noposts", "hsuforum")."</p>";
    }
}

/**
 * Filters the forum discussions according to groups membership and config.
 *
 * @since  Moodle 2.8, 2.7.1, 2.6.4
 * @param  array $discussions Discussions with new posts array
 * @return array Forums with the number of new posts
 */
function hsuforum_filter_user_groups_discussions($discussions) {

    // Group the remaining discussions posts by their forumid.
    $filteredforums = array();

    // Discard not visible groups.
    foreach ($discussions as $discussion) {

        // Course data is already cached.
        $instances = get_fast_modinfo($discussion->course)->get_instances();
        $forum = $instances['hsuforum'][$discussion->forum];

        // Continue if the user should not see this discussion.
        if (!hsuforum_is_user_group_discussion($forum, $discussion->groupid)) {
            continue;
        }

        // Grouping results by forum.
        if (empty($filteredforums[$forum->instance])) {
            $filteredforums[$forum->instance] = new stdClass();
            $filteredforums[$forum->instance]->id = $forum->id;
            $filteredforums[$forum->instance]->count = 0;
        }
        $filteredforums[$forum->instance]->count += $discussion->count;

    }

    return $filteredforums;
}

/**
 * Returns whether the discussion group is visible by the current user or not.
 *
 * @since Moodle 2.8, 2.7.1, 2.6.4
 * @param cm_info $cm The discussion course module
 * @param int $discussiongroupid The discussion groupid
 * @return bool
 */
function hsuforum_is_user_group_discussion(cm_info $cm, $discussiongroupid) {

    if ($discussiongroupid == -1 || $cm->effectivegroupmode != SEPARATEGROUPS) {
        return true;
    }

    if (isguestuser()) {
        return false;
    }

    if (has_capability('moodle/site:accessallgroups', context_module::instance($cm->id)) ||
            in_array($discussiongroupid, $cm->get_modinfo()->get_groups($cm->groupingid))) {
        return true;
    }

    return false;
}

/**
 * @global object
 * @global object
 * @global object
 * @param array $courses
 * @param array $htmlarray
 */
function hsuforum_print_overview($courses,&$htmlarray) {
    global $USER, $CFG, $DB, $SESSION;

    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$forums = get_all_instances_in_courses('hsuforum',$courses)) {
        return;
    }

    $config = get_config('hsuforum');

    // Courses to search for new posts
    $coursessqls = array();
    $params = array();
    foreach ($courses as $course) {

        // If the user has never entered into the course all posts are pending
        if ($course->lastaccess == 0) {
            $coursessqls[] = '(d.course = ?)';
            $params[] = $course->id;

        // Only posts created after the course last access
        } else {
            $coursessqls[] = '(d.course = ? AND p.created > ?)';
            $params[] = $course->id;
            $params[] = $course->lastaccess;
        }
    }
    $params[] = $USER->id;
    $coursessql = implode(' OR ', $coursessqls);

    $sql = "SELECT d.id, d.forum, d.course, d.groupid, COUNT(*) as count "
                .'FROM {hsuforum_discussions} d '
                .'JOIN {hsuforum_posts} p ON p.discussion = d.id '
                ."WHERE ($coursessql) "
                .'AND p.userid != ? '
                .'AND (d.timestart <= ? AND (d.timeend = 0 OR d.timeend > ?)) '
                .'GROUP BY d.id, d.forum, d.course, d.groupid '
                .'ORDER BY d.course, d.forum';
    $params[] = time();
    $params[] = time();

    // Avoid warnings.
    if (!$discussions = $DB->get_records_sql($sql, $params)) {
        $discussions = array();
    }

    $forumsnewposts = hsuforum_filter_user_groups_discussions($discussions);

    // also get all forum tracking stuff ONCE.
    $trackingforums = array();
    foreach ($forums as $forum) {
        $trackingforums[$forum->id] = $forum;
    }

    if (count($trackingforums) > 0) {
        $cutoffdate = isset($config->oldpostdays) ? (time() - ($config->oldpostdays*24*60*60)) : 0;
        $sql = 'SELECT d.forum,d.course,COUNT(p.id) AS count '.
            ' FROM {hsuforum_posts} p '.
            ' JOIN {hsuforum_discussions} d ON p.discussion = d.id '.
            ' LEFT JOIN {hsuforum_read} r ON r.postid = p.id AND r.userid = ? WHERE (';
        $params = array($USER->id);

        foreach ($trackingforums as $track) {
            $sql .= '(d.forum = ? AND (d.groupid = -1 OR d.groupid = 0 OR d.groupid = ?)) OR ';
            $params[] = $track->id;
            if (isset($SESSION->currentgroup[$track->course])) {
                $groupid =  $SESSION->currentgroup[$track->course];
            } else {
                // get first groupid
                $groupids = groups_get_all_groups($track->course, $USER->id);
                if ($groupids) {
                    reset($groupids);
                    $groupid = key($groupids);
                    $SESSION->currentgroup[$track->course] = $groupid;
                } else {
                    $groupid = 0;
                }
                unset($groupids);
            }
            $params[] = $groupid;
        }
        $sql = substr($sql,0,-3); // take off the last OR
        $sql .= ') AND p.modified >= ? AND r.id is NULL ';
        $sql .= 'AND (p.privatereply = 0 OR p.privatereply = ? OR p.userid = ?) ';
        $sql .= 'AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)) ';
        $sql .= 'GROUP BY d.forum,d.course';
        $params[] = $cutoffdate;
        $params[] = $USER->id;
        $params[] = $USER->id;
        $params[] = time();
        $params[] = time();

        if (!$unread = $DB->get_records_sql($sql, $params)) {
            $unread = array();
        }
    }

    if (empty($unread) and empty($forumsnewposts)) {
        return;
    }

    $strforum = get_string('modulename','hsuforum');

    foreach ($forums as $forum) {
        $str = '';
        $count = 0;
        $thisunread = 0;
        $showunread = false;
        // either we have something from logs, or trackposts, or nothing.
        if (array_key_exists($forum->id, $forumsnewposts) && !empty($forumsnewposts[$forum->id])) {
            $count = $forumsnewposts[$forum->id]->count;
        }
        if (array_key_exists($forum->id,$unread)) {
            $thisunread = $unread[$forum->id]->count;
            $showunread = true;
        }
        if ($count > 0 || $thisunread > 0) {
            $str .= '<div class="overview forum"><div class="name">'.$strforum.': <a title="'.$strforum.'" href="'.$CFG->wwwroot.'/mod/hsuforum/view.php?f='.$forum->id.'">'.
                $forum->name.'</a></div>';
            $str .= '<div class="info"><span class="postsincelogin">';
            $str .= get_string('overviewnumpostssince', 'hsuforum', $count)."</span>";
            if (!empty($showunread)) {
                $str .= '<div class="unreadposts">'.get_string('overviewnumunread', 'hsuforum', $thisunread).'</div>';
            }
            $str .= '</div></div>';
        }
        if (!empty($str)) {
            if (!array_key_exists($forum->course,$htmlarray)) {
                $htmlarray[$forum->course] = array();
            }
            if (!array_key_exists('hsuforum',$htmlarray[$forum->course])) {
                $htmlarray[$forum->course]['hsuforum'] = ''; // initialize, avoid warnings
            }
            $htmlarray[$forum->course]['hsuforum'] .= $str;
        }
    }
}

/**
 * Given a course and a date, prints a summary of all the new
 * messages posted in the course since that date
 *
 * @param object $course
 * @param bool $viewfullnames capability
 * @param int $timestart
 * @return bool success
 */
function hsuforum_print_recent_activity($course, $viewfullnames, $timestart) {
    $recentactivity = hsuforum_recent_activity($course, $viewfullnames, $timestart);
    if (!empty($recentactivity)) {
        echo $recentactivity;
        return true;
    } else {
        return false;
    }
}

/**
 * Given a course and a date, prints a summary of all the new
 * messages posted in the course since that date as HTML media objects
 *
 * @global object $CFG
 * @global object $USER
 * @global object $DB
 * @global object $OUTPUT
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $course
 * @param bool $viewfullnames capability
 * @param int $timestart
 * @return string recent activity
 */
function hsuforum_recent_activity($course, $viewfullnames, $timestart, $forumid = null) {
    global $CFG, $USER, $DB, $OUTPUT;

    $limitfrom = 0;
    $limitnum = 0;
    $andforumid = '';
    if ($forumid !== null) {
        $andforumid = 'AND d.forum = ?';
        $limitnum = 6;
    }
    $allnamefields = user_picture::fields('u', null, 'duserid');
    $sql = "SELECT p.*, f.anonymous as forumanonymous, f.type AS forumtype,
                   d.forum, d.groupid, d.timestart, d.timeend, $allnamefields
              FROM {hsuforum_posts} p
              JOIN {hsuforum_discussions} d ON d.id = p.discussion
              JOIN {hsuforum} f             ON f.id = d.forum
              JOIN {user} u                 ON u.id = p.userid
             WHERE p.created > ?
                   AND f.course = ?
                   AND (p.privatereply = 0 OR p.privatereply = ? OR p.userid = ?)
                   $andforumid
          ORDER BY p.created DESC
    ";
    $params = array($timestart, $course->id, $USER->id, $USER->id, $forumid);
    if (!$posts = $DB->get_records_sql($sql, $params, $limitfrom, $limitnum)) {
         return '';
    }

    $modinfo = get_fast_modinfo($course);
    $config = get_config('hsuforum');

    $groupmodes = array();
    $cms    = array();
    $out = '';
    foreach ($posts as $post) {
        if (!isset($modinfo->instances['hsuforum'][$post->forum])) {
            // not visible
            continue;
        }
        $cm = $modinfo->instances['hsuforum'][$post->forum];
        if (!$cm->uservisible) {
            continue;
        }
        $context = context_module::instance($cm->id);

        if (!has_capability('mod/hsuforum:viewdiscussion', $context)) {
            continue;
        }

        if (!empty($config->enabletimedposts) and $USER->id != $post->duserid
          and (($post->timestart > 0 and $post->timestart > time()) or ($post->timeend > 0 and $post->timeend < time()))) {
            if (!has_capability('mod/hsuforum:viewhiddentimedposts', $context)) {
                continue;
            }
        }

        // Check that the user can see the discussion.
        if (hsuforum_is_user_group_discussion($cm, $post->groupid)) {
            $postuser = hsuforum_extract_postuser($post, hsuforum_get_cm_forum($cm), context_module::instance($cm->id));

            $userpicture = new user_picture($postuser);
            $userpicture->link = false;
            $userpicture->alttext = false;
            $userpicture->size = 100;
            $picture = $OUTPUT->render($userpicture);

            $url = $CFG->wwwroot.'/mod/hsuforum/discuss.php?d='.$post->discussion;
            if (!empty($post->parent)) {
                $url .= '#p'.$post->id;
            }

            $postusername = fullname($postuser, $viewfullnames);
            $postsubject = break_up_long_words(format_string($post->subject, true));
            $posttime = hsuforum_relative_time($post->modified);
            $out .= hsuforum_media_object($url, $picture, $postusername, $posttime, $postsubject);

        }

    }

    if($out) {
        $out = "<div class='hsuforum-recent clearfix'>
        <h3 class='hsuforum-recent-heading'>".get_string('newforumposts', 'hsuforum')."</h3>".$out."</div>";
    }
    return $out;
}

/**
 * @param $url
 * @param $picture
 * @param $username
 * @param $time
 * @param $subject
 * @param $userid
 * @return string HTML media object
 */
function hsuforum_media_object($url, $picture, $username, $time, $subject) {
        $out = "<div class=\"snap-media-object\">";
        $out .= "<a href='$url'>";
        $out .= $picture;
        $out .= "<div class=\"snap-media-body\">";
        $out .= "<h3>".format_string($username)."</h3>";
        $out .= "<span class=snap-media-meta>$time</span>";
        $out .= "<p>".format_string($subject)."</p></div>";
        $out .= "</a></div>";
        return $out;
}

/**
 * @param $forum
 * @param $userid
 * @return bool|string
 * @author Mark Nielsen
 */
function hsuforum_get_user_formatted_rating_grade($forum, $userid) {
    $grades = hsuforum_get_user_rating_grades($forum, $userid);
    if (!empty($grades) and array_key_exists($userid, $grades)) {
        $gradeitem = grade_item::fetch(array(
            'courseid'     => $forum->course,
            'itemtype'     => 'mod',
            'itemmodule'   => 'hsuforum',
            'iteminstance' => $forum->id,
            'itemnumber'   => 0,
        ));
        return grade_format_gradevalue($grades[$userid]->rawgrade, $gradeitem);
    }
    return false;
}

/**
 * Return rating grades for given user or all users.
 *
 * @global object
 * @global object
 * @param object $forum
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none
 * @author Mark Nielsen
 */
function hsuforum_get_user_rating_grades($forum, $userid = 0) {
    global $CFG;

    if (!$forum->assessed) {
        return false;
    }
    require_once($CFG->dirroot.'/rating/lib.php');

    $ratingoptions = new stdClass;
    $ratingoptions->component = 'mod_hsuforum';
    $ratingoptions->ratingarea = 'post';

    //need these to work backwards to get a context id. Is there a better way to get contextid from a module instance?
    $ratingoptions->modulename = 'hsuforum';
    $ratingoptions->moduleid   = $forum->id;
    $ratingoptions->userid = $userid;
    $ratingoptions->aggregationmethod = $forum->assessed;
    $ratingoptions->scaleid = $forum->scale;
    $ratingoptions->itemtable = 'hsuforum_posts';
    $ratingoptions->itemtableusercolumn = 'userid';

    $rm = new rating_manager();
    return $rm->get_user_grades($ratingoptions);
}

/**
 * Return grade for given user or all users.
 *
 * @global object
 * @global object
 * @param object $forum
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none
 */
function hsuforum_get_user_grades($forum, $userid = 0) {
    if ($forum->gradetype != HSUFORUM_GRADETYPE_RATING) {
        return false;
    }
    return hsuforum_get_user_rating_grades($forum, $userid);
}

/**
 * Update activity grades
 *
 * @category grade
 * @param object $forum
 * @param int $userid specific user only, 0 means all
 * @param boolean $nullifnone return null if grade does not exist
 * @return void
 */
function hsuforum_update_grades($forum, $userid=0, $nullifnone=true) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    if ($forum->gradetype == HSUFORUM_GRADETYPE_NONE or $forum->gradetype == HSUFORUM_GRADETYPE_MANUAL or
        ($forum->gradetype == HSUFORUM_GRADETYPE_RATING and !$forum->assessed)) {
        hsuforum_grade_item_update($forum);

    } else if ($grades = hsuforum_get_user_grades($forum, $userid)) {
        hsuforum_grade_item_update($forum, $grades);

    } else if ($userid and $nullifnone) {
        $grade = new stdClass();
        $grade->userid   = $userid;
        $grade->rawgrade = NULL;
        hsuforum_grade_item_update($forum, $grade);

    } else {
        hsuforum_grade_item_update($forum);
    }
}

/**
 * Update all grades in gradebook.
 * @global object
 */
function hsuforum_upgrade_grades() {
    global $DB;

    $sql = "SELECT COUNT('x')
              FROM {hsuforum} f, {course_modules} cm, {modules} m
             WHERE m.name='hsuforum' AND m.id=cm.module AND cm.instance=f.id";
    $count = $DB->count_records_sql($sql);

    $sql = "SELECT f.*, cm.idnumber AS cmidnumber, f.course AS courseid
              FROM {hsuforum} f, {course_modules} cm, {modules} m
             WHERE m.name='hsuforum' AND m.id=cm.module AND cm.instance=f.id";
    $rs = $DB->get_recordset_sql($sql);
    if ($rs->valid()) {
        $pbar = new progress_bar('forumupgradegrades', 500, true);
        $i=0;
        foreach ($rs as $forum) {
            $i++;
            upgrade_set_timeout(60*5); // set up timeout, may also abort execution
            hsuforum_update_grades($forum, 0, false);
            $pbar->update($i, $count, "Updating Forum grades ($i/$count).");
        }
    }
    $rs->close();
}

/**
 * Create/update grade item for given forum
 *
 * @category grade
 * @uses GRADE_TYPE_NONE
 * @uses GRADE_TYPE_VALUE
 * @uses GRADE_TYPE_SCALE
 * @param stdClass $forum Forum object with extra cmidnumber
 * @param mixed $grades Optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok
 */
function hsuforum_grade_item_update($forum, $grades=NULL) {
    global $CFG;
    if (!function_exists('grade_update')) { //workaround for buggy PHP versions
        require_once($CFG->libdir.'/gradelib.php');
    }

    $params = array('itemname'=>$forum->name, 'idnumber'=>$forum->cmidnumber);

    if ($forum->gradetype == HSUFORUM_GRADETYPE_NONE or ($forum->gradetype == HSUFORUM_GRADETYPE_RATING and !$forum->assessed) or $forum->scale == 0) {
        $params['gradetype'] = GRADE_TYPE_NONE;

    } else if ($forum->scale > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $forum->scale;
        $params['grademin']  = 0;

    } else if ($forum->scale < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid']   = -$forum->scale;
    }

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = NULL;
    }

    return grade_update('mod/hsuforum', $forum->course, 'mod', 'hsuforum', $forum->id, 0, $grades, $params);
}

/**
 * Delete grade item for given forum
 *
 * @category grade
 * @param stdClass $forum Forum object
 * @return grade_item
 */
function hsuforum_grade_item_delete($forum) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    return grade_update('mod/hsuforum', $forum->course, 'mod', 'hsuforum', $forum->id, 0, NULL, array('deleted'=>1));
}

/**
 * This function returns if a scale is being used by one forum
 *
 * @global object
 * @param int $forumid
 * @param int $scaleid negative number
 * @return bool
 */
function hsuforum_scale_used ($forumid,$scaleid) {
    global $DB;
    $return = false;

    $rec = $DB->get_record("hsuforum",array("id" => "$forumid","scale" => "-$scaleid"));

    if (!empty($rec) && !empty($scaleid)) {
        $return = true;
    }

    return $return;
}

/**
 * Checks if scale is being used by any instance of forum
 *
 * This is used to find out if scale used anywhere
 *
 * @global object
 * @param $scaleid int
 * @return boolean True if the scale is used by any forum
 */
function hsuforum_scale_used_anywhere($scaleid) {
    global $DB;
    if ($scaleid and $DB->record_exists('hsuforum', array('scale' => -$scaleid))) {
        return true;
    } else {
        return false;
    }
}

// SQL FUNCTIONS ///////////////////////////////////////////////////////////

/**
 * Gets a post with all info ready for it to be rendered
 * Most of these joins are just to get the forum id
 *
 * @global object
 * @global object
 * @param int $postid
 * @return mixed array of posts or false
 */
function hsuforum_get_post_full($postid) {
    global $CFG, $DB;

    $allnames = get_all_user_name_fields(true, 'u');
    return $DB->get_record_sql("SELECT p.*, d.forum, $allnames, u.email, u.picture, u.imagealt, r.lastread AS postread
                             FROM {hsuforum_posts} p
                                  JOIN {hsuforum_discussions} d ON p.discussion = d.id
                                  LEFT JOIN {user} u ON p.userid = u.id
                                  LEFT JOIN {hsuforum_read} r ON r.postid = p.id AND r.userid = u.id
                            WHERE p.id = ?", array($postid));
}

/**
 * Gets all posts in discussion including top parent.
 *
 * @global object
 * @global object
 * @global object
 * @param int $discussionid
 * @param bool $tracking does user track the forum?
 * @return array of posts
 */
function hsuforum_get_all_discussion_posts($discussionid, $conditions = array()) {
    global $CFG, $DB, $USER;

    $tr_sel  = "";
    $tr_join = "";
    $params = array();

    $now = time();
    $tr_sel  = ", fr.id AS postread";
    $tr_join = "LEFT JOIN {hsuforum_read} fr ON (fr.postid = p.id AND fr.userid = ?)";
    $params[] = $USER->id;

    $allnames = get_all_user_name_fields(true, 'u');
    $params[] = $discussionid;
    $params[] = $USER->id;
    $params[] = $USER->id;

    $conditionsql = '';
    foreach ($conditions as $field => $value) {
        $conditionsql .= " AND $field = ?";
        $params[] = $value;
    }

    if (!$posts = $DB->get_records_sql("SELECT p.*, $allnames, u.email, u.picture, u.imagealt $tr_sel
                                     FROM {hsuforum_posts} p
                                          LEFT JOIN {user} u ON p.userid = u.id
                                          $tr_join
                                    WHERE p.discussion = ?
                                      AND (p.privatereply = 0 OR p.privatereply = ? OR p.userid = ?)
                                      $conditionsql
                                 ORDER BY p.created ASC", $params)) {
        return array();
    }

    foreach ($posts as $pid=>$p) {
        if (hsuforum_tp_is_post_old($p)) {
             $posts[$pid]->postread = true;
        }
        if (!$p->parent) {
            continue;
        }
        if (!isset($posts[$p->parent])) {
            continue; // parent does not exist??
        }
        if (!isset($posts[$p->parent]->children)) {
            $posts[$p->parent]->children = array();
        }
        $posts[$p->parent]->children[$pid] =& $posts[$pid];
    }

    // Start with the last child of the first post.
    $post = &$posts[reset($posts)->id];

    $lastpost = false;
    while (!$lastpost) {
        if (!isset($post->children)) {
            $post->lastpost = true;
            $lastpost = true;
        } else {
             // Go to the last child of this post.
            $post = &$posts[end($post->children)->id];
        }
    }

    return $posts;
}

function add_forum_js($scriptdata) {

    $js[] = $scriptdata;

    return join(' ', $js);
}

/**
 * An array of forum objects that the user is allowed to read/search through.
 *
 * @global object
 * @global object
 * @global object
 * @param int $userid
 * @param int $courseid if 0, we look for forums throughout the whole site.
 * @param bool excludeanonymous - if true will exclude all anonymous forums.
 * @return array of forum objects, or false if no matches
 *         Forum objects have the following attributes:
 *         id, type, course, cmid, cmvisible, cmgroupmode, accessallgroups,
 *         viewhiddentimedposts
 */
function hsuforum_get_readable_forums($userid, $courseid=0, $excludeanonymous = false) {

    global $CFG, $DB, $USER;
    require_once($CFG->dirroot.'/course/lib.php');

    if (!$forummod = $DB->get_record('modules', array('name' => 'hsuforum'))) {
        print_error('notinstalled', 'hsuforum');
    }

    $config = get_config('hsuforum');

    if ($courseid) {
        $courses = $DB->get_records('course', array('id' => $courseid));
    } else {
        // If no course is specified, then the user can see SITE + his courses.
        $courses1 = $DB->get_records('course', array('id' => SITEID));
        $courses2 = enrol_get_users_courses($userid, true, array('modinfo'));
        $courses = array_merge($courses1, $courses2);
    }
    if (!$courses) {
        return array();
    }

    $readableforums = array();

    foreach ($courses as $course) {

        $modinfo = get_fast_modinfo($course);

        if (empty($modinfo->instances['hsuforum'])) {
            // hmm, no forums?
            continue;
        }

        $params = ['course' => $course->id];
        if ($excludeanonymous) {
            $params['anonymous'] = 0;
        }
        $courseforums = $DB->get_records('hsuforum', $params);

        foreach ($modinfo->instances['hsuforum'] as $forumid => $cm) {
            if (!$cm->uservisible or !isset($courseforums[$forumid])) {
                continue;
            }
            $context = context_module::instance($cm->id);
            $forum = $courseforums[$forumid];
            $forum->context = $context;
            $forum->cm = $cm;

            if (!has_capability('mod/hsuforum:viewdiscussion', $context)) {
                continue;
            }

         /// group access
            if (groups_get_activity_groupmode($cm, $course) == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $context)) {

                $forum->onlygroups = $modinfo->get_groups($cm->groupingid);
                $forum->onlygroups[] = -1;
            }

        /// hidden timed discussions
            $forum->viewhiddentimedposts = true;
            if (!empty($config->enabletimedposts)) {
                if (!has_capability('mod/hsuforum:viewhiddentimedposts', $context)) {
                    $forum->viewhiddentimedposts = false;
                }
            }

        /// qanda access
            if ($forum->type == 'qanda'
                    && !has_capability('mod/hsuforum:viewqandawithoutposting', $context)) {

                // We need to check whether the user has posted in the qanda forum.
                $forum->onlydiscussions = array();  // Holds discussion ids for the discussions
                                                    // the user is allowed to see in this forum.
                if ($discussionspostedin = hsuforum_discussions_user_has_posted_in($forum->id, $USER->id)) {
                    foreach ($discussionspostedin as $d) {
                        $forum->onlydiscussions[] = $d->id;
                    }
                }
            }

            $readableforums[$forum->id] = $forum;
        }

        unset($modinfo);

    } // End foreach $courses

    return $readableforums;
}

/**
 * Returns a list of posts found using an array of search terms.
 *
 * @global object
 * @global object
 * @global object
 * @param array $searchterms array of search terms, e.g. word +word -word
 * @param int $courseid if 0, we search through the whole site
 * @param int $limitfrom
 * @param int $limitnum
 * @param int &$totalcount
 * @param string $extrasql
 * @return array|bool Array of posts found or false
 */
function hsuforum_search_posts($searchterms, $courseid=0, $limitfrom=0, $limitnum=50,
                            &$totalcount, $extrasql='') {
    global $CFG, $DB, $USER;
    require_once($CFG->libdir.'/searchlib.php');

    $forums = hsuforum_get_readable_forums($USER->id, $courseid);

    if (count($forums) == 0) {
        $totalcount = 0;
        return false;
    }

    $now = round(time(), -2); // db friendly

    $fullaccess = array();
    $where = array();
    $params = array('privatereply1' => $USER->id, 'privatereply2' => $USER->id);

    foreach ($forums as $forumid => $forum) {
        $select = array();

        if (!$forum->viewhiddentimedposts) {
            $select[] = "(d.userid = :userid{$forumid} OR (d.timestart < :timestart{$forumid} AND (d.timeend = 0 OR d.timeend > :timeend{$forumid})))";
            $params = array_merge($params, array('userid'.$forumid=>$USER->id, 'timestart'.$forumid=>$now, 'timeend'.$forumid=>$now));
        }

        if ($forum->type == 'qanda'
            && !has_capability('mod/hsuforum:viewqandawithoutposting', $forum->context)) {
            if (!empty($forum->onlydiscussions)) {
                list($discussionid_sql, $discussionid_params) = $DB->get_in_or_equal($forum->onlydiscussions, SQL_PARAMS_NAMED, 'qanda'.$forumid.'_');
                $params = array_merge($params, $discussionid_params);
                $select[] = "(d.id $discussionid_sql OR p.parent = 0)";
            } else {
                $select[] = "p.parent = 0";
            }
        }

        if (!empty($forum->onlygroups)) {
            list($groupid_sql, $groupid_params) = $DB->get_in_or_equal($forum->onlygroups, SQL_PARAMS_NAMED, 'grps'.$forumid.'_');
            $params = array_merge($params, $groupid_params);
            $select[] = "d.groupid $groupid_sql";
        }

        if ($select) {
            $selects = implode(" AND ", $select);
            $where[] = "(d.forum = :forum{$forumid} AND $selects)";
            $params['forum'.$forumid] = $forumid;
        } else {
            $fullaccess[] = $forumid;
        }
    }

    if ($fullaccess) {
        list($fullid_sql, $fullid_params) = $DB->get_in_or_equal($fullaccess, SQL_PARAMS_NAMED, 'fula');
        $params = array_merge($params, $fullid_params);
        $where[] = "(d.forum $fullid_sql)";
    }

    $selectdiscussion = "(".implode(" OR ", $where).")";

    $messagesearch = '';
    $searchstring = '';

    // Need to concat these back together for parser to work.
    foreach($searchterms as $searchterm){
        if ($searchstring != '') {
            $searchstring .= ' ';
        }
        $searchstring .= $searchterm;
    }

    // We need to allow quoted strings for the search. The quotes *should* be stripped
    // by the parser, but this should be examined carefully for security implications.
    $searchstring = str_replace("\\\"","\"",$searchstring);
    $parser = new search_parser();
    $lexer = new search_lexer($parser);

    if ($lexer->parse($searchstring)) {
        $parsearray = $parser->get_parsed_array();
        list($messagesearch, $msparams) = search_generate_SQL($parsearray, 'p.message', 'p.subject',
                                                              'p.userid', 'u.id', 'u.firstname',
                                                              'u.lastname', 'p.modified', 'd.forum');
        $params = array_merge($params, $msparams);
    }

    $fromsql = "{hsuforum_posts} p,
                  {hsuforum_discussions} d JOIN {hsuforum} f ON f.id = d.forum,
                  {user} u";

    foreach ($parsearray as $item){
        if ($item->getType() == TOKEN_USER || $item->getType() == TOKEN_USERID) {
            // Additional user SQL for anonymous posts.
            $extrasql .= " AND ((f.anonymous != 1 OR p.userid = :currentuserid) OR p.reveal = 1) ";
            $params['currentuserid'] = $USER->id;
            break;
        }
    }

    $selectsql = "(p.privatereply = 0
                OR p.privatereply = :privatereply1
                OR p.userid = :privatereply2
               )
               AND $messagesearch
               AND p.discussion = d.id
               AND p.userid = u.id
               AND $selectdiscussion
                   $extrasql";

    $countsql = "SELECT COUNT(*)
                   FROM $fromsql
                  WHERE $selectsql";

    $allnames = get_all_user_name_fields(true, 'u');
    $searchsql = "SELECT p.*,
                         d.forum,
                         $allnames,
                         u.email,
                         u.picture,
                         u.imagealt,
                         u.email
                    FROM $fromsql
                   WHERE $selectsql
                ORDER BY p.modified DESC";

    $totalcount = $DB->count_records_sql($countsql, $params);

    return $DB->get_records_sql($searchsql, $params, $limitfrom, $limitnum);
}

/**
 * Returns a list of ratings for a particular post - sorted.
 *
 * TODO: Check if this function is actually used anywhere.
 * Up until the fix for MDL-27471 this function wasn't even returning.
 *
 * @param stdClass $context
 * @param int $postid
 * @param string $sort
 * @return array Array of ratings or false
 */
function hsuforum_get_ratings($context, $postid, $sort = "u.firstname ASC") {
    $options = new stdClass;
    $options->context = $context;
    $options->component = 'mod_hsuforum';
    $options->ratingarea = 'post';
    $options->itemid = $postid;
    $options->sort = "ORDER BY $sort";

    $rm = new rating_manager();
    return $rm->get_all_ratings_for_item($options);
}

/**
 * Load ratings for a bunch of posts.
 *
 * @param context_module $context
 * @param object $forum
 * @param array $posts Ratings will be assigned to these items
 * @param null|string $returnurl
 * @param null|int $userid
 */
function hsuforum_get_ratings_for_posts(context_module $context, $forum, array $posts, $returnurl = null, $userid = null) {
    global $CFG, $USER;

    require_once($CFG->dirroot.'/rating/lib.php');

    if ($forum->assessed == RATING_AGGREGATE_NONE) {
        return;
    }
    if (empty($userid)) {
        $userid = $USER->id;
    }
    if (empty($returnurl)) {
        $returnurl = "$CFG->wwwroot/mod/hsuforum/view.php?id={$context->instanceid}";
    }
    $ratingoptions                   = new stdClass;
    $ratingoptions->context          = $context;
    $ratingoptions->component        = 'mod_hsuforum';
    $ratingoptions->ratingarea       = 'post';
    $ratingoptions->items            = $posts;
    $ratingoptions->aggregate        = $forum->assessed;
    $ratingoptions->scaleid          = $forum->scale;
    $ratingoptions->userid           = $userid;
    $ratingoptions->returnurl        = $returnurl;
    $ratingoptions->assesstimestart  = $forum->assesstimestart;
    $ratingoptions->assesstimefinish = $forum->assesstimefinish;

    $rm = new rating_manager();
    $rm->get_ratings($ratingoptions);
}

/**
 * Returns a list of all new posts that have not been mailed yet
 *
 * @param int $starttime posts created after this time
 * @param int $endtime posts created before this
 * @param int $now used for timed discussions only
 * @return array
 */
function hsuforum_get_unmailed_posts($starttime, $endtime, $now=null) {
    global $CFG, $DB;

    $params = array();
    $params['mailed'] = HSUFORUM_MAILED_PENDING;
    $params['ptimestart'] = $starttime;
    $params['ptimeend'] = $endtime;
    $params['mailnow'] = 1;

    $config = get_config('hsuforum');

    if (!empty($config->enabletimedposts)) {
        if (empty($now)) {
            $now = time();
        }
        $selectsql = "AND (p.created >= :ptimestart OR d.timestart >= :pptimestart)";
        $params['pptimestart'] = $starttime;
        $timedsql = "AND (d.timestart < :dtimestart AND (d.timeend = 0 OR d.timeend > :dtimeend))";
        $params['dtimestart'] = $now;
        $params['dtimeend'] = $now;
    } else {
        $timedsql = "";
        $selectsql = "AND p.created >= :ptimestart";
    }

    return $DB->get_records_sql("SELECT p.*, d.course, d.forum
                                 FROM {hsuforum_posts} p
                                 JOIN {hsuforum_discussions} d ON d.id = p.discussion
                                 WHERE p.mailed = :mailed
                                 $selectsql
                                 AND (p.created < :ptimeend OR p.mailnow = :mailnow)
                                 $timedsql
                                 ORDER BY p.modified ASC", $params);
}

/**
 * Marks posts before a certain time as being mailed already
 *
 * @global object
 * @global object
 * @param int $endtime
 * @param int $now Defaults to time()
 * @return bool
 */
function hsuforum_mark_old_posts_as_mailed($endtime, $now=null) {
    global $CFG, $DB;

    if (empty($now)) {
        $now = time();
    }

    $config = get_config('hsuforum');

    $params = array();
    $params['mailedsuccess'] = HSUFORUM_MAILED_SUCCESS;
    $params['now'] = $now;
    $params['endtime'] = $endtime;
    $params['mailnow'] = 1;
    $params['mailedpending'] = HSUFORUM_MAILED_PENDING;

    if (empty($config->enabletimedposts)) {
        return $DB->execute("UPDATE {hsuforum_posts}
                             SET mailed = :mailedsuccess
                             WHERE (created < :endtime OR mailnow = :mailnow)
                             AND mailed = :mailedpending", $params);
    } else {
        return $DB->execute("UPDATE {hsuforum_posts}
                             SET mailed = :mailedsuccess
                             WHERE discussion NOT IN (SELECT d.id
                                                      FROM {hsuforum_discussions} d
                                                      WHERE d.timestart > :now)
                             AND (created < :endtime OR mailnow = :mailnow)
                             AND mailed = :mailedpending", $params);
    }
}

/**
 * Get all the posts for a user in a forum suitable for rendering
 *
 * @global object
 * @uses CONTEXT_MODULE
 * @return array
 */
function hsuforum_get_user_posts($forumid, $userid, context_module $context = null) {
    global $DB;

    $timedsql = "";
    $params = array($forumid, $userid);

    $config = get_config('hsuforum');

    if (!empty($config->enabletimedposts)) {
        if (is_null($context)) {
            $cm = get_coursemodule_from_instance('hsuforum', $forumid);
            $context = context_module::instance($cm->id);
        }
        if (!has_capability('mod/hsuforum:viewhiddentimedposts' , $context)) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    $allnames = get_all_user_name_fields(true, 'u');
    return $DB->get_records_sql("SELECT p.*, d.forum, $allnames, u.email, u.picture, u.imagealt, r.lastread AS postread
                              FROM {hsuforum} f
                                   JOIN {hsuforum_discussions} d ON d.forum = f.id
                                   JOIN {hsuforum_posts} p       ON p.discussion = d.id
                                   JOIN {user} u              ON u.id = p.userid
                                   LEFT JOIN {hsuforum_read} r ON r.postid = p.id AND r.userid = u.id
                             WHERE f.id = ?
                                   AND p.userid = ?
                                   $timedsql
                          ORDER BY p.modified ASC", $params);
}

/**
 * Return all user ids that have at least one post in the forum.
 *
 *
 * @global object
 * @uses CONTEXT_MODULE
 * @return array
 */
function hsuforum_get_users_with_posts($forumid, context_module $context = null) {
    global $DB;

    $timedsql = "";
    $params['forumid'] = $forumid;

    $config = get_config('hsuforum');

    if (!empty($config->enabletimedposts)) {
        if (is_null($context)) {
            $cm = get_coursemodule_from_instance('hsuforum', $forumid);
            $context = context_module::instance($cm->id);
        }
        if (!has_capability('mod/hsuforum:viewhiddentimedposts' , $context)) {
            $now = time();
            $timedsql = "AND (d.timestart < :now1 AND (d.timeend = 0 OR d.timeend > :now2))";
            $params['now1'] = $now;
            $params['now2'] = $now;
        }
    }

    $sql = <<<SQL
        SELECT DISTINCT p.userid
          FROM {hsuforum} f

          JOIN {hsuforum_discussions} d
            ON d.forum = f.id

          JOIN {hsuforum_posts} p
            ON p.discussion = d.id

         WHERE f.id = :forumid
               $timedsql
SQL;
    return $DB->get_records_sql($sql, $params);
}

/**
 * Get all the discussions user participated in
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @param int $forumid
 * @param int $userid
 * @return array Array or false
 */
function hsuforum_get_user_involved_discussions($forumid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = array($forumid, $userid);

    $config = get_config('hsuforum');

    if (!empty($config->enabletimedposts)) {
        $cm = get_coursemodule_from_instance('hsuforum', $forumid);
        if (!has_capability('mod/hsuforum:viewhiddentimedposts' , context_module::instance($cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    return $DB->get_records_sql("SELECT DISTINCT d.*
                              FROM {hsuforum} f
                                   JOIN {hsuforum_discussions} d ON d.forum = f.id
                                   JOIN {hsuforum_posts} p       ON p.discussion = d.id
                             WHERE f.id = ?
                                   AND p.userid = ?
                                   $timedsql", $params);
}

/**
 * Get all the posts for a user in a forum suitable for rendering
 *
 * @global object
 * @global object
 * @param int $forumid
 * @param int $userid
 * @return array of counts or false
 */
function hsuforum_count_user_posts($forumid, $userid) {
    global $CFG, $DB;

    $config = get_config('hsuforum');

    $timedsql = "";
    $params = array($forumid, $userid);

    if (!empty($config->enabletimedposts)) {
        $cm = get_coursemodule_from_instance('hsuforum', $forumid);
        if (!has_capability('mod/hsuforum:viewhiddentimedposts' , context_module::instance($cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    return $DB->get_record_sql("SELECT COUNT(p.id) AS postcount, MAX(p.modified) AS lastpost
                             FROM {hsuforum} f
                                  JOIN {hsuforum_discussions} d ON d.forum = f.id
                                  JOIN {hsuforum_posts} p       ON p.discussion = d.id
                                  JOIN {user} u              ON u.id = p.userid
                            WHERE f.id = ?
                                  AND p.userid = ?
                                  $timedsql", $params);
}

/**
 * Given a discussion id, return the first post from the discussion
 *
 * @global object
 * @global object
 * @param int $dicsussionid
 * @return array
 */
function hsuforum_get_firstpost_from_discussion($discussionid) {
    global $CFG, $DB;

    return $DB->get_record_sql("SELECT p.*
                             FROM {hsuforum_discussions} d,
                                  {hsuforum_posts} p
                            WHERE d.id = ?
                              AND d.firstpost = p.id ", array($discussionid));
}

/**
 * Returns an array of counts of replies to each discussion
 *
 * @global object
 * @global object
 * @param int $forumid
 * @param string $forumsort
 * @param int $limit
 * @param int $page
 * @param int $perpage
 * @return array
 */
function hsuforum_count_discussion_replies($forumid, $forumsort="", $limit=-1, $page=-1, $perpage=0) {
    global $CFG, $DB;

    if ($limit > 0) {
        $limitfrom = 0;
        $limitnum  = $limit;
    } else if ($page != -1) {
        $limitfrom = $page*$perpage;
        $limitnum  = $perpage;
    } else {
        $limitfrom = 0;
        $limitnum  = 0;
    }

    if ($forumsort == "") {
        $orderby = "";
        $groupby = "";

    } else {
        $orderby = "ORDER BY $forumsort";
        $groupby = ", ".strtolower($forumsort);
        $groupby = str_replace('desc', '', $groupby);
        $groupby = str_replace('asc', '', $groupby);
    }

    if (($limitfrom == 0 and $limitnum == 0) or $forumsort == "") {
        $sql = "SELECT p.discussion, COUNT(p.id) AS replies, MAX(p.id) AS lastpostid
                  FROM {hsuforum_posts} p
                       JOIN {hsuforum_discussions} d ON p.discussion = d.id
                 WHERE p.parent > 0 AND d.forum = ?
              GROUP BY p.discussion";
        return $DB->get_records_sql($sql, array($forumid));

    } else {
        $sql = "SELECT p.discussion, (COUNT(p.id) - 1) AS replies, MAX(p.id) AS lastpostid
                  FROM {hsuforum_posts} p
                       JOIN {hsuforum_discussions} d ON p.discussion = d.id
                 WHERE d.forum = ?
              GROUP BY p.discussion $groupby $orderby";
        return $DB->get_records_sql($sql, array($forumid), $limitfrom, $limitnum);
    }
}

/**
 * @global object
 * @global object
 * @global object
 * @staticvar array $cache
 * @param object $forum
 * @param object $cm
 * @param object $course
 * @return mixed
 */
function hsuforum_count_discussions($forum, $cm, $course) {
    global $CFG, $DB, $USER;

    static $cache = array();

    $now = round(time(), -2); // db cache friendliness
    $config = get_config('hsuforum');

    $params = array($course->id);

    if (!isset($cache[$course->id])) {
        if (!empty($config->enabletimedposts)) {
            $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
            $params[] = $now;
            $params[] = $now;
        } else {
            $timedsql = "";
        }

        $sql = "SELECT f.id, COUNT(d.id) as dcount
                  FROM {hsuforum} f
                       JOIN {hsuforum_discussions} d ON d.forum = f.id
                 WHERE f.course = ?
                       $timedsql
              GROUP BY f.id";

        if ($counts = $DB->get_records_sql($sql, $params)) {
            foreach ($counts as $count) {
                $counts[$count->id] = $count->dcount;
            }
            $cache[$course->id] = $counts;
        } else {
            $cache[$course->id] = array();
        }
    }

    if (empty($cache[$course->id][$forum->id])) {
        return 0;
    }

    $groupmode = groups_get_activity_groupmode($cm, $course);

    if ($groupmode != SEPARATEGROUPS) {
        return $cache[$course->id][$forum->id];
    }

    if (has_capability('moodle/site:accessallgroups', context_module::instance($cm->id))) {
        return $cache[$course->id][$forum->id];
    }

    require_once($CFG->dirroot.'/course/lib.php');

    $modinfo = get_fast_modinfo($course);

    $mygroups = $modinfo->get_groups($cm->groupingid);

    // add all groups posts
    $mygroups[-1] = -1;

    list($mygroups_sql, $params) = $DB->get_in_or_equal($mygroups);
    $params[] = $forum->id;

    if (!empty($config->enabletimedposts)) {
        $timedsql = "AND d.timestart < $now AND (d.timeend = 0 OR d.timeend > $now)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    $sql = "SELECT COUNT(d.id)
              FROM {hsuforum_discussions} d
             WHERE d.groupid $mygroups_sql AND d.forum = ?
                   $timedsql";

    return $DB->get_field_sql($sql, $params);
}

/**
 * Get all discussions in a forum
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @param string $forumsort
 * @param bool $forumselect True == All post data, False == limited post data, String == custom select fields
 * @param int|array $limit If array, then it is two numbers, limit from and limit number
 * @param bool $userlastmodified
 * @param int $page
 * @param int $perpage
 * @param int $groupid if groups enabled, get discussions for this group overriding the current group.
 *                     Use HSUFORUM_POSTS_ALL_USER_GROUPS for all the user groups
 * @return moodle_recordset|array
 */
function hsuforum_get_discussions($cm, $forumsort="", $forumselect=true, $unused=-1, $limit=-1,
                                $userlastmodified=false, $page=-1, $perpage=0, $groupid = -1, $returnrs=true) {
    global $CFG, $DB, $USER;

    require_once(__DIR__.'/lib/discussion/subscribe.php');

    $config = get_config('hsuforum');
    $timelimit = '';

    $now = round(time(), -2);
    $cutoffdate = $now - ($config->oldpostdays*24*60*60);
    $params = array($cm->instance, $USER->id, $USER->id, $cm->instance);

    $modcontext = context_module::instance($cm->id);

    if (!has_capability('mod/hsuforum:viewdiscussion', $modcontext)) { /// User must have perms to view discussions
        return array();
    }

    $forum = $DB->get_record('hsuforum', array('id' => $cm->instance), '*', MUST_EXIST);

    $trackselect = ' unread.unread, dunread.postread, ';
    $tracksql    = 'LEFT OUTER JOIN (
        SELECT d.id, COUNT(p.id) AS unread
          FROM {hsuforum_discussions} d
          JOIN {hsuforum_posts} p ON p.discussion = d.id
     LEFT JOIN {hsuforum_read} r ON (r.postid = p.id AND r.userid = ?)
         WHERE d.forum = ?
           AND p.modified >= ? AND r.id is NULL
           AND (p.privatereply = 0 OR p.privatereply = ? OR p.userid = ?)
      GROUP BY d.id
    ) unread ON d.id = unread.id

LEFT OUTER JOIN (
        SELECT d.id, CASE WHEN r.id IS NULL THEN 0 ELSE 1 END AS postread
          FROM {hsuforum_discussions} d
          JOIN {hsuforum_posts} p ON p.discussion = d.id AND p.parent = 0
LEFT OUTER JOIN {hsuforum_read} r ON (r.postid = p.id AND r.userid = ?)
         WHERE d.forum = ?
           AND p.modified >= ?
    ) dunread ON d.id = dunread.id';

    $params = array_merge($params, array($USER->id, $cm->instance, $cutoffdate, $USER->id, $USER->id, $USER->id, $cm->instance, $cutoffdate));

    $subscribe = new hsuforum_lib_discussion_subscribe($forum, $modcontext);
    if ($subscribe->can_subscribe()) {
        $subscribeselect = ' sd.id AS subscriptionid, ';
        $subscribesql = 'LEFT OUTER JOIN {hsuforum_subscriptions_disc} sd ON d.id = sd.discussion AND sd.userid = ?';
        $params[] = $USER->id;
    } else {
        $subscribeselect = $subscribesql = '';
    }
    $params[] = $cm->instance;

    if (!empty($config->enabletimedposts)) { /// Users must fulfill timed posts

        if (!has_capability('mod/hsuforum:viewhiddentimedposts', $modcontext)) {
            $timelimit = " AND ((d.timestart <= ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
            if (isloggedin()) {
                $timelimit .= " OR d.userid = ?";
                $params[] = $USER->id;
            }
            $timelimit .= ")";
        }
    }

    if (is_array($limit)) {
        list($limitfrom, $limitnum) = $limit;
    } else if ($limit > 0) {
        $limitfrom = 0;
        $limitnum  = $limit;
    } else if ($page != -1) {
        $limitfrom = $page*$perpage;
        $limitnum  = $perpage;
    } else {
        $limitfrom = 0;
        $limitnum  = 0;
    }

    if (strpos($forumsort,'likes DESC') || strpos($forumsort,'likes ASC')) {
        $sortbylikes = ' LEFT JOIN (SELECT d.*, COUNT(d.id) AS likes
                                        FROM {hsuforum_discussions} d
                                            LEFT OUTER JOIN {hsuforum_actions} a ON d.firstpost = a.postid
                                            WHERE forum = ?
                                            GROUP BY d.firstpost
                                    ) likes ON d.id = likes.id';
        $params[] = $cm->instance;
    } else {
        $sortbylikes ='';
    }

    $groupmode    = groups_get_activity_groupmode($cm);

    if ($groupmode) {

        if (empty($modcontext)) {
            $modcontext = context_module::instance($cm->id);
        }

        // Special case, we received a groupid to override currentgroup.
        if ($groupid > 0) {
            $course = get_course($cm->course);
            if (!groups_group_visible($groupid, $course, $cm)) {
                // User doesn't belong to this group, return nothing.
                return array();
            }
            $currentgroup = $groupid;
        } else if ($groupid === -1) {
            $currentgroup = groups_get_activity_group($cm);
        } else {
            // Get discussions for all groups current user can see.
            $currentgroup = null;
        }

        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "";
            }

        } else {
            // Separate groups.

            // Get discussions for all groups current user can see.
            if ($currentgroup === null) {
                $mygroups = array_keys(groups_get_all_groups($cm->course, $USER->id, $cm->groupingid, 'g.id'));
                if (empty($mygroups)) {
                     $groupselect = "AND d.groupid = -1";
                } else {
                    list($insqlgroups, $inparamsgroups) = $DB->get_in_or_equal($mygroups);
                    $groupselect = "AND (d.groupid = -1 OR d.groupid $insqlgroups)";
                    $params = array_merge($params, $inparamsgroups);
                }
            } else if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    } else {
        $groupselect = "";
    }
    if (empty($forumsort)) {
        $forumsort = hsuforum_get_default_sort_order();
    }
    if (empty($forumselect)) {
        $postdata = "p.id,p.subject,p.modified,p.discussion,p.userid,p.reveal,p.flags,p.privatereply";
    } else {
        $postdata = "p.*";
    }

    if (empty($userlastmodified)) {  // We don't need to know this
        $umfields = "";
        $umtable  = "";
    } else {
        $umfields = ', up.reveal AS umreveal, ' . get_all_user_name_fields(true, 'um', null, 'um') . ', um.email AS umemail, um.picture AS umpicture,
                        um.imagealt AS umimagealt';
        $umtable  = " LEFT JOIN {user} um ON (d.usermodified = um.id)
                      LEFT OUTER JOIN {hsuforum_posts} up ON lastpost.postid = up.id";
    }

    // Sort of hacky, but allows for custom select
    if (is_string($forumselect) and !empty($forumselect)) {
        $selectsql = $forumselect;
    } else {
        $allnames  = get_all_user_name_fields(true, 'u');
        $selectsql = "$postdata, d.name, d.timemodified, d.usermodified, d.groupid, d.timestart, d.timeend, d.assessed, d.pinned,
                           d.firstpost, extra.replies, lastpost.postid lastpostid,$trackselect$subscribeselect
                           $allnames, u.email, u.picture, u.imagealt $umfields";
    }

    $sql = "SELECT $selectsql
              FROM {hsuforum_discussions} d
                   JOIN {hsuforum_posts} p ON p.discussion = d.id
                   JOIN {user} u ON p.userid = u.id
        LEFT OUTER JOIN (SELECT p.discussion, COUNT(p.id) AS replies
                           FROM {hsuforum_posts} p
                           JOIN {hsuforum_discussions} d ON p.discussion = d.id
                          WHERE p.parent > 0
                            AND d.forum = ?
                            AND (p.privatereply = 0 OR p.privatereply = ? OR p.userid = ?)
                          GROUP BY p.discussion) extra ON d.id = extra.discussion
              LEFT JOIN (SELECT p.discussion, p.id postid, p.userid, p.modified
                           FROM {hsuforum_discussions} d
                      LEFT JOIN {hsuforum_posts} p ON d.usermodified = p.userid AND d.id = p.discussion AND p.modified = d.timemodified
                          WHERE d.forum = ?) lastpost ON d.id = lastpost.discussion
                   $tracksql
                   $subscribesql
                   $umtable
                   $sortbylikes
             WHERE d.forum = ? AND p.parent = 0
                   $timelimit $groupselect
          ORDER BY $forumsort";
    if ($returnrs) {
        return $DB->get_recordset_sql($sql, $params, $limitfrom, $limitnum);
    }
    return $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
}

/**
 * Gets the neighbours (previous and next) of a discussion.
 *
 * The calculation is based on the timemodified of the discussion and does not handle
 * the neighbours having an identical timemodified. The reason is that we do not have any
 * other mean to sort the records, e.g. we cannot use IDs as a greater ID can have a lower
 * timemodified.
 *
 * For blog-style forums, the calculation is based on the original creation time of the
 * blog post.
 *
 * Please note that this does not check whether or not the discussion passed is accessible
 * by the user, it simply uses it as a reference to find the neighbours. On the other hand,
 * the returned neighbours are checked and are accessible to the current user.
 *
 * @param object $cm The CM record.
 * @param object $discussion The discussion record.
 * @param object $forum The forum instance record.
 * @return array That always contains the keys 'prev' and 'next'. When there is a result
 *               they contain the record with minimal information such as 'id' and 'name'.
 *               When the neighbour is not found the value is false.
 */
function hsuforum_get_discussion_neighbours($cm, $discussion, $forum) {
    global $CFG, $DB, $USER;

    $config = get_config('hsuforum');

    if ($cm->instance != $discussion->forum or $discussion->forum != $forum->id or $forum->id != $cm->instance) {
        throw new coding_exception('Discussion is not part of the same forum.');
    }

    $neighbours = array('prev' => false, 'next' => false);
    $now = round(time(), -2);
    $params = array();

    $modcontext = context_module::instance($cm->id);
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);


    // Users must fulfill timed posts.
    $timelimit = '';
    if (!empty($config->enabletimedposts)) {
        if (!has_capability('mod/hsuforum:viewhiddentimedposts', $modcontext)) {
            $timelimit = ' AND ((d.timestart <= :tltimestart AND (d.timeend = 0 OR d.timeend > :tltimeend))';
            $params['tltimestart'] = $now;
            $params['tltimeend'] = $now;
            if (isloggedin()) {
                $timelimit .= ' OR d.userid = :tluserid';
                $params['tluserid'] = $USER->id;
            }
            $timelimit .= ')';
        }
    }

    // Limiting to posts accessible according to groups.
    $groupselect = '';
    if ($groupmode) {
        if ($groupmode == VISIBLEGROUPS || has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = 'AND (d.groupid = :groupid OR d.groupid = -1)';
                $params['groupid'] = $currentgroup;
            }
        } else {
            if ($currentgroup) {
                $groupselect = 'AND (d.groupid = :groupid OR d.groupid = -1)';
                $params['groupid'] = $currentgroup;
            } else {
                $groupselect = 'AND d.groupid = -1';
            }
        }
    }

    if ($forum->type === 'blog') {
        $params['forumid'] = $cm->instance;
        $params['discid1'] = $discussion->id;
        $params['discid2'] = $discussion->id;

        $sql = "SELECT d.id, d.name, d.timemodified, d.groupid, d.timestart, d.timeend, d.pinned
                  FROM {hsuforum_discussions} d
                  JOIN {hsuforum_posts} p ON d.firstpost = p.id
                 WHERE d.forum = :forumid
                   AND d.id <> :discid1
                       $timelimit
                       $groupselect";

        $sub = "SELECT pp.created
                  FROM {hsuforum_discussions} dd
                  JOIN {hsuforum_posts} pp ON dd.firstpost = pp.id
                 WHERE dd.id = :discid2";

        $prevsql = $sql . " AND p.created < ($sub)
                       ORDER BY p.created DESC";

        $nextsql = $sql . " AND p.created > ($sub)
                       ORDER BY p.created ASC";

        $neighbours['prev'] = $DB->get_record_sql($prevsql, $params, IGNORE_MULTIPLE);
        $neighbours['next'] = $DB->get_record_sql($nextsql, $params, IGNORE_MULTIPLE);

    } else {
        $params['forumid'] = $cm->instance;
        $params['discid'] = $discussion->id;
        $params['disctimemodified'] = $discussion->timemodified;

        $sql = "SELECT d.id, d.name, d.timemodified, d.groupid, d.timestart, d.timeend
                  FROM {hsuforum_discussions} d
                 WHERE d.forum = :forumid
                   AND d.id <> :discid
                       $timelimit
                       $groupselect";

        if (empty($config->enabletimedposts)) {
            $prevsql = $sql . " AND d.timemodified < :disctimemodified";
            $nextsql = $sql . " AND d.timemodified > :disctimemodified";

        } else {
            // Normally we would just use the timemodified for sorting
            // discussion posts. However, when timed discussions are enabled,
            // then posts need to be sorted base on the later of timemodified
            // or the release date of the post (timestart).
            $params['disctimecompare'] = $discussion->timemodified;
            if ($discussion->timemodified < $discussion->timestart) {
                $params['disctimecompare'] = $discussion->timestart;
            }

            // Here we need to take into account the release time (timestart)
            // if one is set, of the neighbouring posts and compare it to the
            // timestart or timemodified of *this* post depending on if the
            // release date of this post is in the future or not.
            // This stops discussions that appear later because of the
            // timestart value from being buried under discussions that were
            // made afterwards.
            $prevsql = $sql . " AND CASE WHEN d.timemodified < d.timestart
                                    THEN d.timestart ELSE d.timemodified END < :disctimecompare";
            $nextsql = $sql . " AND CASE WHEN d.timemodified < d.timestart
                                    THEN d.timestart ELSE d.timemodified END > :disctimecompare";
        }
        $prevsql .= ' ORDER BY '.hsuforum_get_default_sort_order();
        $nextsql .= ' ORDER BY '.hsuforum_get_default_sort_order(false);

        $neighbours['prev'] = $DB->get_record_sql($prevsql, $params, IGNORE_MULTIPLE);
        $neighbours['next'] = $DB->get_record_sql($nextsql, $params, IGNORE_MULTIPLE);
    }

    return $neighbours;
}

/**
 * Get the sql to use in the ORDER BY clause for forum discussions.
 *
 * This has the ordering take timed discussion windows into account.
 *
 * @param bool $desc True for DESC, False for ASC.
 * @param string $compare The field in the SQL to compare to normally sort by.
 * @param string $prefix The prefix being used for the discussion table.
 * @return string
 */
function hsuforum_get_default_sort_order($desc = true, $compare = 'd.timemodified', $prefix = 'd', $pinned = true) {
    global $CFG;

    $config = get_config('hsuforum');

    if (!empty($prefix)) {
        $prefix .= '.';
    }

    $dir = $desc ? 'DESC' : 'ASC';

    if ($pinned == true) {
        $pinned = "{$prefix}pinned DESC,";
    } else {
        $pinned = '';
    }

    $sort = "{$prefix}timemodified";
    if (!empty($config->enabletimedposts)) {
        $sort = "CASE WHEN {$compare} < {$prefix}timestart
                 THEN {$prefix}timestart
                 ELSE {$compare}
                 END";
    }
    return "$pinned $sort $dir";
}

/**
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @return array
 */
function hsuforum_get_discussions_unread($cm) {
    global $CFG, $DB, $USER;

    $config = get_config('hsuforum');

    $now = round(time(), -2);
    $cutoffdate = $now - ($config->oldpostdays*24*60*60);

    $params = array();
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    if ($groupmode) {
        $modcontext = context_module::instance($cm->id);

        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = :currentgroup OR d.groupid = -1)";
                $params['currentgroup'] = $currentgroup;
            } else {
                $groupselect = "";
            }

        } else {
            //separate groups without access all
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = :currentgroup OR d.groupid = -1)";
                $params['currentgroup'] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    } else {
        $groupselect = "";
    }

    if (!empty($config->enabletimedposts)) {
        $timedsql = "AND d.timestart < :now1 AND (d.timeend = 0 OR d.timeend > :now2)";
        $params['now1'] = $now;
        $params['now2'] = $now;
    } else {
        $timedsql = "";
    }

    $sql = "SELECT d.id, COUNT(p.id) AS unread
              FROM {hsuforum_discussions} d
                   JOIN {hsuforum_posts} p     ON p.discussion = d.id
                   LEFT JOIN {hsuforum_read} r ON (r.postid = p.id AND r.userid = $USER->id)
             WHERE d.forum = {$cm->instance}
                   AND p.modified >= :cutoffdate AND r.id is NULL
                   $groupselect
                   $timedsql
          GROUP BY d.id";
    $params['cutoffdate'] = $cutoffdate;

    if ($unreads = $DB->get_records_sql($sql, $params)) {
        foreach ($unreads as $unread) {
            $unreads[$unread->id] = $unread->unread;
        }
        return $unreads;
    } else {
        return array();
    }
}
/**
 * @global object
 * @global object
 * @global object
 * @uses CONEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @return int
 */
function hsuforum_get_discussions_count($cm) {
    global $CFG, $DB, $USER;

    $config = get_config('hsuforum');
    $now = round(time(), -2);
    $params = array($cm->instance);
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    if ($groupmode) {
        $modcontext = context_module::instance($cm->id);

        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "";
            }

        } else {
            //seprate groups without access all
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    } else {
        $groupselect = "";
    }

    $timelimit = "";

    if (!empty($config->enabletimedposts)) {

        $modcontext = context_module::instance($cm->id);

        if (!has_capability('mod/hsuforum:viewhiddentimedposts', $modcontext)) {
            $timelimit = " AND ((d.timestart <= ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
            if (isloggedin()) {
                $timelimit .= " OR d.userid = ?";
                $params[] = $USER->id;
            }
            $timelimit .= ")";
        }
    }

    $sql = "SELECT COUNT(d.id)
              FROM {hsuforum_discussions} d
                   JOIN {hsuforum_posts} p ON p.discussion = d.id
             WHERE d.forum = ? AND p.parent = 0
                   $groupselect $timelimit";

    return $DB->get_field_sql($sql, $params);
}

/**
 * Get the list of potential subscribers to a forum.
 *
 * @param object $forumcontext the forum context.
 * @param integer $groupid the id of a group, or 0 for all groups.
 * @param string $fields the list of fields to return for each user. As for get_users_by_capability.
 * @param string $sort sort order. As for get_users_by_capability.
 * @return array list of users.
 */
function hsuforum_get_potential_subscribers($forumcontext, $groupid, $fields, $sort = '') {
    global $DB;

    // only active enrolled users or everybody on the frontpage
    list($esql, $params) = get_enrolled_sql($forumcontext, 'mod/hsuforum:allowforcesubscribe', $groupid, true);
    if (!$sort) {
        list($sort, $sortparams) = users_order_by_sql('u');
        $params = array_merge($params, $sortparams);
    }

    $sql = "SELECT $fields
              FROM {user} u
              JOIN ($esql) je ON je.id = u.id
          ORDER BY $sort";

    return $DB->get_records_sql($sql, $params);
}

/**
 * Returns list of user objects that are subscribed to this forum
 *
 * @global object
 * @global object
 * @param object $course the course
 * @param forum $forum the forum
 * @param integer $groupid group id, or 0 for all.
 * @param object $context the forum context, to save re-fetching it where possible.
 * @param string $fields requested user fields (with "u." table prefix)
 * @return array list of users.
 */
function hsuforum_subscribed_users($course, $forum, $groupid=0, $context = null, $fields = null) {
    global $CFG, $DB;

    $allnames = get_all_user_name_fields(true, 'u');
    if (empty($fields)) {
        $fields ="u.id,
                  u.username,
                  $allnames,
                  u.maildisplay,
                  u.mailformat,
                  u.maildigest,
                  u.imagealt,
                  u.email,
                  u.emailstop,
                  u.city,
                  u.country,
                  u.lastaccess,
                  u.lastlogin,
                  u.picture,
                  u.timezone,
                  u.theme,
                  u.lang,
                  u.trackforums,
                  u.mnethostid";
    }

    if (empty($context)) {
        $cm = get_coursemodule_from_instance('hsuforum', $forum->id, $course->id);
        $context = context_module::instance($cm->id);
    }

    if (hsuforum_is_forcesubscribed($forum)) {
        $results = hsuforum_get_potential_subscribers($context, $groupid, $fields, "u.email ASC");

    } else {
        // only active enrolled users or everybody on the frontpage
        list($esql, $params) = get_enrolled_sql($context, '', $groupid, true);
        $params['forumid'] = $forum->id;
        $results = $DB->get_records_sql("SELECT $fields
                                           FROM {user} u
                                           JOIN ($esql) je ON je.id = u.id
                                           JOIN {hsuforum_subscriptions} s ON s.userid = u.id
                                          WHERE s.forum = :forumid
                                       ORDER BY u.email ASC", $params);
    }

    // Guest user should never be subscribed to a forum.
    unset($results[$CFG->siteguest]);

    $cm = get_coursemodule_from_instance('hsuforum', $forum->id);
    $modinfo = get_fast_modinfo($cm->course);
    $mod = $modinfo->get_cm($cm->id);
    $info = new \core_availability\info_module($mod);
    return $info->filter_user_list($results);
}



// OTHER FUNCTIONS ///////////////////////////////////////////////////////////


/**
 * @global object
 * @global object
 * @param int $courseid
 * @param string $type
 */
function hsuforum_get_course_forum($courseid, $type) {
// How to set up special 1-per-course forums
    global $CFG, $DB, $OUTPUT, $USER;

    if ($forums = $DB->get_records_select("hsuforum", "course = ? AND type = ?", array($courseid, $type), "id ASC")) {
        // There should always only be ONE, but with the right combination of
        // errors there might be more.  In this case, just return the oldest one (lowest ID).
        foreach ($forums as $forum) {
            return $forum;   // ie the first one
        }
    }

    // Doesn't exist, so create one now.
    $forum = new stdClass();
    $forum->course = $courseid;
    $forum->type = "$type";
    if (!empty($USER->htmleditor)) {
        $forum->introformat = $USER->htmleditor;
    }
    switch ($forum->type) {
        case "news":
            $forum->name  = get_string("namenews", "hsuforum");
            $forum->intro = get_string("intronews", "hsuforum");
            $forum->forcesubscribe = HSUFORUM_FORCESUBSCRIBE;
            $forum->assessed = 0;
            if ($courseid == SITEID) {
                $forum->name  = get_string("sitenews");
                $forum->forcesubscribe = 0;
            }
            break;
        case "social":
            $forum->name  = get_string("namesocial", "hsuforum");
            $forum->intro = get_string("introsocial", "hsuforum");
            $forum->assessed = 0;
            $forum->forcesubscribe = 0;
            break;
        case "blog":
            $forum->name = get_string('blogforum', 'hsuforum');
            $forum->intro = get_string('introblog', 'hsuforum');
            $forum->assessed = 0;
            $forum->forcesubscribe = 0;
            break;
        default:
            echo $OUTPUT->notification("That forum type doesn't exist!");
            return false;
            break;
    }

    $forum->timemodified = time();
    $forum->id = $DB->insert_record("hsuforum", $forum);

    if (! $module = $DB->get_record("modules", array("name" => "hsuforum"))) {
        echo $OUTPUT->notification("Could not find hsuforum module!!");
        return false;
    }
    $mod = new stdClass();
    $mod->course = $courseid;
    $mod->module = $module->id;
    $mod->instance = $forum->id;
    $mod->section = 0;
    include_once("$CFG->dirroot/course/lib.php");
    if (! $mod->coursemodule = add_course_module($mod) ) {
        echo $OUTPUT->notification("Could not add a new course module to the course '" . $courseid . "'");
        return false;
    }
    $sectionid = course_add_cm_to_section($courseid, $mod->coursemodule, 0);
    return $DB->get_record("hsuforum", array("id" => "$forum->id"));
}

/**
 * Return rating related permissions
 *
 * @param string $options the context id
 * @return array an associative array of the user's rating permissions
 */
function hsuforum_rating_permissions($contextid, $component, $ratingarea) {
    $context = context::instance_by_id($contextid, MUST_EXIST);
    if ($component != 'mod_hsuforum' || $ratingarea != 'post') {
        // We don't know about this component/ratingarea so just return null to get the
        // default restrictive permissions.
        return null;
    }
    return array(
        'view'    => has_capability('mod/hsuforum:viewrating', $context),
        'viewany' => has_capability('mod/hsuforum:viewanyrating', $context),
        'viewall' => has_capability('mod/hsuforum:viewallratings', $context),
        'rate'    => has_capability('mod/hsuforum:rate', $context)
    );
}

/**
 * Validates a submitted rating
 * @param array $params submitted data
 *            context => object the context in which the rated items exists [required]
 *            component => The component for this module - should always be mod_hsuforum [required]
 *            ratingarea => object the context in which the rated items exists [required]
 *            itemid => int the ID of the object being rated [required]
 *            scaleid => int the scale from which the user can select a rating. Used for bounds checking. [required]
 *            rating => int the submitted rating [required]
 *            rateduserid => int the id of the user whose items have been rated. NOT the user who submitted the ratings. 0 to update all. [required]
 *            aggregation => int the aggregation method to apply when calculating grades ie RATING_AGGREGATE_AVERAGE [required]
 * @return boolean true if the rating is valid. Will throw rating_exception if not
 */
function hsuforum_rating_validate($params) {
    global $DB, $USER;

    // Check the component is mod_hsuforum
    if ($params['component'] != 'mod_hsuforum') {
        throw new rating_exception('invalidcomponent');
    }

    // Check the ratingarea is post (the only rating area in forum)
    if ($params['ratingarea'] != 'post') {
        throw new rating_exception('invalidratingarea');
    }

    // Check the rateduserid is not the current user .. you can't rate your own posts
    if ($params['rateduserid'] == $USER->id) {
        throw new rating_exception('nopermissiontorate');
    }

    // Fetch all the related records ... we need to do this anyway to call hsuforum_user_can_see_post
    $post = $DB->get_record('hsuforum_posts', array('id' => $params['itemid'], 'userid' => $params['rateduserid']), '*', MUST_EXIST);
    $discussion = $DB->get_record('hsuforum_discussions', array('id' => $post->discussion), '*', MUST_EXIST);
    $forum = $DB->get_record('hsuforum', array('id' => $discussion->forum), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $forum->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('hsuforum', $forum->id, $course->id , false, MUST_EXIST);
    $context = context_module::instance($cm->id);

    // Make sure the context provided is the context of the forum
    if ($context->id != $params['context']->id) {
        throw new rating_exception('invalidcontext');
    }

    if ($forum->scale != $params['scaleid']) {
        //the scale being submitted doesnt match the one in the database
        throw new rating_exception('invalidscaleid');
    }

    // check the item we're rating was created in the assessable time window
    if (!empty($forum->assesstimestart) && !empty($forum->assesstimefinish)) {
        if ($post->created < $forum->assesstimestart || $post->created > $forum->assesstimefinish) {
            throw new rating_exception('notavailable');
        }
    }

    //check that the submitted rating is valid for the scale

    // lower limit
    if ($params['rating'] < 0  && $params['rating'] != RATING_UNSET_RATING) {
        throw new rating_exception('invalidnum');
    }

    // upper limit
    if ($forum->scale < 0) {
        //its a custom scale
        $scalerecord = $DB->get_record('scale', array('id' => -$forum->scale));
        if ($scalerecord) {
            $scalearray = explode(',', $scalerecord->scale);
            if ($params['rating'] > count($scalearray)) {
                throw new rating_exception('invalidnum');
            }
        } else {
            throw new rating_exception('invalidscaleid');
        }
    } else if ($params['rating'] > $forum->scale) {
        //if its numeric and submitted rating is above maximum
        throw new rating_exception('invalidnum');
    }

    // Make sure groups allow this user to see the item they're rating
    if ($discussion->groupid > 0 and $groupmode = groups_get_activity_groupmode($cm, $course)) {   // Groups are being used
        if (!groups_group_exists($discussion->groupid)) { // Can't find group
            throw new rating_exception('cannotfindgroup');//something is wrong
        }
        if (!empty($discussion->unread) && $discussion->unread !== '-') {
            $replystring .= ' <span class="sep">/</span> <span class="unread">';
            if ($discussion->unread == 1) {
                $replystring .= get_string('unreadpostsone', 'hsuforum');
            } else {
                $replystring .= get_string('unreadpostsnumber', 'hsuforum', $discussion->unread);
            }
            $replystring .= '</span>';
        }

        if (!groups_is_member($discussion->groupid) and !has_capability('moodle/site:accessallgroups', $context)) {
            // do not allow rating of posts from other groups when in SEPARATEGROUPS or VISIBLEGROUPS
            throw new rating_exception('notmemberofgroup');
        }
    }

    // perform some final capability checks
    if (!hsuforum_user_can_see_post($forum, $discussion, $post, $USER, $cm)) {
        throw new rating_exception('nopermissiontorate');
    }

    return true;
}

/**
 * @global object
 * @param object $course
 * @param string $search
 * @return string
 */
function hsuforum_search_form($course, $forumid=null, $search='') {
    global $CFG;

    $output  = '<div class="hsuforum-search">';
    $output .= '<form action="'.$CFG->wwwroot.'/mod/hsuforum/search.php">';
    $output .= '<div class="invisiblefieldset">';
    $output .= '<input id="search" name="search" type="search" placeholder="'.get_string('search', 'hsuforum').'" value="'.s($search, true).'" title="Search Forums" />';
    $output .= '<button id="searchforums" type="submit" aria-label="Search Forums" title="Search Forums">
                    <i class="fa fa-search"></i>
                </button>';
    $output .= '<input name="id" type="hidden" value="'.$course->id.'" />';
    if ($forumid != null) {
        $output .= '<input name="forumid" type="hidden" value="'.s($forumid).'" />';
    }
    $output .= '</div>';
    $output .= '</form>';
    $output .= '</div>';

    return $output;
}


/**
 * @global object
 * @global object
 */
function hsuforum_set_return() {
    global $CFG, $SESSION;

    // If its an AJAX_SCRIPT then it makes no sense to set this variable.
    if (defined(AJAX_SCRIPT) && AJAX_SCRIPT) {
        unset($SESSION->fromdiscussion);
        return;
    }

    if (! isset($SESSION->fromdiscussion)) {
        $referer = get_local_referer(false);
        // If the referer is NOT a login screen then save it.
        if (! strncasecmp("$CFG->wwwroot/login", $referer, 300)) {
            $SESSION->fromdiscussion = $referer;
        }
    }
}

/**
 * Can the current user see ratings for a given itemid?
 *
 * @param array $params submitted data
 *            contextid => int contextid [required]
 *            component => The component for this module - should always be mod_hsuforum [required]
 *            ratingarea => object the context in which the rated items exists [required]
 *            itemid => int the ID of the object being rated [required]
 *            scaleid => int scale id [optional]
 * @return bool
 * @throws coding_exception
 * @throws rating_exception
 */
function mod_hsuforum_rating_can_see_item_ratings($params) {
    global $DB, $USER;

    // Check the component is mod_hsuforum.
    if (!isset($params['component']) || $params['component'] != 'mod_hsuforum') {
        throw new rating_exception('invalidcomponent');
    }

    // Check the ratingarea is post (the only rating area in forum).
    if (!isset($params['ratingarea']) || $params['ratingarea'] != 'post') {
        throw new rating_exception('invalidratingarea');
    }

    if (!isset($params['itemid'])) {
        throw new rating_exception('invaliditemid');
    }

    $post = $DB->get_record('hsuforum_posts', array('id' => $params['itemid']), '*', MUST_EXIST);
    $discussion = $DB->get_record('hsuforum_discussions', array('id' => $post->discussion), '*', MUST_EXIST);
    $forum = $DB->get_record('hsuforum', array('id' => $discussion->forum), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $forum->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('hsuforum', $forum->id, $course->id , false, MUST_EXIST);

    // Perform some final capability checks.
    if (!hsuforum_user_can_see_post($forum, $discussion, $post, $USER, $cm)) {
        return false;
    }
    return true;
}

/**
 * @global object
 * @param string|\moodle_url $default
 * @return string
 */
function hsuforum_go_back_to($default) {
    global $SESSION;

    if (!empty($SESSION->fromdiscussion)
        && (!defined(AJAX_SCRIPT) || !AJAX_SCRIPT)) {
        // If we have an ajax fromdiscussion session variable then we need to get rid of it because this is not an
        // ajax page and we will end up redirecting incorrectly to route.php.
        $murl = new moodle_url($SESSION->fromdiscussion);
        $path = $murl->get_path();
        if (strpos($path, '/mod/hsuforum/route.php') === 0) {
            // OK - this is bad, we are not using AJAX but the redirect url is an AJAX url, so kill it.
            unset($SESSION->fromdiscussion);
        }
    }

    if (!empty($SESSION->fromdiscussion)) {
        $returnto = $SESSION->fromdiscussion;
        unset($SESSION->fromdiscussion);
        return $returnto;
    } else {
        return $default;
    }
}

/**
 * Given a discussion object that is being moved to $forumto,
 * this function checks all posts in that discussion
 * for attachments, and if any are found, these are
 * moved to the new forum directory.
 *
 * @global object
 * @param object $discussion
 * @param int $forumfrom source forum id
 * @param int $forumto target forum id
 * @return bool success
 */
function hsuforum_move_attachments($discussion, $forumfrom, $forumto) {
    global $DB;

    $fs = get_file_storage();

    $newcm = get_coursemodule_from_instance('hsuforum', $forumto);
    $oldcm = get_coursemodule_from_instance('hsuforum', $forumfrom);

    $newcontext = context_module::instance($newcm->id);
    $oldcontext = context_module::instance($oldcm->id);

    // loop through all posts, better not use attachment flag ;-)
    if ($posts = $DB->get_records('hsuforum_posts', array('discussion'=>$discussion->id), '', 'id, attachment')) {
        foreach ($posts as $post) {
            $fs->move_area_files_to_new_context($oldcontext->id,
                    $newcontext->id, 'mod_hsuforum', 'post', $post->id);
            $attachmentsmoved = $fs->move_area_files_to_new_context($oldcontext->id,
                    $newcontext->id, 'mod_hsuforum', 'attachment', $post->id);
            if ($attachmentsmoved > 0 && $post->attachment != '1') {
                // Weird - let's fix it
                $post->attachment = '1';
                $DB->update_record('hsuforum_posts', $post);
            } else if ($attachmentsmoved == 0 && $post->attachment != '') {
                // Weird - let's fix it
                $post->attachment = '';
                $DB->update_record('hsuforum_posts', $post);
            }
        }
    }

    return true;
}

/**
 * Returns attachments as formated text/html optionally with separate images
 *
 * @global object
 * @global object
 * @global object
 * @param object $post
 * @param object $cm
 * @param string $type html/text/separateimages
 * @return mixed string or array of (html text withouth images and image HTML)
 */
function hsuforum_print_attachments($post, $cm, $type) {
    global $CFG, $DB, $USER, $OUTPUT;

    if (empty($post->attachment)) {
        return $type !== 'separateimages' ? '' : array('', '');
    }

    if (!in_array($type, array('separateimages', 'html', 'text'))) {
        return $type !== 'separateimages' ? '' : array('', '');
    }

    if (!$context = context_module::instance($cm->id)) {
        return $type !== 'separateimages' ? '' : array('', '');
    }
    $strattachment = get_string('attachment', 'hsuforum');

    $fs = get_file_storage();

    $imagereturn = '';
    $output = '';

    $canexport = !empty($CFG->enableportfolios) && empty(hsuforum_get_cm_forum($cm)->anonymous) && (has_capability('mod/hsuforum:exportpost', $context) || ($post->userid == $USER->id && has_capability('mod/hsuforum:exportownpost', $context)));

    if ($canexport) {
        require_once($CFG->libdir.'/portfoliolib.php');
    }

    // We retrieve all files according to the time that they were created.  In the case that several files were uploaded
    // at the sametime (e.g. in the case of drag/drop upload) we revert to using the filename.
    $files = $fs->get_area_files($context->id, 'mod_hsuforum', 'attachment', $post->id, "filename", false);
    if ($files) {
        if ($canexport) {
            $button = new portfolio_add_button();
        }
        foreach ($files as $file) {
            $filename = $file->get_filename();
            $mimetype = $file->get_mimetype();
            $iconimage = $OUTPUT->pix_icon(file_file_icon($file), get_mimetype_description($file), 'moodle', array('class' => 'icon'));
            $path = file_encode_url($CFG->wwwroot.'/pluginfile.php', '/'.$context->id.'/mod_hsuforum/attachment/'.$post->id.'/'.$filename);

            if ($type == 'html') {
                $output .= "<a href=\"$path\">$iconimage</a> ";
                $output .= "<a href=\"$path\">".s($filename)."</a>";
                if ($canexport) {
                    $button->set_callback_options('hsuforum_portfolio_caller', array('postid' => $post->id, 'attachment' => $file->get_id()), 'mod_hsuforum');
                    $button->set_format_by_file($file);
                    $output .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                }

            } else if ($type == 'text') {
                $output .= "$strattachment ".s($filename).":\n$path\n";

            } else { //'returnimages'
                if (in_array($mimetype, array('image/gif', 'image/jpeg', 'image/png'))) {
                    // Image attachments don't get printed as links
                    $imagereturn .= "<img src=\"$path\" alt=\"\" />";
                    if ($canexport) {
                        $button->set_callback_options('hsuforum_portfolio_caller', array('postid' => $post->id, 'attachment' => $file->get_id()), 'mod_hsuforum');
                        $button->set_format_by_file($file);
                        $imagereturn .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                    }
                } else {
                    $output .= "<a href=\"$path\">$iconimage</a> ";
                    $output .= format_text("<a href=\"$path\">".s($filename)."</a>", FORMAT_HTML, array('context'=>$context));
                    if ($canexport) {
                        $button->set_callback_options('hsuforum_portfolio_caller', array('postid' => $post->id, 'attachment' => $file->get_id()), 'mod_hsuforum');
                        $button->set_format_by_file($file);
                        $output .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                    }
                }
            }

            if (!empty($CFG->enableplagiarism)) {
                require_once($CFG->libdir.'/plagiarismlib.php');
                $output .= plagiarism_get_links(array('userid' => $post->userid,
                    'file' => $file,
                    'cmid' => $cm->id,
                    'course' => $cm->course,
                    'hsuforum' => $cm->instance));
            }
        }
    }

    if ($type !== 'separateimages') {
        return $output;

    } else {
        return array($output, $imagereturn);
    }
}

////////////////////////////////////////////////////////////////////////////////
// File API                                                                   //
////////////////////////////////////////////////////////////////////////////////

/**
 * Lists all browsable file areas
 *
 * @package  mod_hsuforum
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @return array
 */
function hsuforum_get_file_areas($course, $cm, $context) {
    return array(
        'attachment' => get_string('areaattachment', 'mod_hsuforum'),
        'post' => get_string('areapost', 'mod_hsuforum'),
    );
}

/**
 * File browsing support for forum module.
 *
 * @package  mod_hsuforum
 * @category files
 * @param stdClass $browser file browser object
 * @param stdClass $areas file areas
 * @param stdClass $course course object
 * @param stdClass $cm course module
 * @param stdClass $context context module
 * @param string $filearea file area
 * @param int $itemid item ID
 * @param string $filepath file path
 * @param string $filename file name
 * @return file_info instance or null if not found
 */
function hsuforum_get_file_info($browser, $areas, $course, $cm, $context, $filearea, $itemid, $filepath, $filename) {
    global $CFG, $DB, $USER;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return null;
    }

    // filearea must contain a real area
    if (!isset($areas[$filearea])) {
        return null;
    }

    // Note that hsuforum_user_can_see_post() additionally allows access for parent roles
    // and it explicitly checks qanda forum type, too. One day, when we stop requiring
    // course:managefiles, we will need to extend this.
    if (!has_capability('mod/hsuforum:viewdiscussion', $context)) {
        return null;
    }

    if (is_null($itemid)) {
        require_once($CFG->dirroot.'/mod/hsuforum/locallib.php');
        return new hsuforum_file_info_container($browser, $course, $cm, $context, $areas, $filearea);
    }

    static $cached = array();
    // $cached will store last retrieved post, discussion and forum. To make sure that the cache
    // is cleared between unit tests we check if this is the same session
    if (!isset($cached['sesskey']) || $cached['sesskey'] != sesskey()) {
        $cached = array('sesskey' => sesskey());
    }

    if (isset($cached['post']) && $cached['post']->id == $itemid) {
        $post = $cached['post'];
    } else if ($post = $DB->get_record('hsuforum_posts', array('id' => $itemid))) {
        $cached['post'] = $post;
    } else {
        return null;
    }

    if (isset($cached['discussion']) && $cached['discussion']->id == $post->discussion) {
        $discussion = $cached['discussion'];
    } else if ($discussion = $DB->get_record('hsuforum_discussions', array('id' => $post->discussion))) {
        $cached['discussion'] = $discussion;
    } else {
        return null;
    }

    if (isset($cached['forum']) && $cached['forum']->id == $cm->instance) {
        $forum = $cached['forum'];
    } else if ($forum = $DB->get_record('hsuforum', array('id' => $cm->instance))) {
        $cached['forum'] = $forum;
    } else {
        return null;
    }

    $fs = get_file_storage();
    $filepath = is_null($filepath) ? '/' : $filepath;
    $filename = is_null($filename) ? '.' : $filename;
    if (!($storedfile = $fs->get_file($context->id, 'mod_hsuforum', $filearea, $itemid, $filepath, $filename))) {
        return null;
    }

    // Checks to see if the user can manage files or is the owner.
    // TODO MDL-33805 - Do not use userid here and move the capability check above.
    if (!has_capability('moodle/course:managefiles', $context) && $storedfile->get_userid() != $USER->id) {
        return null;
    }
    // Make sure groups allow this user to see this file
    if ($discussion->groupid > 0 && !has_capability('moodle/site:accessallgroups', $context)) {
        $groupmode = groups_get_activity_groupmode($cm, $course);
        if ($groupmode == SEPARATEGROUPS && !groups_is_member($discussion->groupid)) {
            return null;
        }
    }

    // Make sure we're allowed to see it...
    if (!hsuforum_user_can_see_post($forum, $discussion, $post, NULL, $cm)) {
        return null;
    }

    $urlbase = $CFG->wwwroot.'/pluginfile.php';
    return new file_info_stored($browser, $context, $storedfile, $urlbase, $itemid, true, true, false, false);
}

/**
 * Serves the forum attachments. Implements needed access control ;-)
 *
 * @package  mod_hsuforum
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function hsuforum_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    global $CFG, $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_course_login($course, true, $cm);

    $areas = hsuforum_get_file_areas($course, $cm, $context);

    // Try comment area first. SC INT-4387.
    hsuforum_forum_comments_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, $options);

    // filearea must contain a real area
    if (!isset($areas[$filearea])) {
        return false;
    }

    $postid = (int)array_shift($args);

    if (!$post = $DB->get_record('hsuforum_posts', array('id'=>$postid))) {
        return false;
    }

    if (!$discussion = $DB->get_record('hsuforum_discussions', array('id'=>$post->discussion))) {
        return false;
    }

    if (!$forum = $DB->get_record('hsuforum', array('id'=>$cm->instance))) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_hsuforum/$filearea/$postid/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }

    // Make sure groups allow this user to see this file
    if ($discussion->groupid > 0) {
        $groupmode = groups_get_activity_groupmode($cm, $course);
        if ($groupmode == SEPARATEGROUPS) {
            if (!groups_is_member($discussion->groupid) and !has_capability('moodle/site:accessallgroups', $context)) {
                return false;
            }
        }
    }

    // Make sure we're allowed to see it...
    if (!hsuforum_user_can_see_post($forum, $discussion, $post, NULL, $cm)) {
        return false;
    }

    // finally send the file
    send_stored_file($file, 0, 0, true, $options); // download MUST be forced - security!
}

/**
 * If successful, this function returns the name of the file
 *
 * @global object
 * @param object $post is a full post record, including course and forum
 * @param object $forum
 * @param object $cm
 * @param mixed $mform
 * @param string $unused
 * @param \mod_hsuforum\upload_file $uploader
 * @return bool
 */
function hsuforum_add_attachment($post, $forum, $cm, $mform=null, $unused=null, \mod_hsuforum\upload_file $uploader = null) {
    global $DB;

    if ($uploader instanceof \mod_hsuforum\upload_file) {
        $files = $uploader->process_file_upload($post->id);
        $DB->set_field('hsuforum_posts', 'attachment', empty($files) ? 0 : 1, array('id' => $post->id));
        return true;
    }

    if (empty($mform)) {
        return false;
    }

    if (empty($post->attachments)) {
        return true;   // Nothing to do
    }

    $context = context_module::instance($cm->id);

    $info = file_get_draft_area_info($post->attachments);
    $present = ($info['filecount']>0) ? '1' : '';
    file_save_draft_area_files($post->attachments, $context->id, 'mod_hsuforum', 'attachment', $post->id,
            mod_hsuforum_post_form::attachment_options($forum));

    $DB->set_field('hsuforum_posts', 'attachment', $present, array('id'=>$post->id));

    return true;
}

/**
 * Add a new post in an existing discussion.
 *
 * @global object
 * @global object
 * @global object
 * @param object $post
 * @param mixed $mform
 * @param string $unused formerly $message, renamed in 2.8 as it was unused.
 * @param \mod_hsuforum\upload_file $uploader
 * @return int
 */
function hsuforum_add_new_post($post, $mform, $unused=null, \mod_hsuforum\upload_file $uploader = null) {
    global $USER, $CFG, $DB;

    $discussion = $DB->get_record('hsuforum_discussions', array('id' => $post->discussion));
    $forum      = $DB->get_record('hsuforum', array('id' => $discussion->forum));
    $cm         = get_coursemodule_from_instance('hsuforum', $forum->id);
    $context    = context_module::instance($cm->id);

    $post->created    = $post->modified = time();
    $post->mailed     = HSUFORUM_MAILED_PENDING;
    $post->userid     = $USER->id;
    if (!isset($post->totalscore)) {
        $post->totalscore = 0;
    }
    if (!isset($post->mailnow)) {
        $post->mailnow    = 0;
    }

    $filearea = 'post';
    $draftid = file_get_submitted_draft_itemid('hiddenadvancededitor');
    if (!$draftid) {
        $draftid = file_get_submitted_draft_itemid('message');
    }

    // Handle draftid's that has been passed via the post (typically mobile).
    if (isset($post->draftid)) {
        $draftid = $post->draftid;
        $filearea = 'attachment';
    }

    $post->id = $DB->insert_record("hsuforum_posts", $post);
    $post->message = file_save_draft_area_files($draftid, $context->id, 'mod_hsuforum', $filearea, $post->id,
            mod_hsuforum_post_form::editor_options($context, $post->id), $post->message);
    $DB->set_field('hsuforum_posts', 'message', $post->message, array('id'=>$post->id));
    hsuforum_add_attachment($post, $forum, $cm, $mform, null, $uploader);

    // Update discussion modified date
    if (empty($post->privatereply)) {
        $DB->set_field("hsuforum_discussions", "timemodified", $post->modified, array("id" => $post->discussion));
        $DB->set_field("hsuforum_discussions", "usermodified", $post->userid, array("id" => $post->discussion));
    }

    hsuforum_mark_post_read($post->userid, $post, $forum->id);

    // Let Moodle know that assessable content is uploaded (eg for plagiarism detection)
    hsuforum_trigger_content_uploaded_event($post, $cm, 'hsuforum_add_new_post');

    return $post->id;
}

/**
 * Update a post
 *
 * @global object
 * @global object
 * @global object
 * @param object $post
 * @param mixed $mform
 * @param string $message
 * @param \mod_hsuforum\upload_file $uploader
 * @return bool
 */
function hsuforum_update_post($post, $mform, &$message, \mod_hsuforum\upload_file $uploader = null) {
    global $USER, $CFG, $DB;

    $discussion = $DB->get_record('hsuforum_discussions', array('id' => $post->discussion));
    $forum      = $DB->get_record('hsuforum', array('id' => $discussion->forum));
    $cm         = get_coursemodule_from_instance('hsuforum', $forum->id);
    $context    = context_module::instance($cm->id);

    $post->modified = time();

    $DB->update_record('hsuforum_posts', $post);

    if (empty($post->privatereply)) {
        $discussion->timemodified = $post->modified; // last modified tracking
        $discussion->usermodified = $post->userid; // last modified tracking
    }

    if (!$post->parent) {   // Post is a discussion starter - update discussion title and times too
        $discussion->name      = $post->subject;
        $discussion->timestart = $post->timestart;
        $discussion->timeend   = $post->timeend;
        if (isset($post->pinned)) {
            $discussion->pinned = $post->pinned;
        }
    }

    $draftid = file_get_submitted_draft_itemid('hiddenadvancededitor');
    if (!$draftid) {
        $draftid = file_get_submitted_draft_itemid('message');
    }

    $post->message = file_save_draft_area_files($draftid, $context->id, 'mod_hsuforum', 'post', $post->id,
            mod_hsuforum_post_form::editor_options($context, $post->id), $post->message);
    $DB->set_field('hsuforum_posts', 'message', $post->message, array('id'=>$post->id));
    $DB->update_record('hsuforum_discussions', $discussion);

    hsuforum_add_attachment($post, $forum, $cm, $mform, $message, $uploader);

    hsuforum_mark_post_read($post->userid, $post, $post->forum);

    // Let Moodle know that assessable content is uploaded (eg for plagiarism detection)
    hsuforum_trigger_content_uploaded_event($post, $cm, 'hsuforum_update_post');

    return true;
}

/**
 * Given an object containing all the necessary data,
 * create a new discussion and return the id
 *
 * @param object $post
 * @param mixed $mform
 * @param string $unused
 * @param int $userid
 * @param \mod_hsuforum\upload_file $uploader
 * @return object
 */
function hsuforum_add_discussion($discussion, $mform=null, $unused=null, $userid=null, \mod_hsuforum\upload_file $uploader = null) {
    global $USER, $CFG, $DB;

    $timenow = time();

    if (is_null($userid)) {
        $userid = $USER->id;
    }

    // The first post is stored as a real post, and linked
    // to from the discuss entry.

    $forum = $DB->get_record('hsuforum', array('id'=>$discussion->forum));
    $cm    = get_coursemodule_from_instance('hsuforum', $forum->id);

    $post = new stdClass();
    $post->discussion    = 0;
    $post->parent        = 0;
    $post->userid        = $userid;
    $post->created       = $timenow;
    $post->modified      = $timenow;
    $post->mailed        = HSUFORUM_MAILED_PENDING;
    $post->subject       = $discussion->name;
    $post->message       = $discussion->message;
    $post->messageformat = $discussion->messageformat;
    $post->messagetrust  = $discussion->messagetrust;
    $post->attachments   = isset($discussion->attachments) ? $discussion->attachments : null;
    $post->forum         = $forum->id;     // speedup
    $post->course        = $forum->course; // speedup
    $post->mailnow       = $discussion->mailnow;
    $post->reveal        = $discussion->reveal;

    if (!is_null($mform)) {
        $data = $mform->get_data();
        if (!empty($data->reveal)) {
            $post->reveal = 1;
        }
    }
    $post->id = $DB->insert_record("hsuforum_posts", $post);

    // TODO: Fix the calling code so that there always is a $cm when this function is called
    if (!empty($cm->id) && !empty($discussion->itemid)) {   // In "single simple discussions" this may not exist yet
        $context = context_module::instance($cm->id);
        $text = file_save_draft_area_files($discussion->itemid, $context->id, 'mod_hsuforum', 'post', $post->id,
                mod_hsuforum_post_form::editor_options($context, null), $post->message);
        $DB->set_field('hsuforum_posts', 'message', $text, array('id'=>$post->id));
    }

    // Now do the main entry for the discussion, linking to this first post

    $discussion->firstpost    = $post->id;
    $discussion->timemodified = $timenow;
    $discussion->usermodified = $post->userid;
    $discussion->userid       = $userid;
    $discussion->assessed     = 0;

    $post->discussion = $DB->insert_record("hsuforum_discussions", $discussion);

    // Finally, set the pointer on the post.
    $DB->set_field("hsuforum_posts", "discussion", $post->discussion, array("id"=>$post->id));

    if (!empty($cm->id)) {
        hsuforum_add_attachment($post, $forum, $cm, $mform, $unused, $uploader);
    }

    hsuforum_mark_post_read($post->userid, $post, $post->forum);

    // Let Moodle know that assessable content is uploaded (eg for plagiarism detection)
    if (!empty($cm->id)) {
        hsuforum_trigger_content_uploaded_event($post, $cm, 'hsuforum_add_discussion');
    }

    $draftid = file_get_submitted_draft_itemid('hiddenadvancededitor');
    if (!$draftid) {
        $draftid = file_get_submitted_draft_itemid('message');
    }

    // Handle mobile app file upload references
    if ($discussion->mobiledraftid && $discussion->mobiledraftid > 0) {
        $fileoptions = array('subdirs' => false, 'maxbytes' => $forum->maxbytes, 'maxfiles' => $forum->maxattachments);
        $context = context_module::instance($cm->id);

        file_save_draft_area_files($discussion->mobiledraftid, $context->id, 'mod_hsuforum',
            'attachment', $post->id, $fileoptions);
    }

    if ($draftid) {
        $context = context_module::instance($cm->id);
        $post->message = file_save_draft_area_files($draftid, $context->id, 'mod_hsuforum', 'post', $post->id,
            mod_hsuforum_post_form::editor_options($context, $post->id), $post->message);
        $DB->set_field('hsuforum_posts', 'message', $post->message, array('id' => $post->id));
    }

    return $post->discussion;
}

/**
 * Verify and delete the post.  The post can be a discussion post.
 *
 * @param object $course
 * @param object $cm
 * @param object $forum
 * @param context_module $modcontext
 * @param object $discussion
 * @param object $post
 * @return string The URL to redirect to
 */
function hsuforum_verify_and_delete_post($course, $cm, $forum, $modcontext, $discussion, $post) {
    global $CFG, $USER;

    // Check user capability to delete post.
    $timepassed = time() - $post->created;
    if (($timepassed > $CFG->maxeditingtime) && !has_capability('mod/hsuforum:deleteanypost', $modcontext)) {
        print_error("cannotdeletepost", "hsuforum",
            hsuforum_go_back_to("discuss.php?d=$post->discussion"));
    }
    if ($post->totalscore) {
        print_error('couldnotdeleteratings', 'rating',
            hsuforum_go_back_to("discuss.php?d=$post->discussion"));
    }
    if (hsuforum_count_replies($post) && !has_capability('mod/hsuforum:deleteanypost', $modcontext)) {
        print_error("couldnotdeletereplies", "hsuforum",
            hsuforum_go_back_to("discuss.php?d=$post->discussion"));
    }
    if (!$post->parent) { // post is a discussion topic as well, so delete discussion
        if ($forum->type == 'single') {
            print_error('cannnotdeletesinglediscussion', 'hsuforum',
                hsuforum_go_back_to("discuss.php?d=$post->discussion"));
        }
        hsuforum_delete_discussion($discussion, false, $course, $cm, $forum);

        $params = array(
            'objectid' => $discussion->id,
            'context' => $modcontext,
            'other' => array(
                'forumid' => $forum->id,
            )
        );

        $event = \mod_hsuforum\event\discussion_deleted::create($params);
        $event->add_record_snapshot('hsuforum_discussions', $discussion);
        $event->trigger();

        return $CFG->wwwroot."/mod/hsuforum/view.php?id=$cm->id";

    }
    if (!hsuforum_delete_post($post, has_capability('mod/hsuforum:deleteanypost', $modcontext), $course, $cm, $forum)) {
        print_error('errorwhiledelete', 'hsuforum');
    }
    if ($forum->type == 'single') {
        // Single discussion forums are an exception. We show
        // the forum itself since it only has one discussion
        // thread.
        $discussionurl = "view.php?f=$forum->id";
    } else {
        $discussionurl = "discuss.php?d=$post->discussion";
    }

    $params = array(
        'context' => $modcontext,
        'objectid' => $post->id,
        'other' => array(
            'discussionid' => $discussion->id,
            'forumid' => $forum->id,
            'forumtype' => $forum->type,
        )
    );

    if ($post->userid !== $USER->id) {
        $params['relateduserid'] = $post->userid;
    }
    $event = \mod_hsuforum\event\post_deleted::create($params);
    $event->add_record_snapshot('hsuforum_posts', $post);
    $event->add_record_snapshot('hsuforum_discussions', $discussion);
    $event->trigger();

    return hsuforum_go_back_to($discussionurl);
}

/**
 * Deletes a discussion and handles all associated cleanup.
 *
 * @global object
 * @param object $discussion Discussion to delete
 * @param bool $fulldelete True when deleting entire forum
 * @param object $course Course
 * @param object $cm Course-module
 * @param object $forum Forum
 * @return bool
 */
function hsuforum_delete_discussion($discussion, $fulldelete, $course, $cm, $forum) {
    global $DB, $CFG;
    require_once($CFG->libdir.'/completionlib.php');

    $result = true;

    if ($posts = $DB->get_records("hsuforum_posts", array("discussion" => $discussion->id))) {
        foreach ($posts as $post) {
            $post->course = $discussion->course;
            $post->forum  = $discussion->forum;
            if (!hsuforum_delete_post($post, 'ignore', $course, $cm, $forum, $fulldelete)) {
                $result = false;
            }
        }
    }

    hsuforum_delete_read_records_for_discussion($discussion->id);

    if (!$DB->delete_records("hsuforum_discussions", array("id"=>$discussion->id))) {
        $result = false;
    }
    if (!$DB->delete_records('hsuforum_subscriptions_disc', array('discussion' => $discussion->id))) {
        $result = false;
    }

    // Update completion state if we are tracking completion based on number of posts
    // But don't bother when deleting whole thing
    if (!$fulldelete) {
        $completion = new completion_info($course);
        if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC &&
           ($forum->completiondiscussions || $forum->completionreplies || $forum->completionposts)) {
            $completion->update_state($cm, COMPLETION_INCOMPLETE, $discussion->userid);
        }
    }

    return $result;
}


/**
 * Deletes a single forum post.
 *
 * @global object
 * @param object $post Forum post object
 * @param mixed $children Whether to delete children. If false, returns false
 *   if there are any children (without deleting the post). If true,
 *   recursively deletes all children. If set to special value 'ignore', deletes
 *   post regardless of children (this is for use only when deleting all posts
 *   in a disussion).
 * @param object $course Course
 * @param object $cm Course-module
 * @param object $forum Forum
 * @param bool $skipcompletion True to skip updating completion state if it
 *   would otherwise be updated, i.e. when deleting entire forum anyway.
 * @return bool
 */
function hsuforum_delete_post($post, $children, $course, $cm, $forum, $skipcompletion=false) {
    global $DB, $CFG, $USER;
    require_once($CFG->libdir.'/completionlib.php');

    $context = context_module::instance($cm->id);

    if ($children !== 'ignore' && ($childposts = $DB->get_records('hsuforum_posts', array('parent'=>$post->id)))) {
       if ($children) {
           foreach ($childposts as $childpost) {
               hsuforum_delete_post($childpost, true, $course, $cm, $forum, $skipcompletion);
           }
       } else {
           return false;
       }
    }

    //delete ratings
    require_once($CFG->dirroot.'/rating/lib.php');
    $delopt = new stdClass;
    $delopt->contextid = $context->id;
    $delopt->component = 'mod_hsuforum';
    $delopt->ratingarea = 'post';
    $delopt->itemid = $post->id;
    $rm = new rating_manager();
    $rm->delete_ratings($delopt);

    //delete attachments
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'mod_hsuforum', 'attachment', $post->id);
    $fs->delete_area_files($context->id, 'mod_hsuforum', 'post', $post->id);

    if ($DB->delete_records("hsuforum_posts", array("id" => $post->id))) {

        hsuforum_delete_read_records_for_post($post->id);

    // Just in case we are deleting the last post
        hsuforum_discussion_update_last_post($post->discussion);

        // Update completion state if we are tracking completion based on number of posts
        // But don't bother when deleting whole thing

        if (!$skipcompletion) {
            $completion = new completion_info($course);
            if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC &&
               ($forum->completiondiscussions || $forum->completionreplies || $forum->completionposts)) {
                $completion->update_state($cm, COMPLETION_INCOMPLETE, $post->userid);
            }
        }

        $params = array(
            'context' => $context,
            'objectid' => $post->id,
            'other' => array(
                'discussionid' => $post->discussion,
                'forumid' => $forum->id,
                'forumtype' => $forum->type,
            )
        );
        if ($post->userid !== $USER->id) {
            $params['relateduserid'] = $post->userid;
        }
        $event = \mod_hsuforum\event\post_deleted::create($params);
        $event->add_record_snapshot('hsuforum_posts', $post);
        $event->trigger();

        return true;
    }
    return false;
}

/**
 * Sends post content to plagiarism plugin
 * @param object $post Forum post object
 * @param object $cm Course-module
 * @param string $name
 * @return bool
*/
function hsuforum_trigger_content_uploaded_event($post, $cm, $name) {
    $context = context_module::instance($cm->id);
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_hsuforum', 'attachment', $post->id, "timemodified", false);
    $params = array(
        'context' => $context,
        'objectid' => $post->id,
        'other' => array(
            'content' => $post->message,
            'pathnamehashes' => array_keys($files),
            'discussionid' => $post->discussion,
            'triggeredfrom' => $name,
        )
    );
    $event = \mod_hsuforum\event\assessable_uploaded::create($params);
    $event->trigger();
    return true;
}

/**
 * @global object
 * @param object $post
 * @param bool $children
 * @return int
 */
function hsuforum_count_replies($post, $children=true) {
    global $DB, $USER;
    $count = 0;

    $select = 'parent = ? AND (privatereply = 0 OR privatereply = ? OR userid = ?)';
    $params = array($post->id, $USER->id, $USER->id);

    if ($children) {
        if ($childposts = $DB->get_records_select('hsuforum_posts', $select, $params)) {
           foreach ($childposts as $childpost) {
               $count ++;                   // For this child
               $count += hsuforum_count_replies($childpost, true);
           }
        }
    } else {
        $count += $DB->count_records_select('hsuforum_posts', $select, $params);
    }

    return $count;
}


/**
 * @global object
 * @param int $forumid
 * @param mixed $value
 * @return bool
 */
function hsuforum_forcesubscribe($forumid, $value=1) {
    global $DB;
    return $DB->set_field("hsuforum", "forcesubscribe", $value, array("id" => $forumid));
}

/**
 * @global object
 * @param object $forum
 * @return bool
 */
function hsuforum_is_forcesubscribed($forum) {
    global $DB;
    if (isset($forum->forcesubscribe)) {    // then we use that
        return ($forum->forcesubscribe == HSUFORUM_FORCESUBSCRIBE);
    } else {   // Check the database
       return ($DB->get_field('hsuforum', 'forcesubscribe', array('id' => $forum)) == HSUFORUM_FORCESUBSCRIBE);
    }
}

function hsuforum_get_forcesubscribed($forum) {
    global $DB;
    if (isset($forum->forcesubscribe)) {    // then we use that
        return $forum->forcesubscribe;
    } else {   // Check the database
        return $DB->get_field('hsuforum', 'forcesubscribe', array('id' => $forum));
    }
}

/**
 * @global object
 * @param int $userid
 * @param object $forum
 * @return bool
 */
function hsuforum_is_subscribed($userid, $forum) {
    global $DB;
    if (is_numeric($forum)) {
        $forum = $DB->get_record('hsuforum', array('id' => $forum));
    }
    // If forum is force subscribed and has allowforcesubscribe, then user is subscribed.
    $cm = get_coursemodule_from_instance('hsuforum', $forum->id);
    if (hsuforum_is_forcesubscribed($forum) && $cm &&
            has_capability('mod/hsuforum:allowforcesubscribe', context_module::instance($cm->id), $userid)) {
        return true;
    }
    return $DB->record_exists("hsuforum_subscriptions", array("userid" => $userid, "forum" => $forum->id));
}

function hsuforum_get_subscribed_forums($course) {
    global $USER, $CFG, $DB;
    $sql = "SELECT f.id
              FROM {hsuforum} f
                   LEFT JOIN {hsuforum_subscriptions} fs ON (fs.forum = f.id AND fs.userid = ?)
             WHERE f.course = ?
                   AND (f.forcesubscribe = ".HSUFORUM_FORCESUBSCRIBE." OR fs.id IS NOT NULL)";
    if ($subscribed = $DB->get_records_sql($sql, array($USER->id, $course->id))) {
        foreach ($subscribed as $s) {
            $subscribed[$s->id] = $s->id;
        }
        return $subscribed;
    } else {
        return array();
    }
}

/**
 * Returns an array of forums that the current user is subscribed to and is allowed to unsubscribe from
 *
 * @return array An array of unsubscribable forums
 */
function hsuforum_get_optional_subscribed_forums() {
    global $USER, $DB;

    // Get courses that $USER is enrolled in and can see
    $courses = enrol_get_my_courses();
    if (empty($courses)) {
        return array();
    }

    $courseids = array();
    foreach($courses as $course) {
        $courseids[] = $course->id;
    }
    list($coursesql, $courseparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');

    // get all forums from the user's courses that they are subscribed to and which are not set to forced
    $sql = "SELECT f.id, cm.id as cm, cm.visible
              FROM {hsuforum} f
                   JOIN {course_modules} cm ON cm.instance = f.id
                   JOIN {modules} m ON m.name = :modulename AND m.id = cm.module
                   LEFT JOIN {hsuforum_subscriptions} fs ON (fs.forum = f.id AND fs.userid = :userid)
             WHERE f.forcesubscribe <> :forcesubscribe AND fs.id IS NOT NULL
                   AND cm.course $coursesql";
    $params = array_merge($courseparams, array('modulename'=>'hsuforum', 'userid'=>$USER->id, 'forcesubscribe'=>HSUFORUM_FORCESUBSCRIBE));
    if (!$forums = $DB->get_records_sql($sql, $params)) {
        return array();
    }

    $unsubscribableforums = array(); // Array to return

    foreach($forums as $forum) {

        if (empty($forum->visible)) {
            // the forum is hidden
            $context = context_module::instance($forum->cm);
            if (!has_capability('moodle/course:viewhiddenactivities', $context)) {
                // the user can't see the hidden forum
                continue;
            }
        }

        // subscribe.php only requires 'mod/hsuforum:managesubscriptions' when
        // unsubscribing a user other than yourself so we don't require it here either

        // A check for whether the forum has subscription set to forced is built into the SQL above

        $unsubscribableforums[] = $forum;
    }

    return $unsubscribableforums;
}

/**
 * Adds user to the subscriber list
 *
 * @param int $userid
 * @param int $forumid
 * @param context_module|null $context Module context, may be omitted if not known or if called for the current module set in page.
 */
function hsuforum_subscribe($userid, $forumid, $context = null) {
    global $DB, $PAGE;

    require_once(__DIR__.'/repository/discussion.php');

    $repo = new hsuforum_repository_discussion();
    $repo->unsubscribe_all($forumid, $userid);

    if ($DB->record_exists("hsuforum_subscriptions", array("userid"=>$userid, "forum"=>$forumid))) {
        return true;
    }

    $sub = new stdClass();
    $sub->userid  = $userid;
    $sub->forum = $forumid;

    $result = $DB->insert_record("hsuforum_subscriptions", $sub);

    if (!$context) {
        // Find out forum context. First try to take current page context to save on DB query.
        if ($PAGE->cm && $PAGE->cm->modname === 'hsuforum' && $PAGE->cm->instance == $forumid
                && $PAGE->context->contextlevel == CONTEXT_MODULE && $PAGE->context->instanceid == $PAGE->cm->id) {
            $context = $PAGE->context;
        } else {
            $cm = get_coursemodule_from_instance('hsuforum', $forumid);
            $context = context_module::instance($cm->id);
        }
    }
    $params = array(
        'context' => $context,
        'objectid' => $result,
        'relateduserid' => $userid,
        'other' => array('forumid' => $forumid),

    );
    $event  = \mod_hsuforum\event\subscription_created::create($params);
    $event->trigger();

    return $result;
}

/**
 * Removes user from the subscriber list
 *
 * @global object
 * @param int $userid
 * @param int $forumid
 * @param context_module|null $context Module context, may be omitted if not known or if called for the current module set in page.
 */
function hsuforum_unsubscribe($userid, $forumid, $context = null) {
    global $DB, $PAGE;

    $DB->delete_records('hsuforum_digests', array('userid' => $userid, 'forum' => $forumid));

    if ($forumsubscription = $DB->get_record('hsuforum_subscriptions', array('userid' => $userid, 'forum' => $forumid))) {
        $DB->delete_records('hsuforum_subscriptions', array('id' => $forumsubscription->id));

        if (!$context) {
            // Find out forum context. First try to take current page context to save on DB query.
            if ($PAGE->cm && $PAGE->cm->modname === 'hsuforum' && $PAGE->cm->instance == $forumid
                    && $PAGE->context->contextlevel == CONTEXT_MODULE && $PAGE->context->instanceid == $PAGE->cm->id) {
                $context = $PAGE->context;
            } else {
                $cm = get_coursemodule_from_instance('hsuforum', $forumid);
                $context = context_module::instance($cm->id);
            }
        }
        $params = array(
            'context' => $context,
            'objectid' => $forumsubscription->id,
            'relateduserid' => $userid,
            'other' => array('forumid' => $forumid),

        );
        $event = \mod_hsuforum\event\subscription_deleted::create($params);
        $event->add_record_snapshot('hsuforum_subscriptions', $forumsubscription);
        $event->trigger();
    }

    return true;
}

/**
 * Given a new post, subscribes or unsubscribes as appropriate.
 * Returns some text which describes what happened.
 *
 * @global objec
 * @param object $post
 * @param object $forum
 */
function hsuforum_post_subscription($post, $forum) {

    global $USER;

    $action = '';
    $subscribed = hsuforum_is_subscribed($USER->id, $forum);

    if ($forum->forcesubscribe == HSUFORUM_FORCESUBSCRIBE) { // database ignored
        return "";

    } elseif (($forum->forcesubscribe == HSUFORUM_DISALLOWSUBSCRIBE)
        && !has_capability('moodle/course:manageactivities', context_course::instance($forum->course), $USER->id)) {
        if ($subscribed) {
            $action = 'unsubscribe'; // sanity check, following MDL-14558
        } else {
            return "";
        }

    } else { // go with the user's choice
        if (isset($post->subscribe)) {
            // no change
            if ((!empty($post->subscribe) && $subscribed)
                || (empty($post->subscribe) && !$subscribed)) {
                return "";

            } elseif (!empty($post->subscribe) && !$subscribed) {
                $action = 'subscribe';

            } elseif (empty($post->subscribe) && $subscribed) {
                $action = 'unsubscribe';
            }
        }
    }

    $info = new stdClass();
    $info->name  = fullname($USER);
    $info->forum = format_string($forum->name);

    switch ($action) {
        case 'subscribe':
            hsuforum_subscribe($USER->id, $post->forum);
            return "<p>".get_string("nowsubscribed", "hsuforum", $info)."</p>";
        case 'unsubscribe':
            hsuforum_unsubscribe($USER->id, $post->forum);
            return "<p>".get_string("nownotsubscribed", "hsuforum", $info)."</p>";
    }
}

/**
 * Generate and return the subscribe or unsubscribe link for a forum.
 *
 * @param object $forum the forum. Fields used are $forum->id and $forum->forcesubscribe.
 * @param object $context the context object for this forum.
 * @param array $messages text used for the link in its various states
 *      (subscribed, unsubscribed, forcesubscribed or cantsubscribe).
 *      Any strings not passed in are taken from the $defaultmessages array
 *      at the top of the function.
 * @param bool $cantaccessagroup
 * @param bool $fakelink
 * @param bool $backtoindex
 * @param array $subscribed_forums
 * @return string
 */
function hsuforum_get_subscribe_link($forum, $context, $messages = array(), $cantaccessagroup = false, $fakelink=true, $backtoindex=false, $subscribed_forums=null) {
    global $USER, $PAGE, $OUTPUT;
    $defaultmessages = array(
        'subscribed' => get_string('unsubscribe', 'hsuforum'),
        'unsubscribed' => get_string('subscribe', 'hsuforum'),
        'cantaccessgroup' => get_string('no'),
        'forcesubscribed' => get_string('everyoneissubscribed', 'hsuforum'),
        'cantsubscribe' => get_string('disallowsubscribe','hsuforum')
    );
    $messages = $messages + $defaultmessages;

    if (hsuforum_is_forcesubscribed($forum)) {
        return $messages['forcesubscribed'];
    } else if ($forum->forcesubscribe == HSUFORUM_DISALLOWSUBSCRIBE && !has_capability('mod/hsuforum:managesubscriptions', $context)) {
        return $messages['cantsubscribe'];
    } else if ($cantaccessagroup) {
        return $messages['cantaccessgroup'];
    } else {
        if (!is_enrolled($context, $USER, '', true)) {
            return '';
        }
        if (is_null($subscribed_forums)) {
            $subscribed = hsuforum_is_subscribed($USER->id, $forum);
        } else {
            $subscribed = !empty($subscribed_forums[$forum->id]);
        }
        if ($subscribed) {
            $linktext = $messages['subscribed'];
            $linktitle = get_string('subscribestop', 'hsuforum');
        } else {
            $linktext = $messages['unsubscribed'];
            $linktitle = get_string('subscribestart', 'hsuforum');
        }

        $options = array();
        if ($backtoindex) {
            $backtoindexlink = '&amp;backtoindex=1';
            $options['backtoindex'] = 1;
        } else {
            $backtoindexlink = '';
        }
        $link = '';

        if ($fakelink) {
            $PAGE->requires->js('/mod/hsuforum/forum.js');
            $PAGE->requires->js_function_call('hsuforum_produce_subscribe_link', array($forum->id, $backtoindexlink, $linktext, $linktitle));
            $link = "<noscript>";
        }
        $options['id'] = $forum->id;
        $options['sesskey'] = sesskey();
        $url = new moodle_url('/mod/hsuforum/subscribe.php', $options);
        $link .= $OUTPUT->single_button($url, $linktext, 'get', array('title'=>$linktitle));
        if ($fakelink) {
            $link .= '</noscript>';
        }
        return $link;
    }
}

/**
 * Returns true if user created new discussion already.
 *
 * @param int $forumid  The forum to check for postings
 * @param int $userid   The user to check for postings
 * @param int $groupid  The group to restrict the check to
 * @return bool
 */
function hsuforum_user_has_posted_discussion($forumid, $userid, $groupid = null) {
    global $CFG, $DB;

    $sql = "SELECT 'x'
              FROM {hsuforum_discussions} d, {hsuforum_posts} p
             WHERE d.forum = ? AND p.discussion = d.id AND p.parent = 0 AND p.userid = ?";

    $params = [$forumid, $userid];

    if ($groupid) {
        $sql .= " AND d.groupid = ?";
        $params[] = $groupid;
    }

    return $DB->record_exists_sql($sql, $params);
}

/**
 * @global object
 * @global object
 * @param int $forumid
 * @param int $userid
 * @return array
 */
function hsuforum_discussions_user_has_posted_in($forumid, $userid) {
    global $CFG, $DB;

    $haspostedsql = "SELECT DISTINCT d.id AS id,
                            d.*
                       FROM {hsuforum_posts} p,
                            {hsuforum_discussions} d
                      WHERE p.discussion = d.id
                        AND d.forum = ?
                        AND p.userid = ?";

    return $DB->get_records_sql($haspostedsql, array($forumid, $userid));
}

/**
 * @global object
 * @global object
 * @param int $forumid
 * @param int $did
 * @param int $userid
 * @return bool
 */
function hsuforum_user_has_posted($forumid, $did, $userid) {
    global $DB;

    if (empty($did)) {
        // posted in any forum discussion?
        $sql = "SELECT 'x'
                  FROM {hsuforum_posts} p
                  JOIN {hsuforum_discussions} d ON d.id = p.discussion
                 WHERE p.userid = :userid AND d.forum = :forumid";
        return $DB->record_exists_sql($sql, array('forumid'=>$forumid,'userid'=>$userid));
    } else {
        return $DB->record_exists('hsuforum_posts', array('discussion'=>$did,'userid'=>$userid));
    }
}

/**
 * Returns creation time of the first user's post in given discussion
 * @global object $DB
 * @param int $did Discussion id
 * @param int $userid User id
 * @return int|bool post creation time stamp or return false
 */
function hsuforum_get_user_posted_time($did, $userid) {
    global $DB;

    $posttime = $DB->get_field('hsuforum_posts', 'MIN(created)', array('userid'=>$userid, 'discussion'=>$did));
    if (empty($posttime)) {
        return false;
    }
    return $posttime;
}

/**
 * @global object
 * @param object $forum
 * @param object $currentgroup
 * @param int $unused
 * @param object $cm
 * @param object $context
 * @return bool
 */
function hsuforum_user_can_post_discussion($forum, $currentgroup=null, $unused=-1, $cm=NULL, $context=NULL) {
// $forum is an object
    global $USER;

    // shortcut - guest and not-logged-in users can not post
    if (isguestuser() or !isloggedin()) {
        return false;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('hsuforum', $forum->id, $forum->course)) {
            print_error('invalidcoursemodule');
        }
    }

    if (!$context) {
        $context = context_module::instance($cm->id);
    }

    if ($currentgroup === null) {
        $currentgroup = groups_get_activity_group($cm);
    }

    $groupmode = groups_get_activity_groupmode($cm);

    if ($forum->type == 'news') {
        $capname = 'mod/hsuforum:addnews';
    } else if ($forum->type == 'qanda') {
        $capname = 'mod/hsuforum:addquestion';
    } else {
        $capname = 'mod/hsuforum:startdiscussion';
    }

    if (!has_capability($capname, $context)) {
        return false;
    }

    if ($forum->type == 'single') {
        return false;
    }

    if ($forum->type == 'eachuser') {
        if (hsuforum_user_has_posted_discussion($forum->id, $USER->id, $currentgroup)) {
            return false;
        }
    }

    if (!$groupmode or has_capability('moodle/site:accessallgroups', $context)) {
        return true;
    }

    if ($currentgroup) {
        return groups_is_member($currentgroup);
    } else {
        // no group membership and no accessallgroups means no new discussions
        // reverted to 1.7 behaviour in 1.9+,  buggy in 1.8.0-1.9.0
        return false;
    }
}

/**
 * This function checks whether the user can reply to posts in a forum
 * discussion. Use hsuforum_user_can_post_discussion() to check whether the user
 * can start discussions.
 *
 * @global object
 * @global object
 * @uses DEBUG_DEVELOPER
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $forum forum object
 * @param object $discussion
 * @param object $user
 * @param object $cm
 * @param object $course
 * @param object $context
 * @return bool
 */
function hsuforum_user_can_post($forum, $discussion, $user=NULL, $cm=NULL, $course=NULL, $context=NULL) {
    global $USER, $DB;
    if (empty($user)) {
        $user = $USER;
    }

    // shortcut - guest and not-logged-in users can not post
    if (isguestuser($user) or empty($user->id)) {
        return false;
    }

    if (!isset($discussion->groupid)) {
        debugging('incorrect discussion parameter', DEBUG_DEVELOPER);
        return false;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('hsuforum', $forum->id, $forum->course)) {
            print_error('invalidcoursemodule');
        }
    }

    if (!$course) {
        debugging('missing course', DEBUG_DEVELOPER);
        if (!$course = $DB->get_record('course', array('id' => $forum->course))) {
            print_error('invalidcourseid');
        }
    }

    if (!$context) {
        $context = context_module::instance($cm->id);
    }

    // normal users with temporary guest access can not post, suspended users can not post either
    if (!is_viewing($context, $user->id) and !is_enrolled($context, $user->id, '', true)) {
        return false;
    }

    if ($forum->type == 'news') {
        $capname = 'mod/hsuforum:replynews';
    } else {
        $capname = 'mod/hsuforum:replypost';
    }

    if (!has_capability($capname, $context, $user->id)) {
        return false;
    }

    if (!$groupmode = groups_get_activity_groupmode($cm, $course)) {
        return true;
    }

    if (has_capability('moodle/site:accessallgroups', $context)) {
        return true;
    }

    if ($groupmode == VISIBLEGROUPS) {
        if ($discussion->groupid == -1) {
            // allow students to reply to all participants discussions - this was not possible in Moodle <1.8
            return true;
        }
        return groups_is_member($discussion->groupid);

    } else {
        //separate groups
        if ($discussion->groupid == -1) {
            return false;
        }
        return groups_is_member($discussion->groupid);
    }
}

/**
* Check to ensure a user can view a timed discussion.
*
* @param object $discussion
* @param object $user
* @param object $context
* @return boolean returns true if they can view post, false otherwise
*/
function hsuforum_user_can_see_timed_discussion($discussion, $user, $context) {

    $config = get_config('hsuforum');

    // Check that the user can view a discussion that is normally hidden due to access times.
    if (!empty($config->enabletimedposts)) {
        $time = time();
        if (($discussion->timestart != 0 && $discussion->timestart > $time)
            || ($discussion->timeend != 0 && $discussion->timeend < $time)) {
            if (!has_capability('mod/hsuforum:viewhiddentimedposts', $context, $user->id)) {
                return false;
            }
        }
    }

    return true;
}

/**
* Check to ensure a user can view a group discussion.
*
* @param object $discussion
* @param object $cm
* @param object $context
* @return boolean returns true if they can view post, false otherwise
*/
function hsuforum_user_can_see_group_discussion($discussion, $cm, $context) {

    // If it's a grouped discussion, make sure the user is a member.
    if ($discussion->groupid > 0) {
        $groupmode = groups_get_activity_groupmode($cm);
        if ($groupmode == SEPARATEGROUPS) {
            return groups_is_member($discussion->groupid) || has_capability('moodle/site:accessallgroups', $context);
        }
    }

    return true;
}

/**
 * @global object
 * @global object
 * @uses DEBUG_DEVELOPER
 * @param object $forum
 * @param object $discussion
 * @param object $context
 * @param object $user
 * @return bool
 */
function hsuforum_user_can_see_discussion($forum, $discussion, $context, $user=NULL) {
    global $USER, $DB;

    if (empty($user) || empty($user->id)) {
        $user = $USER;
    }

    // retrieve objects (yuk)
    if (is_numeric($forum)) {
        debugging('missing full forum', DEBUG_DEVELOPER);
        if (!$forum = $DB->get_record('hsuforum',array('id'=>$forum))) {
            return false;
        }
    }
    if (is_numeric($discussion)) {
        debugging('missing full discussion', DEBUG_DEVELOPER);
        if (!$discussion = $DB->get_record('hsuforum_discussions',array('id'=>$discussion))) {
            return false;
        }
    }
    if (!$cm = get_coursemodule_from_instance('hsuforum', $forum->id, $forum->course)) {
        print_error('invalidcoursemodule');
    }

    if (!has_capability('mod/hsuforum:viewdiscussion', $context)) {
        return false;
    }

    if (!hsuforum_user_can_see_timed_discussion($discussion, $user, $context)) {
        return false;
    }

    if (!hsuforum_user_can_see_group_discussion($discussion, $cm, $context)) {
        return false;
    }

    return true;
}

/**
 * @global object
 * @global object
 * @param object $forum
 * @param object $discussion
 * @param object $post
 * @param object $user
 * @param object $cm
 * @return bool
 */
function hsuforum_user_can_see_post($forum, $discussion, $post, $user=NULL, $cm=NULL) {
    global $CFG, $USER, $DB;

    // Context used throughout function.
    $modcontext = context_module::instance($cm->id);

    // retrieve objects (yuk)
    if (is_numeric($forum)) {
        debugging('missing full forum', DEBUG_DEVELOPER);
        if (!$forum = $DB->get_record('hsuforum',array('id'=>$forum))) {
            return false;
        }
    }

    if (is_numeric($discussion)) {
        debugging('missing full discussion', DEBUG_DEVELOPER);
        if (!$discussion = $DB->get_record('hsuforum_discussions',array('id'=>$discussion))) {
            return false;
        }
    }
    if (is_numeric($post)) {
        debugging('missing full post', DEBUG_DEVELOPER);
        if (!$post = $DB->get_record('hsuforum_posts',array('id'=>$post))) {
            return false;
        }
    }

    if (!isset($post->id) && isset($post->parent)) {
        $post->id = $post->parent;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('hsuforum', $forum->id, $forum->course)) {
            print_error('invalidcoursemodule');
        }
    }

    if (empty($user) || empty($user->id)) {
        $user = $USER;
    }

    $canviewdiscussion = \mod_hsuforum\local::cached_has_capability('mod/hsuforum:viewdiscussion', $modcontext, $user->id);
    if (!$canviewdiscussion && !has_all_capabilities(array('moodle/user:viewdetails', 'moodle/user:readuserposts'), context_user::instance($post->userid))) {
        return false;
    }

    if (isset($cm->uservisible)) {
        if (!$cm->uservisible) {
            return false;
        }
    } else {
        if (!\core_availability\info_module::is_user_visible($cm, $user->id, false)) {
            return false;
        }
    }

    if (!hsuforum_user_can_see_timed_discussion($discussion, $user, $modcontext)) {
        return false;
    }

    if (!hsuforum_user_can_see_group_discussion($discussion, $cm, $modcontext)) {
        return false;
    }

    if (!property_exists($post, 'privatereply')) {
        throw new coding_exception('Must set post\'s privatereply property!');
    }
    if (!empty($post->privatereply)) {
        if ($post->userid != $user->id and $post->privatereply != $user->id) {
            return false;
        }
    }

    if ($forum->type == 'qanda') {
        $firstpost = hsuforum_get_firstpost_from_discussion($discussion->id);
        $userfirstpost = hsuforum_get_user_posted_time($discussion->id, $user->id);

        return (($userfirstpost !== false && (time() - $userfirstpost >= $CFG->maxeditingtime)) ||
                $firstpost->id == $post->id || $post->userid == $user->id || $firstpost->userid == $user->id ||
                \mod_hsuforum\local::cached_has_capability('mod/hsuforum:viewqandawithoutposting', $modcontext, $user->id));
    }
    return true;
}


/**
 * Prints the discussion view screen for a forum.
 *
 * @global object
 * @global object
 * @param object $course The current course object.
 * @param object $forum Forum to be printed.
 * @param int $maxdiscussions .
 * @param string $sort Sort arguments for database query (optional).
 * @param int $groupmode Group mode of the forum (optional).
 * @param void $unused (originally current group)
 * @param int $page Page mode, page to display (optional).
 * @param int $perpage The maximum number of discussions per page(optional)
 *
 */
function hsuforum_print_latest_discussions($course, $forum, $maxdiscussions=-1, $sort='',
                                        $currentgroup=-1, $groupmode=-1, $page=-1, $perpage=100, $cm=NULL) {
    global $CFG, $USER, $OUTPUT, $PAGE;

    require_once($CFG->dirroot.'/rating/lib.php');

    if (!$cm) {
        if (!$cm = get_coursemodule_from_instance('hsuforum', $forum->id, $forum->course)) {
            print_error('invalidcoursemodule');
        }
    }
    $context = context_module::instance($cm->id);

    if (empty($sort)) {
        $sort = hsuforum_get_default_sort_order();
    }

    $olddiscussionlink = false;

 // Sort out some defaults
    if ($perpage <= 0) {
        $perpage = 0;
        $page    = -1;
    }

    if ($maxdiscussions == 0) {
        // all discussions - backwards compatibility
        $page    = -1;
        $perpage = 0;
    } else if ($maxdiscussions > 0) {
        $page    = -1;
        $perpage = $maxdiscussions;
    }

    $renderer = $PAGE->get_renderer('mod_hsuforum');
    $PAGE->requires->js_init_call('M.mod_hsuforum.init', null, false, $renderer->get_js_module());

    $fullpost = true;

// Decide if current user is allowed to see ALL the current discussions or not

// First check the group stuff
    if ($currentgroup == -1 or $groupmode == -1) {
        $groupmode    = groups_get_activity_groupmode($cm, $course);
        $currentgroup = groups_get_activity_group($cm);
    }

    $groups = array(); //cache

// If the user can post discussions, then this is a good place to put the
// button for it. We do not show the button if we are showing site news
// and the current user is a guest.

    $canstart = hsuforum_user_can_post_discussion($forum, $currentgroup, $groupmode, $cm, $context);
    if (!$canstart and $forum->type !== 'news') {
        if (isguestuser() or !isloggedin()) {
            $canstart = true;
        }
        if (!is_enrolled($context) and !is_viewing($context)) {
            // allow guests and not-logged-in to see the button - they are prompted to log in after clicking the link
            // normal users with temporary guest access see this button too, they are asked to enrol instead
            // do not show the button to users with suspended enrolments here
            $canstart = enrol_selfenrol_available($course->id);
        }
    }

    // Get all the recent discussions we're allowed to see
    $getuserlastmodified = true;
    $discussions = hsuforum_get_discussions($cm, $sort, $fullpost, null, $maxdiscussions, $getuserlastmodified, $page, $perpage, -1, false);

    // If we want paging
    $numdiscussions = null;
    if ($page != -1) {
        // Get the number of discussions found.
        $numdiscussions = hsuforum_get_discussions_count($cm);
    } else {
        if ($maxdiscussions > 0 and $maxdiscussions <= count($discussions)) {
            $olddiscussionlink = true;
        }
    }


    // TODO - Can we just delete this first if?
    if (!$canstart && (isguestuser()
        or !isloggedin()
        or  $forum->type == 'news'
        or  $forum->type == 'qanda' and !has_capability('mod/hsuforum:addquestion', $context)
        or  $forum->type != 'qanda' and !has_capability('mod/hsuforum:startdiscussion', $context))) {
        // no button and no info
    } else if (!$canstart && $groupmode and !has_capability('moodle/site:accessallgroups', $context)) {
        // inform users why they can not post new discussion
        if (!$currentgroup) {
            echo $OUTPUT->notification(get_string('cannotadddiscussionall', 'hsuforum'), 'notifyproblem hsuforum-cannot-post');
        } else if (!groups_is_member($currentgroup)) {
            echo $OUTPUT->notification(get_string('cannotadddiscussion', 'hsuforum'), 'notifyproblem hsuforum-cannot-post');
        }
    }


    if ($discussions) {
        echo "<h3 class='hsuforum-discussion-count rounded discussion-meta' data-count='$numdiscussions'>".get_string('xdiscussions', 'hsuforum', $numdiscussions)."</h3>";
    }

    // lots of echo instead of building up and printing - bad
    echo '<div id="hsuforum-menu">';
    if ($canstart) {
        echo
        '<form class="hsuforum-add-discussion" id="newdiscussionform" method="get" action="'.$CFG->wwwroot.'/mod/hsuforum/post.php">
        <div>
        <input type="hidden" name="forum" value="'.$forum->id.'" />
        <input class="rounded-pill btn btn-primary" type="submit" value="'.get_string('addanewtopic', 'hsuforum').'" />
        </div>
        </form>';
    }
    echo hsuforum_search_form($course, $forum->id);

    // Sort/Filter options
    $urlmenu = new moodle_url('/mod/hsuforum/view.php', array('id'=>$cm->id));
    $groupselect = groups_print_activity_menu($cm, $urlmenu, true);

    $sortselect = '';
    if ($discussions && $forum->type != 'single' && $numdiscussions > 1) {
        require_once(__DIR__.'/lib/discussion/sort.php');
        $dsort = hsuforum_lib_discussion_sort::get_from_session($forum, $context);
        $dsort->set_key(optional_param('dsortkey', $dsort->get_key(), PARAM_ALPHA));
        $dsort->set_direction(optional_param('dsortdirection', $dsort->get_direction(), PARAM_ALPHA));
        hsuforum_lib_discussion_sort::set_to_session($dsort);
        $sortselect = $renderer->discussion_sorting($cm, $dsort);
    }

    if ($groupselect || $sortselect) {
        echo "<div id='hsuforum-filter-options'>";
            echo "<div class='filter-option-wrapper row'>";
                echo "<div class='filter-option'>";

                    if ($groupselect && strpos($groupselect, '<form') !== false) {
                        echo'<label for="selectgroup" >Filter:</label>';
                    }
                    echo $groupselect;

                echo "</div>";

                echo "<div class='filter-option'>";

                    echo $sortselect;

                echo "</div>";

            echo "</div>";
        echo "</div>";
    }

    echo "</div><!-- end hsuforum-menu -->";
    echo "<hr>";

    // When there are no threads, return;
    if (!$discussions) {
        // in an empty forum, if the user can start a thread this div is where the js puts it
        if ($canstart) {
            echo '<div class="mod-hsuforum-posts-container article">';
            echo $renderer->discussions($cm, array(), array(
                'total'   => 0,
                'page'    => $page,
                'perpage' => $perpage,
            ));
            echo "</div>";
        }
        return;
    }

    if ($forum->assessed != RATING_AGGREGATE_NONE) {
        $ratingoptions = new stdClass;
        $ratingoptions->context = $context;
        $ratingoptions->component = 'mod_hsuforum';
        $ratingoptions->ratingarea = 'post';
        $ratingoptions->items = $discussions;
        $ratingoptions->aggregate = $forum->assessed;
        $ratingoptions->scaleid = $forum->scale;
        $ratingoptions->userid = $USER->id;
        $ratingoptions->returnurl = "$CFG->wwwroot/mod/hsuforum/view.php?id=$cm->id";
        $ratingoptions->assesstimestart = $forum->assesstimestart;
        $ratingoptions->assesstimefinish = $forum->assesstimefinish;

        $rm = new rating_manager();
        $discussions = $rm->get_ratings($ratingoptions);
    }

    $canviewparticipants = has_capability('moodle/course:viewparticipants',$context);
    $canviewhiddentimedposts = has_capability('mod/hsuforum:viewhiddentimedposts', $context);

    $strdatestring = get_string('strftimerecentfull');

    echo '<div class="mod-hsuforum-posts-container article">';

    // Can be used by some output formats.
    $discussionlist = array();

    foreach ($discussions as $discussion) {
        if ($forum->type == 'qanda' && !has_capability('mod/hsuforum:viewqandawithoutposting', $context) &&
            !hsuforum_user_has_posted($forum->id, $discussion->discussion, $USER->id)) {
            $canviewparticipants = false;
        }

        if (empty($discussion->replies)) {
            $discussion->replies = 0;
        }
        if (empty($discussion->lastpostid)) {
            $discussion->lastpostid = 0;
        }

        // SPECIAL CASE: The front page can display a news item post to non-logged in users.
        // All posts are read in this case.
        if (empty($USER)) {
            $discussion->unread = 0;
        } else if (empty($discussion->unread)) {
            $discussion->unread = 0;
        }

        if (isloggedin()) {
            $ownpost = ($discussion->userid == $USER->id);
        } else {
            $ownpost=false;
        }
        // Use discussion name instead of subject of first post
        $discussion->subject = $discussion->name;

        $disc = hsuforum_extract_discussion($discussion, $forum);
        $discussionlist[$disc->id] = array($disc, $discussion);
    }

    echo $renderer->discussions($cm, $discussionlist, array(
        'total'   => $numdiscussions,
        'page'    => $page,
        'perpage' => $perpage,
    ));

    if ($olddiscussionlink) {
        if ($forum->type == 'news') {
            $strolder = get_string('oldertopics', 'hsuforum');
        } else {
            $strolder = get_string('olderdiscussions', 'hsuforum');
        }
        echo '<div class="forumolddiscuss">';
        echo '<a href="'.$CFG->wwwroot.'/mod/hsuforum/view.php?f='.$forum->id.'&amp;showall=1">';
        echo $strolder.'</a> ...</div>';
    }

    echo "</div>"; // End mod-hsuforum-posts-container

    if ($page != -1) {
        echo $OUTPUT->paging_bar($numdiscussions, $page, $perpage, "view.php?f=$forum->id");
    }


}


/**
 * Prints a forum discussion
 *
 * @uses CONTEXT_MODULE
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $forum
 * @param stdClass $discussion
 * @param stdClass $post
 * @param mixed $canreply
 * @param bool $canrate
 */
function hsuforum_print_discussion($course, $cm, $forum, $discussion, $post, $canreply=NULL, $canrate=false) {
    global $USER, $CFG, $OUTPUT, $PAGE, $DB;

    require_once($CFG->dirroot.'/rating/lib.php');

    $modcontext = context_module::instance($cm->id);
    if ($canreply === NULL) {
        $reply = hsuforum_user_can_post($forum, $discussion, $USER, $cm, $course, $modcontext);
    } else {
        $reply = $canreply;
    }

    // Add a check that user should be able to reply ...
    // ...to posts that has passed expected completion date...
    // ...if the expected completion date is enabled.
    $studentrole = $DB->get_field('role', 'id', array('shortname' => 'student'));
    $student = get_role_users($studentrole, $modcontext, true, "u.id", "", "", "", "", "", "u.id=".$USER->id);

    if ($cm->completionexpected && $cm->completionexpected < time() && !empty($student)) {
        $reply = false;
    }

    $posters = array();

    $posts = hsuforum_get_all_discussion_posts($discussion->id);
    $post = $posts[$post->id];

    foreach ($posts as $pid=>$p) {
        $posters[$p->userid] = $p->userid;
    }

    //load ratings
    if ($forum->assessed != RATING_AGGREGATE_NONE) {
        $ratingoptions = new stdClass;
        $ratingoptions->context = $modcontext;
        $ratingoptions->component = 'mod_hsuforum';
        $ratingoptions->ratingarea = 'post';
        $ratingoptions->items = $posts;
        $ratingoptions->aggregate = $forum->assessed;//the aggregation method
        $ratingoptions->scaleid = $forum->scale;
        $ratingoptions->userid = $USER->id;
        if ($forum->type == 'single' or !$discussion->id) {
            $ratingoptions->returnurl = "$CFG->wwwroot/mod/hsuforum/view.php?id=$cm->id";
        } else {
            $ratingoptions->returnurl = "$CFG->wwwroot/mod/hsuforum/discuss.php?d=$discussion->id";
        }
        $ratingoptions->assesstimestart = $forum->assesstimestart;
        $ratingoptions->assesstimefinish = $forum->assesstimefinish;

        $rm = new rating_manager();
        $posts = $rm->get_ratings($ratingoptions);
    }

    $post->forum = $forum->id;   // Add the forum id to the post object, later used for rendering
    $post->forumtype = $forum->type;

    $post->subject = format_string($post->subject);

    $postread = !empty($post->postread);

    echo $OUTPUT->box_start("mod-hsuforum-posts-container article");

    $renderer = $PAGE->get_renderer('mod_hsuforum');
    echo $renderer->discussion_thread($cm, $discussion, $post, $posts, $reply);
    echo $OUTPUT->box_end(); // End mod-hsuforum-posts-container
    return;

}



/**
 * Returns all forum posts since a given time in specified forum.
 *
 * @todo Document this functions args
 * @global object
 * @global object
 * @global object
 * @global object
 */
function hsuforum_get_recent_mod_activity(&$activities, &$index, $timestart, $courseid, $cmid, $userid=0, $groupid=0)  {
    global $COURSE, $USER, $DB;

    $config = get_config('hsuforum');

    if ($COURSE->id == $courseid) {
        $course = $COURSE;
    } else {
        $course = $DB->get_record('course', array('id' => $courseid));
    }

    $modinfo = get_fast_modinfo($course);

    $cm = $modinfo->cms[$cmid];

    // Cannot report on recent activity on anonymous forums as we could reveal user's identity.
    $anonymous = $DB->get_field('hsuforum', 'anonymous', array('id' => $cm->instance), MUST_EXIST);
    if (!empty($anonymous)) {
        $tmpactivity             = new stdClass();
        $tmpactivity->type       = 'hsuforum';
        $tmpactivity->cmid       = $cm->id;
        $tmpactivity->name       = format_string($cm->name, true);;
        $tmpactivity->sectionnum = $cm->sectionnum;
        $tmpactivity->timestamp  = time();
        $tmpactivity->content    = get_string('anonymousrecentactivity', 'hsuforum');

        $activities[$index++] = $tmpactivity;
        return;
    }

    $params = array($timestart, $cm->instance, $USER->id, $USER->id);

    if ($userid) {
        $userselect = "AND u.id = ?";
        $params[] = $userid;
    } else {
        $userselect = "";
    }

    if ($groupid) {
        $groupselect = "AND d.groupid = ?";
        $params[] = $groupid;
    } else {
        $groupselect = "";
    }

    $allnames = get_all_user_name_fields(true, 'u');
    if (!$posts = $DB->get_records_sql("SELECT p.*, f.anonymous AS forumanonymous, f.type AS forumtype, d.forum, d.groupid,
                                              d.timestart, d.timeend, d.userid AS duserid,
                                              $allnames, u.email, u.picture, u.imagealt, u.email
                                         FROM {hsuforum_posts} p
                                              JOIN {hsuforum_discussions} d ON d.id = p.discussion
                                              JOIN {hsuforum} f             ON f.id = d.forum
                                              JOIN {user} u              ON u.id = p.userid
                                        WHERE p.created > ? AND f.id = ? AND (p.privatereply = 0 OR p.privatereply = ? OR p.userid = ?)
                                              $userselect $groupselect
                                     ORDER BY p.id ASC", $params)) { // order by initial posting date
         return;
    }

    $groupmode       = groups_get_activity_groupmode($cm, $course);
    $cm_context      = context_module::instance($cm->id);
    $viewhiddentimed = has_capability('mod/hsuforum:viewhiddentimedposts', $cm_context);
    $accessallgroups = has_capability('moodle/site:accessallgroups', $cm_context);

    $printposts = array();
    foreach ($posts as $post) {

        if (!empty($config->enabletimedposts) and $USER->id != $post->duserid
          and (($post->timestart > 0 and $post->timestart > time()) or ($post->timeend > 0 and $post->timeend < time()))) {
            if (!$viewhiddentimed) {
                continue;
            }
        }

        if ($groupmode) {
            if ($post->groupid == -1 or $groupmode == VISIBLEGROUPS or $accessallgroups) {
                // oki (Open discussions have groupid -1)
            } else {
                // separate mode
                if (isguestuser()) {
                    // shortcut
                    continue;
                }

                if (!in_array($post->groupid, $modinfo->get_groups($cm->groupingid))) {
                    continue;
                }
            }
        }

        $printposts[] = $post;
    }

    if (!$printposts) {
        return;
    }

    $aname = format_string($cm->name,true);

    foreach ($printposts as $post) {
        $tmpactivity = new stdClass();

        $tmpactivity->type         = 'hsuforum';
        $tmpactivity->cmid         = $cm->id;
        $tmpactivity->name         = $aname;
        $tmpactivity->sectionnum   = $cm->sectionnum;
        $tmpactivity->timestamp    = $post->modified;

        $tmpactivity->content = new stdClass();
        $tmpactivity->content->id         = $post->id;
        $tmpactivity->content->discussion = $post->discussion;
        $tmpactivity->content->subject    = format_string($post->subject);
        $tmpactivity->content->parent     = $post->parent;

        $tmpactivity->user = new stdClass();
        $additionalfields = array('id' => 'userid', 'picture', 'imagealt', 'email');
        $additionalfields = explode(',', user_picture::fields());
        $tmpactivity->user = username_load_fields_from_object($tmpactivity->user, $post, null, $additionalfields);
        $tmpactivity->user->id = $post->userid;

        $tmpactivity->user = hsuforum_anonymize_user($tmpactivity->user, (object) array(
            'id'        => $post->forum,
            'course'    => $courseid,
            'anonymous' => $post->forumanonymous
        ), $post);

        $activities[$index++] = $tmpactivity;
    }

    return;
}

/**
 * @todo Document this function
 * @global object
 */
function hsuforum_print_recent_mod_activity($activity, $courseid, $detail, $modnames, $viewfullnames) {
    global $CFG, $OUTPUT;

    // This handles anonymous forums.
    if (is_string($activity->content)) {
        echo $OUTPUT->box($activity->content, 'forum-recent anonymous');
        return;
    }
    if ($activity->content->parent) {
        $class = 'reply';
    } else {
        $class = 'discussion';
    }

    echo '<table border="0" cellpadding="3" cellspacing="0" class="forum-recent">';

    echo "<tr><td class=\"userpicture\" valign=\"top\">";
    echo $OUTPUT->user_picture($activity->user, array('courseid'=>$courseid, 'link' => (!hsuforum_is_anonymous_user($activity->user))));
    echo "</td><td class=\"$class\">";

    echo '<div class="title">';
    if ($detail) {
        $aname = s($activity->name);
        echo "<img src=\"" . $OUTPUT->image_url('icon', $activity->type) . "\" ".
             "class=\"icon\" alt=\"{$aname}\" />";
    }
    echo "<a href=\"$CFG->wwwroot/mod/hsuforum/discuss.php?d={$activity->content->discussion}"
        ."#p{$activity->content->id}\">{$activity->content->subject}</a>";
    echo '</div>';

    echo '<div class="user">';
    $fullname = fullname($activity->user, $viewfullnames);
    if (hsuforum_is_anonymous_user($activity->user)) {
        echo "{$fullname} - ".userdate($activity->timestamp);
    } else {
        echo "<a href=\"$CFG->wwwroot/user/view.php?id={$activity->user->id}&amp;course=$courseid\">"
                 ."{$fullname}</a> - ".userdate($activity->timestamp);
    }
    echo '</div>';
      echo "</td></tr></table>";

    return;
}

/**
 * recursively sets the discussion field to $discussionid on $postid and all its children
 * used when pruning a post
 *
 * @global object
 * @param int $postid
 * @param int $discussionid
 * @return bool
 */
function hsuforum_change_discussionid($postid, $discussionid) {
    global $DB;
    $DB->set_field('hsuforum_posts', 'discussion', $discussionid, array('id' => $postid));
    if ($posts = $DB->get_records('hsuforum_posts', array('parent' => $postid))) {
        foreach ($posts as $post) {
            hsuforum_change_discussionid($post->id, $discussionid);
        }
    }
    return true;
}

/**
 * Prints the editing button on subscribers page
 *
 * @global object
 * @global object
 * @param int $courseid
 * @param int $forumid
 * @return string
 */
function hsuforum_update_subscriptions_button($courseid, $forumid) {
    global $CFG, $USER;

    if (!empty($USER->subscriptionsediting)) {
        $string = get_string('turneditingoff');
        $edit = "off";
    } else {
        $string = get_string('turneditingon');
        $edit = "on";
    }

    return "<form method=\"get\" action=\"$CFG->wwwroot/mod/hsuforum/subscribers.php\">".
           "<input type=\"hidden\" name=\"id\" value=\"$forumid\" />".
           "<input type=\"hidden\" name=\"edit\" value=\"$edit\" />".
           "<input type=\"submit\" value=\"$string\" /></form>";
}

/**
 * This function gets run whenever user is enrolled into course
 *
 * @deprecated deprecating this function as we will be using \mod_hsuforum\observer::role_assigned()
 * @param stdClass $cp
 * @return void
 */
function hsuforum_user_enrolled($cp) {
    global $DB;

    // NOTE: this has to be as fast as possible - we do not want to slow down enrolments!
    //       Originally there used to be 'mod/hsuforum:initialsubscriptions' which was
    //       introduced because we did not have enrolment information in earlier versions...

    $sql = "SELECT f.id
              FROM {hsuforum} f
         LEFT JOIN {hsuforum_subscriptions} fs ON (fs.forum = f.id AND fs.userid = :userid)
             WHERE f.course = :courseid AND f.forcesubscribe = :initial AND fs.id IS NULL";
    $params = array('courseid'=>$cp->courseid, 'userid'=>$cp->userid, 'initial'=>HSUFORUM_INITIALSUBSCRIBE);

    $forums = $DB->get_records_sql($sql, $params);
    foreach ($forums as $forum) {
        hsuforum_subscribe($cp->userid, $forum->id);
    }
}

// Functions to do with read tracking.

/**
 * Mark posts as read.
 *
 * @global object
 * @global object
 * @param object $user object
 * @param array $postids array of post ids
 * @return boolean success
 */
function hsuforum_mark_posts_read($user, $postids) {
    global $DB;

    $config = get_config('hsuforum');
    $status = true;

    $now = time();
    $cutoffdate = $now - ($config->oldpostdays * 24 * 3600);

    if (empty($postids)) {
        return true;

    } else if (count($postids) > 200) {
        while ($part = array_splice($postids, 0, 200)) {
            $status = hsuforum_mark_posts_read($user, $part) && $status;
        }
        return $status;
    }

    list($usql, $postidparams) = $DB->get_in_or_equal($postids, SQL_PARAMS_NAMED, 'postid');

    $insertparams = array(
             'userid1' => $user->id,
             'userid2' => $user->id,
             'userid3' => $user->id,
             'firstread' => $now,
             'lastread' => $now,
             'cutoffdate' => $cutoffdate,
         );
     $params = array_merge($postidparams, $insertparams);

     if ($CFG->hsuforum_allowforcedreadtracking) {
             $trackingsql = "AND (f.trackingtype = ".HSUFORUM_TRACKING_FORCED."
                     OR (f.trackingtype = ".HSUFORUM_TRACKING_OPTIONAL." AND tf.id IS NULL))";
         } else {
             $trackingsql = "AND ((f.trackingtype = ".HSUFORUM_TRACKING_OPTIONAL."  OR f.trackingtype = ".HSUFORUM_TRACKING_FORCED.")
                         AND tf.id IS NULL)";
         }

 // First insert any new entries.
    //  @TODO Temp remove trackingsql for now since there is no trackingtype column in hsuforum table but this is in config table
 $sql = "INSERT INTO {hsuforum_read} (userid, postid, discussionid, forumid, firstread, lastread)

         SELECT :userid1, p.id, p.discussion, d.forum, :firstread, :lastread
             FROM {hsuforum_posts} p
                 JOIN {hsuforum_discussions} d       ON d.id = p.discussion
                 JOIN {hsuforum} f                   ON f.id = d.forum
                 LEFT JOIN {hsuforum_track_prefs} tf ON (tf.userid = :userid2 AND tf.forumid = f.id)
                 LEFT JOIN {hsuforum_read} fr        ON (
                         fr.userid = :userid3
                     AND fr.postid = p.id
                     AND fr.discussionid = d.id
                     AND fr.forumid = f.id
                 )
             WHERE p.id $usql
                 AND p.modified >= :cutoffdate
                 AND fr.id IS NULL";

 $status = $DB->execute($sql, $params) && $status;

 // Then update all records.
 $updateparams = array(
         'userid' => $user->id,
         'lastread' => $now,
     );
 $params = array_merge($postidparams, $updateparams);
 $status = $DB->set_field_select('hsuforum_read', 'lastread', $now, '
             userid      =  :userid
         AND lastread    <> :lastread
         AND postid      ' . $usql,
                 $params) && $status;

    return $status;
}

/**
 * Mark post as read.
 * @global object
 * @global object
 * @param int $userid
 * @param int $postid
 */
function hsuforum_tp_add_read_record($userid, $postid) {
    global $DB;

    $config = get_config('hsuforum');

    $now = time();
    $cutoffdate = $now - ($config->oldpostdays * 24 * 3600);

    if (!$DB->record_exists('hsuforum_read', array('userid' => $userid, 'postid' => $postid))) {
        $sql = "INSERT INTO {hsuforum_read} (userid, postid, discussionid, forumid, firstread, lastread)

                SELECT ?, p.id, p.discussion, d.forum, ?, ?
                  FROM {hsuforum_posts} p
                       JOIN {hsuforum_discussions} d ON d.id = p.discussion
                 WHERE p.id = ? AND p.modified >= ?";
        return $DB->execute($sql, array($userid, $now, $now, $postid, $cutoffdate));

    } else {
        $sql = "UPDATE {hsuforum_read}
                   SET lastread = ?
                 WHERE userid = ? AND postid = ?";
        return $DB->execute($sql, array($now, $userid, $userid));
    }
}

/**
 * If its an old post, do nothing. If the record exists, the maintenance will clear it up later.
 *
 * @return bool
 */
function hsuforum_mark_post_read($userid, $post, $forumid) {
    if (!hsuforum_tp_is_post_old($post)) {
        return array(hsuforum_mark_parent_post_read($userid, $post->id), hsuforum_tp_add_read_record($userid, $post->id));
    } else {
        return true;
    }
}

/**
 * Marks a whole forum as read, for a given user
 *
 * @global object
 * @global object
 * @param object $user
 * @param int $forumid
 * @param int|bool $groupid
 * @return bool
 */
function hsuforum_mark_forum_read($user, $forumid, $groupid=false) {
    global $DB;

    $config = get_config('hsuforum');

    $cutoffdate = time() - ( $config->oldpostdays*24*60*60);

    $groupsel = "";
    $params = array($user->id, $forumid, $cutoffdate);

    if ($groupid !== false) {
        $groupsel = " AND (d.groupid = ? OR d.groupid = -1)";
        $params[] = $groupid;
    }

    $sql = "SELECT p.id
              FROM {hsuforum_posts} p
                   LEFT JOIN {hsuforum_discussions} d ON d.id = p.discussion
                   LEFT JOIN {hsuforum_read} r        ON (r.postid = p.id AND r.userid = ?)
             WHERE d.forum = ?
                   AND p.modified >= ? AND r.id is NULL
                   $groupsel";

    if ($posts = $DB->get_records_sql($sql, $params)) {
        $postids = array_keys($posts);
        return hsuforum_mark_posts_read($user, $postids);
    }

    return true;
}

/**
 * Marks a whole discussion as read, for a given user
 *
 * @global object
 * @global object
 * @param object $user
 * @param int $discussionid
 * @return bool
 */
function hsuforum_mark_discussion_read($user, $discussionid) {
    global $CFG, $DB;

    $config = get_config('hsuforum');

    $cutoffdate = time() - ($config->oldpostdays*24*60*60);

    $sql = "SELECT p.id
              FROM {hsuforum_posts} p
                   LEFT JOIN {hsuforum_read} r ON (r.postid = p.id AND r.userid = ?)
             WHERE p.discussion = ?
                   AND p.modified >= ? AND r.id is NULL";

    if ($posts = $DB->get_records_sql($sql, array($user->id, $discussionid, $cutoffdate))) {
        $postids = array_keys($posts);
        return hsuforum_mark_posts_read($user, $postids);
    }

    return true;
}

/**
 * @global object
 * @param int $userid
 * @param object $post
 */
function hsuforum_tp_is_post_read($userid, $post) {
    global $DB;
    return (hsuforum_tp_is_post_old($post) ||
            $DB->record_exists('hsuforum_read', array('userid' => $userid, 'postid' => $post->id)));
}

/**
 * @global object
 * @param object $post
 * @param int $time Defautls to time()
 */
function hsuforum_tp_is_post_old($post, $time=null) {
    $config = get_config('hsuforum');

    if (is_null($time)) {
        $time = time();
    }
    return ($post->modified < ($time - ($config->oldpostdays * 24 * 3600)));
}

/**
 * Returns the count of records for the provided user and course.
 * Please note that group access is ignored!
 *
 * @global object
 * @global object
 * @param int $userid
 * @param int $courseid
 * @return array
 */
function hsuforum_tp_get_course_unread_posts($userid, $courseid) {
    global $CFG, $DB;

    $now = round(time(), -2); // DB cache friendliness.
    $config = get_config('hsuforum');
    $cutoffdate = $now - ($config->oldpostdays * 24 * 60 * 60);
    $params = array($userid, $userid, $courseid, $cutoffdate, $userid, $userid);

    if (!empty($config->enabletimedposts)) {
        $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    $sql = "SELECT f.id, COUNT(p.id) AS unread
              FROM {hsuforum_posts} p
                   JOIN {hsuforum_discussions} d       ON d.id = p.discussion
                   JOIN {hsuforum} f                   ON f.id = d.forum
                   JOIN {course} c                  ON c.id = f.course
                   LEFT JOIN {hsuforum_read} r         ON (r.postid = p.id AND r.userid = ?)
                   LEFT JOIN {hsuforum_track_prefs} tf ON (tf.userid = ? AND tf.forumid = f.id)
             WHERE f.course = ?
                   AND p.modified >= ? AND r.id is NULL
                   AND (p.privatereply = 0 OR p.privatereply = ? OR p.userid = ?)
                   $timedsql
          GROUP BY f.id";

    if ($return = $DB->get_records_sql($sql, $params)) {
        return $return;
    }

    return array();
}

/**
 * Returns the count of records for the provided user and forum and [optionally] group.
 *
 * @global object
 * @global object
 * @global object
 * @param object $cm
 * @param object $course
 * @return int
 */
function hsuforum_count_forum_unread_posts($cm, $course) {
    global $CFG, $USER, $DB;

    static $readcache = array();

    $forumid = $cm->instance;
    $config = get_config('hsuforum');

    if (!isset($readcache[$course->id])) {
        $readcache[$course->id] = array();
        if ($counts = hsuforum_tp_get_course_unread_posts($USER->id, $course->id)) {
            foreach ($counts as $count) {
                $readcache[$course->id][$count->id] = $count->unread;
            }
        }
    }

    if (empty($readcache[$course->id][$forumid])) {
        // no need to check group mode ;-)
        return 0;
    }

    $groupmode = groups_get_activity_groupmode($cm, $course);

    // GETSMARTER EDIT. Added  && $groupmode != VISIBLEGROUPS to the if statement below
    if ($groupmode != SEPARATEGROUPS && $groupmode != VISIBLEGROUPS) {
        return $readcache[$course->id][$forumid];
    }

    if (has_capability('moodle/site:accessallgroups', context_module::instance($cm->id))) {
        return $readcache[$course->id][$forumid];
    }

    require_once($CFG->dirroot.'/course/lib.php');

    $modinfo = get_fast_modinfo($course);

    $mygroups = $modinfo->get_groups($cm->groupingid);

    // Add all groups posts.
    $mygroups[-1] = -1;

    list ($groups_sql, $groups_params) = $DB->get_in_or_equal($mygroups);

    $now = round(time(), -2); // DB cache friendliness.
    $cutoffdate = $now - ($config->oldpostdays * 24 * 60 * 60);
    $params = array($USER->id, $forumid, $cutoffdate, $USER->id, $USER->id);

    if (!empty($config->enabletimedposts)) {
        $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    $params = array_merge($params, $groups_params);

    $sql = "SELECT COUNT(p.id)
              FROM {hsuforum_posts} p
                   JOIN {hsuforum_discussions} d ON p.discussion = d.id
                   LEFT JOIN {hsuforum_read} r   ON (r.postid = p.id AND r.userid = ?)
             WHERE d.forum = ?
                   AND p.modified >= ? AND r.id is NULL
                   AND (p.privatereply = 0 OR p.privatereply = ? OR p.userid = ?)
                   $timedsql
                   AND d.groupid $groups_sql";

    return $DB->get_field_sql($sql, $params);
}

/**
 * Deletes read records for the specified forum.
 *
 * @param int $forumid
 * @return bool
 */
function hsuforum_delete_read_records_for_forum($forumid) {
    global $DB;
    return $DB->delete_records('hsuforum_read', array('forumid' => $forumid));
}

/**
 * Deletes read records for the specified discussion.
 *
 * @param int $discussionid
 * @return bool
 */
function hsuforum_delete_read_records_for_discussion($discussionid) {
    global $DB;
    return $DB->delete_records('hsuforum_read', array('discussionid' => $discussionid));
}

/**
 * Deletes read records for the specified post.
 *
 * @param int $postid
 * @return bool
 */
function hsuforum_delete_read_records_for_post($postid) {
    global $DB;
    return $DB->delete_records('hsuforum_read', array('postid' => $postid));
}

/**
 * Clean old records from the hsuforum_read table.
 * @global object
 * @global object
 * @return void
 */
function hsuforum_tp_clean_read_records() {
    global $CFG, $DB;

    $config = get_config('hsuforum');

    if (!isset($config->oldpostdays)) {
        return;
    }
// Look for records older than the cutoffdate that are still in the hsuforum_read table.
    $cutoffdate = time() - ($config->oldpostdays*24*60*60);

    //first get the oldest tracking present - we need tis to speedup the next delete query
    $sql = "SELECT MIN(fp.modified) AS first
              FROM {hsuforum_posts} fp
                   JOIN {hsuforum_read} fr ON fr.postid=fp.id";
    if (!$first = $DB->get_field_sql($sql)) {
        // nothing to delete;
        return;
    }

    // now delete old tracking info
    $sql = "DELETE
              FROM {hsuforum_read}
             WHERE postid IN (SELECT fp.id
                                FROM {hsuforum_posts} fp
                               WHERE fp.modified >= ? AND fp.modified < ?)";
    $DB->execute($sql, array($first, $cutoffdate));
}

/**
 * Sets the last post for a given discussion
 *
 * @global object
 * @global object
 * @param into $discussionid
 * @return bool|int
 **/
function hsuforum_discussion_update_last_post($discussionid) {
    global $DB;

// Check the given discussion exists
    if (!$DB->record_exists('hsuforum_discussions', array('id' => $discussionid))) {
        return false;
    }

// Use SQL to find the last post for this discussion
    $sql = "SELECT id, userid, modified
              FROM {hsuforum_posts}
             WHERE discussion=?
             ORDER BY modified DESC";

// Lets go find the last post
    if (($lastposts = $DB->get_records_sql($sql, array($discussionid), 0, 1))) {
        $lastpost = reset($lastposts);
        $discussionobject = new stdClass();
        $discussionobject->id           = $discussionid;
        $discussionobject->usermodified = $lastpost->userid;
        $discussionobject->timemodified = $lastpost->modified;
        $DB->update_record('hsuforum_discussions', $discussionobject);
        return $lastpost->id;
    }

// To get here either we couldn't find a post for the discussion (weird)
// or we couldn't update the discussion record (weird x2)
    return false;
}


/**
 * List the actions that correspond to a view of this module.
 * This is used by the participation report.
 *
 * Note: This is not used by new logging system. Event with
 *       crud = 'r' and edulevel = LEVEL_PARTICIPATING will
 *       be considered as view action.
 *
 * @return array
 */
function hsuforum_get_view_actions() {
    return array('view discussion', 'search', 'forum', 'forums', 'subscribers', 'view forum');
}

/**
 * List the actions that correspond to a post of this module.
 * This is used by the participation report.
 *
 * Note: This is not used by new logging system. Event with
 *       crud = ('c' || 'u' || 'd') and edulevel = LEVEL_PARTICIPATING
 *       will be considered as post action.
 *
 * @return array
 */
function hsuforum_get_post_actions() {
    return array('add discussion','add post','delete discussion','delete post','move discussion','prune post','update post');
}

/**
 * Returns a warning object if a user has reached the number of posts equal to
 * the warning/blocking setting, or false if there is no warning to show.
 *
 * @param int|stdClass $forum the forum id or the forum object
 * @param stdClass $cm the course module
 * @return stdClass|bool returns an object with the warning information, else
 *         returns false if no warning is required.
 */
function hsuforum_check_throttling($forum, $cm = null) {
    global $CFG, $DB, $USER;

    if (is_numeric($forum)) {
        $forum = $DB->get_record('hsuforum', array('id' => $forum), '*', MUST_EXIST);
    }

    if (!is_object($forum)) {
        return false; // This is broken.
    }

    if (!$cm) {
        $cm = get_coursemodule_from_instance('hsuforum', $forum->id, $forum->course, false, MUST_EXIST);
    }

    if (empty($forum->blockafter)) {
        return false;
    }

    if (empty($forum->blockperiod)) {
        return false;
    }

    $modcontext = context_module::instance($cm->id);
    if (has_capability('mod/hsuforum:postwithoutthrottling', $modcontext)) {
        return false;
    }

    // Get the number of posts in the last period we care about.
    $timenow = time();
    $timeafter = $timenow - $forum->blockperiod;
    $numposts = $DB->count_records_sql('SELECT COUNT(p.id) FROM {hsuforum_posts} p
                                        JOIN {hsuforum_discussions} d
                                        ON p.discussion = d.id WHERE d.forum = ?
                                        AND p.userid = ? AND p.created > ?', array($forum->id, $USER->id, $timeafter));

    $a = new stdClass();
    $a->blockafter = $forum->blockafter;
    $a->numposts = $numposts;
    $a->blockperiod = get_string('secondstotime'.$forum->blockperiod);

    if ($forum->blockafter <= $numposts) {
        $warning = new stdClass();
        $warning->canpost = false;
        $warning->errorcode = 'forumblockingtoomanyposts';
        $warning->module = 'error';
        $warning->additional = $a;
        $warning->link = $CFG->wwwroot . '/mod/hsuforum/view.php?f=' . $forum->id;

        return $warning;
    }

    if ($forum->warnafter <= $numposts) {
        $warning = new stdClass();
        $warning->canpost = true;
        $warning->errorcode = 'forumblockingalmosttoomanyposts';
        $warning->module = 'hsuforum';
        $warning->additional = $a;
        $warning->link = null;

        return $warning;
    }

    return false;
}

/**
 * Throws an error if the user is no longer allowed to post due to having reached
 * or exceeded the number of posts specified in 'Post threshold for blocking'
 * setting.
 *
 * @since Moodle 2.5
 * @param stdClass $thresholdwarning the warning information returned
 *        from the function hsuforum_check_throttling.
 */
function hsuforum_check_blocking_threshold($thresholdwarning) {
    if (!empty($thresholdwarning) && !$thresholdwarning->canpost) {
        print_error($thresholdwarning->errorcode,
                    $thresholdwarning->module,
                    $thresholdwarning->link,
                    $thresholdwarning->additional);
    }
}


/**
 * Removes all grades from gradebook
 *
 * @global object
 * @global object
 * @param int $courseid
 * @param string $type optional
 */
function hsuforum_reset_gradebook($courseid, $type='') {
    global $DB;

    $wheresql = '';
    $params = array($courseid);
    if ($type) {
        $wheresql = "AND f.type=?";
        $params[] = $type;
    }

    $sql = "SELECT f.*, cm.idnumber as cmidnumber, f.course as courseid
              FROM {hsuforum} f, {course_modules} cm, {modules} m
             WHERE m.name='hsuforum' AND m.id=cm.module AND cm.instance=f.id AND f.course=? $wheresql";

    if ($forums = $DB->get_records_sql($sql, $params)) {
        foreach ($forums as $forum) {
            hsuforum_grade_item_update($forum, 'reset');
        }
    }
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 * This function will remove all posts from the specified forum
 * and clean up any related data.
 *
 * @global object
 * @global object
 * @param $data the data submitted from the reset course.
 * @return array status array
 */
function hsuforum_reset_userdata($data) {
    global $CFG, $DB;
    require_once($CFG->dirroot.'/rating/lib.php');

    $componentstr = get_string('modulenameplural', 'hsuforum');
    $status = array();

    $params = array($data->courseid);

    $removeposts = false;
    $typesql     = "";
    if (!empty($data->reset_hsuforum_all)) {
        $removeposts = true;
        $typesstr    = get_string('resetforumsall', 'hsuforum');
        $types       = array();
    } else if (!empty($data->reset_hsuforum_types)){
        $removeposts = true;
        $types       = array();
        $sqltypes    = array();
        $hsuforum_types_all = hsuforum_get_hsuforum_types_all();
        foreach ($data->reset_hsuforum_types as $type) {
            if (!array_key_exists($type, $hsuforum_types_all)) {
                continue;
            }
            $types[] = $hsuforum_types_all[$type];
            $sqltypes[] = $type;
        }
        if (!empty($sqltypes)) {
            list($typesql, $typeparams) = $DB->get_in_or_equal($sqltypes);
            $typesql = " AND f.type " . $typesql;
            $params = array_merge($params, $typeparams);
        }
        $typesstr = get_string('resetforums', 'hsuforum').': '.implode(', ', $types);
    }
    $alldiscussionssql = "SELECT fd.id
                            FROM {hsuforum_discussions} fd, {hsuforum} f
                           WHERE f.course=? AND f.id=fd.forum";

    $allforumssql      = "SELECT f.id
                            FROM {hsuforum} f
                           WHERE f.course=?";

    $allpostssql       = "SELECT fp.id
                            FROM {hsuforum_posts} fp, {hsuforum_discussions} fd, {hsuforum} f
                           WHERE f.course=? AND f.id=fd.forum AND fd.id=fp.discussion";

    $forumssql = $forums = $rm = null;

    if( $removeposts || !empty($data->reset_hsuforum_ratings) ) {
        $forumssql      = "$allforumssql $typesql";
        $forums = $forums = $DB->get_records_sql($forumssql, $params);
        $rm = new rating_manager();
        $ratingdeloptions = new stdClass;
        $ratingdeloptions->component = 'mod_hsuforum';
        $ratingdeloptions->ratingarea = 'post';
    }

    if ($removeposts) {
        $discussionssql = "$alldiscussionssql $typesql";
        $postssql       = "$allpostssql $typesql";

        // now get rid of all attachments
        $fs = get_file_storage();
        if ($forums) {
            foreach ($forums as $forumid=>$unused) {
                if (!$cm = get_coursemodule_from_instance('hsuforum', $forumid)) {
                    continue;
                }
                $context = context_module::instance($cm->id);
                $fs->delete_area_files($context->id, 'mod_hsuforum', 'attachment');
                $fs->delete_area_files($context->id, 'mod_hsuforum', 'post');

                //remove ratings
                $ratingdeloptions->contextid = $context->id;
                $rm->delete_ratings($ratingdeloptions);
            }
        }

        // first delete all read flags
        $DB->delete_records_select('hsuforum_read', "forumid IN ($forumssql)", $params);

        // remove tracking prefs
        $DB->delete_records_select('hsuforum_track_prefs', "forumid IN ($forumssql)", $params);

        // remove posts from queue
        $DB->delete_records_select('hsuforum_queue', "discussionid IN ($discussionssql)", $params);

        // all posts - initial posts must be kept in single simple discussion forums
        $DB->delete_records_select('hsuforum_posts', "discussion IN ($discussionssql) AND parent <> 0", $params); // first all children
        $DB->delete_records_select('hsuforum_posts', "discussion IN ($discussionssql AND f.type <> 'single') AND parent = 0", $params); // now the initial posts for non single simple

        // finally all discussions except single simple forums
        $DB->delete_records_select('hsuforum_discussions', "forum IN ($forumssql AND f.type <> 'single')", $params);

        // remove all grades from gradebook
        if (empty($data->reset_gradebook_grades)) {
            if (empty($types)) {
                hsuforum_reset_gradebook($data->courseid);
            } else {
                foreach ($types as $type) {
                    hsuforum_reset_gradebook($data->courseid, $type);
                }
            }
        }

        $status[] = array('component'=>$componentstr, 'item'=>$typesstr, 'error'=>false);
    }

    // remove all ratings in this course's forums
    if (!empty($data->reset_hsuforum_ratings)) {
        if ($forums) {
            foreach ($forums as $forumid=>$unused) {
                if (!$cm = get_coursemodule_from_instance('hsuforum', $forumid)) {
                    continue;
                }
                $context = context_module::instance($cm->id);

                //remove ratings
                $ratingdeloptions->contextid = $context->id;
                $rm->delete_ratings($ratingdeloptions);
            }
        }

        // remove all grades from gradebook
        if (empty($data->reset_gradebook_grades)) {
            hsuforum_reset_gradebook($data->courseid);
        }
    }

    // remove all digest settings unconditionally - even for users still enrolled in course.
    if (!empty($data->reset_forum_digests)) {
        $DB->delete_records_select('hsuforum_digests', "forum IN ($allforumssql)", $params);
        $status[] = array('component' => $componentstr, 'item' => get_string('resetdigests', 'hsuforum'), 'error' => false);
    }

    // remove all subscriptions unconditionally - even for users still enrolled in course
    if (!empty($data->reset_hsuforum_subscriptions)) {
        $DB->delete_records_select('hsuforum_subscriptions', "forum IN ($allforumssql)", $params);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('resetsubscriptions','hsuforum'), 'error'=>false);
    }

    // remove all tracking prefs unconditionally - even for users still enrolled in course
    if (!empty($data->reset_hsuforum_track_prefs)) {
        $DB->delete_records_select('hsuforum_track_prefs', "forumid IN ($allforumssql)", $params);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('resettrackprefs','hsuforum'), 'error'=>false);
    }

    /// updating dates - shift may be negative too
    if ($data->timeshift) {
        shift_course_mod_dates('hsuforum', array('assesstimestart', 'assesstimefinish'), $data->timeshift, $data->courseid);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('datechanged'), 'error'=>false);
    }

    return $status;
}

/**
 * Called by course/reset.php
 *
 * @param $mform form passed by reference
 */
function hsuforum_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'forumheader', get_string('modulenameplural', 'hsuforum'));

    $mform->addElement('checkbox', 'reset_hsuforum_all', get_string('resetforumsall','hsuforum'));

    $mform->addElement('select', 'reset_hsuforum_types', get_string('resetforums', 'hsuforum'), hsuforum_get_hsuforum_types_all(), array('multiple' => 'multiple'));
    $mform->setAdvanced('reset_hsuforum_types');
    $mform->disabledIf('reset_hsuforum_types', 'reset_hsuforum_all', 'checked');

    $mform->addElement('checkbox', 'reset_hsuforum_digests', get_string('resetdigests', 'hsuforum'));
    $mform->setAdvanced('reset_hsuforum_digests');

    $mform->addElement('checkbox', 'reset_hsuforum_subscriptions', get_string('resetsubscriptions','hsuforum'));
    $mform->setAdvanced('reset_hsuforum_subscriptions');

    $mform->addElement('checkbox', 'reset_hsuforum_track_prefs', get_string('resettrackprefs','hsuforum'));
    $mform->setAdvanced('reset_hsuforum_track_prefs');
    $mform->disabledIf('reset_hsuforum_track_prefs', 'reset_hsuforum_all', 'checked');

    $mform->addElement('checkbox', 'reset_hsuforum_ratings', get_string('deleteallratings'));
    $mform->disabledIf('reset_hsuforum_ratings', 'reset_hsuforum_all', 'checked');
}

/**
 * Course reset form defaults.
 * @return array
 */
function hsuforum_reset_course_form_defaults($course) {
    return array('reset_hsuforum_all'=>1, 'reset_hsuforum_digests' => 0, 'reset_hsuforum_subscriptions'=>0, 'reset_hsuforum_track_prefs'=>0, 'reset_hsuforum_ratings'=>1);
}

/**
 * Returns array of forum types chooseable on the forum editing form
 *
 * @return array
 */
function hsuforum_get_hsuforum_types() {
    return array ('general'  => get_string('generalforum', 'hsuforum'),
                  'eachuser' => get_string('eachuserforum', 'hsuforum'),
                  'single'   => get_string('singleforum', 'hsuforum'),
                  'qanda'    => get_string('qandaforum', 'hsuforum'),
                  'blog'     => get_string('blogforum', 'hsuforum'));
}

/**
 * Returns array of all forum layout modes
 *
 * @return array
 */
function hsuforum_get_hsuforum_types_all() {
    return array ('news'     => get_string('namenews','hsuforum'),
                  'social'   => get_string('namesocial','hsuforum'),
                  'general'  => get_string('generalforum', 'hsuforum'),
                  'eachuser' => get_string('eachuserforum', 'hsuforum'),
                  'single'   => get_string('singleforum', 'hsuforum'),
                  'qanda'    => get_string('qandaforum', 'hsuforum'),
                  'blog'     => get_string('blogforum', 'hsuforum'));
}

/**
 * Returns array of hsuforum grade types
 */
function hsuforum_get_grading_types(){
    return array(
        HSUFORUM_GRADETYPE_NONE   => get_string('gradetypenone', 'hsuforum'),
        HSUFORUM_GRADETYPE_MANUAL => get_string('gradetypemanual', 'hsuforum'),
        HSUFORUM_GRADETYPE_RATING => get_string('gradetyperating', 'hsuforum')
    );
}

/**
 * Returns all other caps used in module
 *
 * @return array
 */
function hsuforum_get_extra_capabilities() {
    return array('moodle/site:accessallgroups', 'moodle/site:viewfullnames', 'moodle/site:trustcontent', 'moodle/rating:view', 'moodle/rating:viewany', 'moodle/rating:viewall', 'moodle/rating:rate');
}

/**
 * Adds module specific settings to the settings block
 *
 * @param settings_navigation $settings The settings navigation object
 * @param navigation_node $forumnode The node to add module settings to
 */
function hsuforum_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $forumnode) {
    global $USER, $PAGE, $CFG, $DB;

    $config = get_config('hsuforum');

    $forumobject = $DB->get_record("hsuforum", array("id" => $PAGE->cm->instance));
    if (empty($PAGE->cm->context)) {
        $PAGE->cm->context = context_module::instance($PAGE->cm->instance);
    }

    // for some actions you need to be enrolled, beiing admin is not enough sometimes here
    $enrolled = is_enrolled($PAGE->cm->context, $USER, '', false);
    $activeenrolled = is_enrolled($PAGE->cm->context, $USER, '', true);

    $canmanage  = has_capability('mod/hsuforum:managesubscriptions', $PAGE->cm->context);
    $subscriptionmode = hsuforum_get_forcesubscribed($forumobject);
    $cansubscribe = ($activeenrolled && $subscriptionmode != HSUFORUM_FORCESUBSCRIBE && ($subscriptionmode != HSUFORUM_DISALLOWSUBSCRIBE || $canmanage));

    $discussionid = optional_param('d', 0, PARAM_INT);
    $viewingdiscussion = ($PAGE->url->compare(new moodle_url('/mod/hsuforum/discuss.php'), URL_MATCH_BASE) and $discussionid);

    if (!is_guest($PAGE->cm->context) && has_capability('mod/hsuforum:exportdiscussion', $PAGE->cm->context)) {
        $forumnode->add(get_string('export', 'hsuforum'), new moodle_url('/mod/hsuforum/route.php', array('contextid' => $PAGE->cm->context->id, 'action' => 'export')), navigation_node::TYPE_SETTING, null, null, new pix_icon('i/export', get_string('export', 'hsuforum')));
    }
    $forumnode->add(get_string('viewposters', 'hsuforum'), new moodle_url('/mod/hsuforum/route.php', array('contextid' => $PAGE->cm->context->id, 'action' => 'viewposters')), navigation_node::TYPE_SETTING, null, null, new pix_icon('t/preview', get_string('viewposters', 'hsuforum')));

    if ($canmanage) {
        $mode = $forumnode->add(get_string('subscriptionmode', 'hsuforum'), null, navigation_node::TYPE_CONTAINER);

        $allowchoice = $mode->add(get_string('subscriptionoptional', 'hsuforum'), new moodle_url('/mod/hsuforum/subscribe.php', array('id'=>$forumobject->id, 'mode'=>HSUFORUM_CHOOSESUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);
        $forceforever = $mode->add(get_string("subscriptionforced", "hsuforum"), new moodle_url('/mod/hsuforum/subscribe.php', array('id'=>$forumobject->id, 'mode'=>HSUFORUM_FORCESUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);
        $forceinitially = $mode->add(get_string("subscriptionauto", "hsuforum"), new moodle_url('/mod/hsuforum/subscribe.php', array('id'=>$forumobject->id, 'mode'=>HSUFORUM_INITIALSUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);
        $disallowchoice = $mode->add(get_string('subscriptiondisabled', 'hsuforum'), new moodle_url('/mod/hsuforum/subscribe.php', array('id'=>$forumobject->id, 'mode'=>HSUFORUM_DISALLOWSUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);

        switch ($subscriptionmode) {
            case HSUFORUM_CHOOSESUBSCRIBE : // 0
                $allowchoice->action = null;
                $allowchoice->add_class('activesetting');
                break;
            case HSUFORUM_FORCESUBSCRIBE : // 1
                $forceforever->action = null;
                $forceforever->add_class('activesetting');
                break;
            case HSUFORUM_INITIALSUBSCRIBE : // 2
                $forceinitially->action = null;
                $forceinitially->add_class('activesetting');
                break;
            case HSUFORUM_DISALLOWSUBSCRIBE : // 3
                $disallowchoice->action = null;
                $disallowchoice->add_class('activesetting');
                break;
        }

    } else if ($activeenrolled) {

        switch ($subscriptionmode) {
            case HSUFORUM_CHOOSESUBSCRIBE : // 0
                $notenode = $forumnode->add(get_string('subscriptionoptional', 'hsuforum'));
                break;
            case HSUFORUM_FORCESUBSCRIBE : // 1
                $notenode = $forumnode->add(get_string('subscriptionforced', 'hsuforum'));
                break;
            case HSUFORUM_INITIALSUBSCRIBE : // 2
                $notenode = $forumnode->add(get_string('subscriptionauto', 'hsuforum'));
                break;
            case HSUFORUM_DISALLOWSUBSCRIBE : // 3
                $notenode = $forumnode->add(get_string('subscriptiondisabled', 'hsuforum'));
                break;
        }
    }

    if ($cansubscribe) {
        if (hsuforum_is_subscribed($USER->id, $forumobject)) {
            $linktext = get_string('unsubscribe', 'hsuforum');
        } else {
            $linktext = get_string('subscribe', 'hsuforum');
        }
        $url = new moodle_url('/mod/hsuforum/subscribe.php', array('id'=>$forumobject->id, 'sesskey'=>sesskey()));
        $forumnode->add($linktext, $url, navigation_node::TYPE_SETTING);
    }

    if ($viewingdiscussion) {
        require_once(__DIR__.'/lib/discussion/subscribe.php');
        $subscribe = new hsuforum_lib_discussion_subscribe($forumobject, $PAGE->cm->context);

        if ($subscribe->can_subscribe()) {
            $subscribeurl = new moodle_url('/mod/hsuforum/route.php', array(
                'contextid'    => $PAGE->cm->context->id,
                'action'       => 'subscribedisc',
                'discussionid' => $discussionid,
                'sesskey'      => sesskey(),
                'returnurl'    => $PAGE->url,
            ));

            if ($subscribe->is_subscribed($discussionid)) {
                $linktext = get_string('unsubscribedisc', 'hsuforum');
            } else {
                $linktext = get_string('subscribedisc', 'hsuforum');
            }
            $forumnode->add($linktext, $subscribeurl, navigation_node::TYPE_SETTING);
        }
    }


    if (has_capability('mod/hsuforum:viewsubscribers', $PAGE->cm->context)){
        $url = new moodle_url('/mod/hsuforum/subscribers.php', array('id'=>$forumobject->id));
        $forumnode->add(get_string('showsubscribers', 'hsuforum'), $url, navigation_node::TYPE_SETTING);

        $discsubscribers = ($viewingdiscussion or (optional_param('action', '', PARAM_ALPHA) == 'discsubscribers'));
        if ($discsubscribers
                && !hsuforum_is_forcesubscribed($forumobject)
                && $discussionid) {
            $url = new moodle_url('/mod/hsuforum/route.php', array(
                'contextid'    => $PAGE->cm->context->id,
                'action'       => 'discsubscribers',
                'discussionid' => $discussionid,
            ));
            $forumnode->add(get_string('showdiscussionsubscribers', 'hsuforum'), $url, navigation_node::TYPE_SETTING, null, 'discsubscribers');
        }
    }

    if (!isloggedin() && $PAGE->course->id == SITEID) {
        $userid = guest_user()->id;
    } else {
        $userid = $USER->id;
    }

    $hascourseaccess = ($PAGE->course->id == SITEID) || can_access_course($PAGE->course, $userid);
    $enablerssfeeds = !empty($config->enablerssfeeds) && !empty($config->enablerssfeeds);

    if ($enablerssfeeds && $forumobject->rsstype && $forumobject->rssarticles && $hascourseaccess) {

        if (!function_exists('rss_get_url')) {
            require_once("$CFG->libdir/rsslib.php");
        }

        if ($forumobject->rsstype == 1) {
            $string = get_string('rsssubscriberssdiscussions','hsuforum');
        } else {
            $string = get_string('rsssubscriberssposts','hsuforum');
        }

        $url = new moodle_url(rss_get_url($PAGE->cm->context->id, $userid, "mod_hsuforum", $forumobject->id));
        $forumnode->add($string, $url, settings_navigation::TYPE_SETTING, null, null, new pix_icon('i/rss', ''));
    }

    // Add forum report node
    $enableforumreporting = get_config('local_forum_report', 'enableforumreporting');
    $params = [
        'courseid' => $PAGE->course->id,
        'cmid' => $PAGE->cm->id
    ];

    if ($enableforumreporting && has_capability('local/forum_report:viewforumreports', $PAGE->cm->context)) {
        $url = new moodle_url('/local/forum_report', $params);
        $forumnode->add(get_string('pluginname', 'local_forum_report'), $url, settings_navigation::TYPE_SETTING, null, null, new pix_icon('t/preview', ''));
    }
}

/**
 * Abstract class used by forum subscriber selection controls
 * @package   mod_hsuforum
 * @copyright 2009 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class hsuforum_subscriber_selector_base extends user_selector_base {

    /**
     * The id of the forum this selector is being used for
     * @var int
     */
    protected $forumid = null;
    /**
     * The context of the forum this selector is being used for
     * @var object
     */
    protected $context = null;
    /**
     * The id of the current group
     * @var int
     */
    protected $currentgroup = null;

    /**
     * Constructor method
     * @param string $name
     * @param array $options
     */
    public function __construct($name, $options) {
        $options['accesscontext'] = $options['context'];
        parent::__construct($name, $options);
        if (isset($options['context'])) {
            $this->context = $options['context'];
        }
        if (isset($options['currentgroup'])) {
            $this->currentgroup = $options['currentgroup'];
        }
        if (isset($options['forumid'])) {
            $this->forumid = $options['forumid'];
        }
    }

    /**
     * Returns an array of options to seralise and store for searches
     *
     * @return array
     */
    protected function get_options() {
        global $CFG;
        $options = parent::get_options();
        $options['file'] =  substr(__FILE__, strlen($CFG->dirroot.'/'));
        $options['context'] = $this->context;
        $options['currentgroup'] = $this->currentgroup;
        $options['forumid'] = $this->forumid;
        return $options;
    }

}

/**
 * A user selector control for potential subscribers to the selected forum
 * @package   mod_hsuforum
 * @copyright 2009 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hsuforum_potential_subscriber_selector extends hsuforum_subscriber_selector_base {
    /**
     * If set to true EVERYONE in this course is force subscribed to this forum
     * @var bool
     */
    protected $forcesubscribed = false;
    /**
     * Can be used to store existing subscribers so that they can be removed from
     * the potential subscribers list
     */
    protected $existingsubscribers = array();

    /**
     * Constructor method
     * @param string $name
     * @param array $options
     */
    public function __construct($name, $options) {
        parent::__construct($name, $options);
        if (isset($options['forcesubscribed'])) {
            $this->forcesubscribed=true;
        }
    }

    /**
     * Returns an arary of options for this control
     * @return array
     */
    protected function get_options() {
        $options = parent::get_options();
        if ($this->forcesubscribed===true) {
            $options['forcesubscribed']=1;
        }
        return $options;
    }

    /**
     * Finds all potential users
     *
     * Potential subscribers are all enroled users who are not already subscribed.
     *
     * @param string $search
     * @return array
     */
    public function find_users($search) {
        global $DB;

        $whereconditions = array();
        list($wherecondition, $params) = $this->search_sql($search, 'u');
        if ($wherecondition) {
            $whereconditions[] = $wherecondition;
        }

        if (!$this->forcesubscribed) {
            $existingids = array();
            foreach ($this->existingsubscribers as $group) {
                foreach ($group as $user) {
                    $existingids[$user->id] = 1;
                }
            }
            if ($existingids) {
                list($usertest, $userparams) = $DB->get_in_or_equal(
                        array_keys($existingids), SQL_PARAMS_NAMED, 'existing', false);
                $whereconditions[] = 'u.id ' . $usertest;
                $params = array_merge($params, $userparams);
            }
        }

        if ($whereconditions) {
            $wherecondition = 'WHERE ' . implode(' AND ', $whereconditions);
        }

        list($esql, $eparams) = get_enrolled_sql($this->context, '', $this->currentgroup, true);
        $params = array_merge($params, $eparams);

        $fields      = 'SELECT ' . $this->required_fields_sql('u');
        $countfields = 'SELECT COUNT(u.id)';

        $sql = " FROM {user} u
                 JOIN ($esql) je ON je.id = u.id
                      $wherecondition";

        list($sort, $sortparams) = users_order_by_sql('u', $search, $this->accesscontext);
        $order = ' ORDER BY ' . $sort;

        // Check to see if there are too many to show sensibly.
        if (!$this->is_validating()) {
            $potentialmemberscount = $DB->count_records_sql($countfields . $sql, $params);
            if ($potentialmemberscount > $this->maxusersperpage) {
                return $this->too_many_results($search, $potentialmemberscount);
            }
        }

        // If not, show them.
        $availableusers = $DB->get_records_sql($fields . $sql . $order, array_merge($params, $sortparams));

        if (empty($availableusers)) {
            return array();
        }

        if ($this->forcesubscribed) {
            return array(get_string("existingsubscribers", 'hsuforum') => $availableusers);
        } else {
            return array(get_string("potentialsubscribers", 'hsuforum') => $availableusers);
        }
    }

    /**
     * Sets the existing subscribers
     * @param array $users
     */
    public function set_existing_subscribers(array $users) {
        $this->existingsubscribers = $users;
    }

    /**
     * Sets this forum as force subscribed or not
     */
    public function set_force_subscribed($setting=true) {
        $this->forcesubscribed = true;
    }
}

/**
 * User selector control for removing subscribed users
 * @package   mod_hsuforum
 * @copyright 2009 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hsuforum_existing_subscriber_selector extends hsuforum_subscriber_selector_base {

    /**
     * Finds all subscribed users
     *
     * @param string $search
     * @return array
     */
    public function find_users($search) {
        global $DB;
        list($wherecondition, $params) = $this->search_sql($search, 'u');
        $params['forumid'] = $this->forumid;

        // only active enrolled or everybody on the frontpage
        list($esql, $eparams) = get_enrolled_sql($this->context, '', $this->currentgroup, true);
        $fields = $this->required_fields_sql('u');
        list($sort, $sortparams) = users_order_by_sql('u', $search, $this->accesscontext);
        $params = array_merge($params, $eparams, $sortparams);

        $subscribers = $DB->get_records_sql("SELECT $fields
                                               FROM {user} u
                                               JOIN ($esql) je ON je.id = u.id
                                               JOIN {hsuforum_subscriptions} s ON s.userid = u.id
                                              WHERE $wherecondition AND s.forum = :forumid
                                           ORDER BY $sort", $params);

        return array(get_string("existingsubscribers", 'hsuforum') => $subscribers);
    }

}

/**
 * Adds information about recent messages for the course view page
 * to the course-module object.
 * @param cm_info $cm Course-module object
 */
function hsuforum_cm_info_view(cm_info $cm) {

    if (!$cm->get_user_visible) {
        return;
    }

    $config = get_config('hsuforum');
    $forum = hsuforum_get_cm_forum($cm);

    $out = '';

    if (empty($config->hiderecentposts) && $forum->showrecent) {
        $out .= hsuforum_recent_activity($cm->get_course(), true, 0, $forum->id);
    }

    if ($unread = hsuforum_count_forum_unread_posts($cm, $cm->get_course())) {
        $out .= '<a class="unread" href="' . $cm->get_url . '">';
        if ($unread == 1) {
            $out .= get_string('unreadpostsone', 'hsuforum');
        } else {
            $out .= get_string('unreadpostsnumber', 'hsuforum', $unread);
        }
        $out .= '</a>';
    }
    $cachedcminfo = new cached_cm_info();
    $cachedcminfo->content = $cm->get_formatted_content(array('overflowdiv' => true, 'noclean' => true));;

    if(property_exists($cm, 'content') && strlen($cachedcminfo->content) > 0) {
        $out = $cachedcminfo->content . $out;
    }
    $cm->set_content($out); // append the unreadpost section to existing content
}

/**
 * Return a list of page types
 * @param string $pagetype current page type
 * @param stdClass $parentcontext Block's parent context
 * @param stdClass $currentcontext Current context of block
 */
function hsuforum_page_type_list($pagetype, $parentcontext, $currentcontext) {
    $hsuforum_pagetype = array(
        'mod-hsuforum-*'=>get_string('page-mod-hsuforum-x', 'hsuforum'),
        'mod-hsuforum-view'=>get_string('page-mod-hsuforum-view', 'hsuforum'),
        'mod-hsuforum-discuss'=>get_string('page-mod-hsuforum-discuss', 'hsuforum')
    );
    return $hsuforum_pagetype;
}

/**
 * Gets all of the courses where the provided user has posted in a forum.
 *
 * @global moodle_database $DB The database connection
 * @param stdClass $user The user who's posts we are looking for
 * @param bool $discussionsonly If true only look for discussions started by the user
 * @param bool $includecontexts If set to trye contexts for the courses will be preloaded
 * @param int $limitfrom The offset of records to return
 * @param int $limitnum The number of records to return
 * @return array An array of courses
 */
function hsuforum_get_courses_user_posted_in($user, $discussionsonly = false, $includecontexts = true, $limitfrom = null, $limitnum = null) {
    global $DB;

    // If we are only after discussions we need only look at the hsuforum_discussions
    // table and join to the userid there. If we are looking for posts then we need
    // to join to the hsuforum_posts table.
    if (!$discussionsonly) {
        $joinsql = 'JOIN {hsuforum_discussions} fd ON fd.course = c.id
                    JOIN {hsuforum_posts} fp ON fp.discussion = fd.id';
        $wheresql = 'fp.userid = :userid';
        $params = array('userid' => $user->id);
    } else {
        $joinsql = 'JOIN {hsuforum_discussions} fd ON fd.course = c.id';
        $wheresql = 'fd.userid = :userid';
        $params = array('userid' => $user->id);
    }

    // Join to the context table so that we can preload contexts if required.
    if ($includecontexts) {
        $ctxselect = ', ' . context_helper::get_preload_record_columns_sql('ctx');
        $ctxjoin = "LEFT JOIN {context} ctx ON (ctx.instanceid = c.id AND ctx.contextlevel = :contextlevel)";
        $params['contextlevel'] = CONTEXT_COURSE;
    } else {
        $ctxselect = '';
        $ctxjoin = '';
    }

    // Now we need to get all of the courses to search.
    // All courses where the user has posted within a forum will be returned.
    $sql = "SELECT DISTINCT c.* $ctxselect
            FROM {course} c
            $joinsql
            $ctxjoin
            WHERE $wheresql";
    $courses = $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
    if ($includecontexts) {
        array_map('context_helper::preload_from_record', $courses);
    }
    return $courses;
}

/**
 * Gets all of the forums a user has posted in for one or more courses.
 *
 * @global moodle_database $DB
 * @param stdClass $user
 * @param array $courseids An array of courseids to search or if not provided
 *                       all courses the user has posted within
 * @param bool $discussionsonly If true then only forums where the user has started
 *                       a discussion will be returned.
 * @param int $limitfrom The offset of records to return
 * @param int $limitnum The number of records to return
 * @return array An array of forums the user has posted within in the provided courses
 */
function hsuforum_get_forums_user_posted_in($user, array $courseids = null, $discussionsonly = false, $limitfrom = null, $limitnum = null) {
    global $DB;

    if (!is_null($courseids)) {
        list($coursewhere, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'courseid');
        $coursewhere = ' AND f.course '.$coursewhere;
    } else {
        $coursewhere = '';
        $params = array();
    }
    $params['userid'] = $user->id;
    $params['forum'] = 'hsuforum';

    if ($discussionsonly) {
        $join = 'JOIN {hsuforum_discussions} ff ON ff.forum = f.id';
    } else {
        $join = 'JOIN {hsuforum_discussions} fd ON fd.forum = f.id
                 JOIN {hsuforum_posts} ff ON ff.discussion = fd.id';
    }

    $sql = "SELECT f.*, cm.id AS cmid
              FROM {hsuforum} f
              JOIN {course_modules} cm ON cm.instance = f.id
              JOIN {modules} m ON m.id = cm.module
              JOIN (
                  SELECT f.id
                    FROM {hsuforum} f
                    {$join}
                   WHERE ff.userid = :userid
                GROUP BY f.id
                   ) j ON j.id = f.id
             WHERE m.name = :forum
                 {$coursewhere}";

    $courseforums = $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
    return $courseforums;
}

/**
 * Returns posts made by the selected user in the requested courses.
 *
 * This method can be used to return all of the posts made by the requested user
 * within the given courses.
 * For each course the access of the current user and requested user is checked
 * and then for each post access to the post and forum is checked as well.
 *
 * This function is safe to use with usercapabilities.
 *
 * @global moodle_database $DB
 * @param stdClass $user The user whose posts we want to get
 * @param array $courses The courses to search
 * @param bool $musthaveaccess If set to true errors will be thrown if the user
 *                             cannot access one or more of the courses to search
 * @param bool $discussionsonly If set to true only discussion starting posts
 *                              will be returned.
 * @param int $limitfrom The offset of records to return
 * @param int $limitnum The number of records to return
 * @return stdClass An object the following properties
 *               ->totalcount: the total number of posts made by the requested user
 *                             that the current user can see.
 *               ->courses: An array of courses the current user can see that the
 *                          requested user has posted in.
 *               ->forums: An array of forums relating to the posts returned in the
 *                         property below.
 *               ->posts: An array containing the posts to show for this request.
 */
function hsuforum_get_posts_by_user($user, array $courses, $musthaveaccess = false, $discussionsonly = false, $limitfrom = 0, $limitnum = 50) {
    global $DB, $USER, $CFG;

    $config = get_config('hsuforum');
    $return = new stdClass;
    $return->totalcount = 0;    // The total number of posts that the current user is able to view
    $return->courses = array(); // The courses the current user can access
    $return->forums = array();  // The forums that the current user can access that contain posts
    $return->posts = array();   // The posts to display

    // First up a small sanity check. If there are no courses to check we can
    // return immediately, there is obviously nothing to search.
    if (empty($courses)) {
        return $return;
    }

    // A couple of quick setups
    $isloggedin = isloggedin();
    $isguestuser = $isloggedin && isguestuser();
    $iscurrentuser = $isloggedin && $USER->id == $user->id;

    // Checkout whether or not the current user has capabilities over the requested
    // user and if so they have the capabilities required to view the requested
    // users content.
    $usercontext = context_user::instance($user->id, MUST_EXIST);
    $hascapsonuser = !$iscurrentuser && $DB->record_exists('role_assignments', array('userid' => $USER->id, 'contextid' => $usercontext->id));
    $hascapsonuser = $hascapsonuser && has_all_capabilities(array('moodle/user:viewdetails', 'moodle/user:readuserposts'), $usercontext);

    // Before we actually search each course we need to check the user's access to the
    // course. If the user doesn't have the appropraite access then we either throw an
    // error if a particular course was requested or we just skip over the course.
    foreach ($courses as $course) {
        $coursecontext = context_course::instance($course->id, MUST_EXIST);
        if ($iscurrentuser || $hascapsonuser) {
            // If it is the current user, or the current user has capabilities to the
            // requested user then all we need to do is check the requested users
            // current access to the course.
            // Note: There is no need to check group access or anything of the like
            // as either the current user is the requested user, or has granted
            // capabilities on the requested user. Either way they can see what the
            // requested user posted, although its VERY unlikely in the `parent` situation
            // that the current user will be able to view the posts in context.
            if (!is_viewing($coursecontext, $user) && !is_enrolled($coursecontext, $user)) {
                // Need to have full access to a course to see the rest of own info
                if ($musthaveaccess) {
                    print_error('errorenrolmentrequired', 'hsuforum');
                }
                continue;
            }
        } else {
            // Check whether the current user is enrolled or has access to view the course
            // if they don't we immediately have a problem.
            if (!can_access_course($course)) {
                if ($musthaveaccess) {
                    print_error('errorenrolmentrequired', 'hsuforum');
                }
                continue;
            }

            // Check whether the requested user is enrolled or has access to view the course
            // if they don't we immediately have a problem.
            if (!can_access_course($course, $user) && !is_enrolled($coursecontext, $user)) {
                if ($musthaveaccess) {
                    print_error('notenrolled', 'hsuforum');
                }
                continue;
            }

            // If groups are in use and enforced throughout the course then make sure
            // we can meet in at least one course level group.
            // Note that we check if either the current user or the requested user have
            // the capability to access all groups. This is because with that capability
            // a user in group A could post in the group B forum. Grrrr.
            if (groups_get_course_groupmode($course) == SEPARATEGROUPS && $course->groupmodeforce
              && !has_capability('moodle/site:accessallgroups', $coursecontext) && !has_capability('moodle/site:accessallgroups', $coursecontext, $user->id)) {
                // If its the guest user to bad... the guest user cannot access groups
                if (!$isloggedin or $isguestuser) {
                    // do not use require_login() here because we might have already used require_login($course)
                    if ($musthaveaccess) {
                        redirect(get_login_url());
                    }
                    continue;
                }
                // Get the groups of the current user
                $mygroups = array_keys(groups_get_all_groups($course->id, $USER->id, $course->defaultgroupingid, 'g.id, g.name'));
                // Get the groups the requested user is a member of
                $usergroups = array_keys(groups_get_all_groups($course->id, $user->id, $course->defaultgroupingid, 'g.id, g.name'));
                // Check whether they are members of the same group. If they are great.
                $intersect = array_intersect($mygroups, $usergroups);
                if (empty($intersect)) {
                    // But they're not... if it was a specific course throw an error otherwise
                    // just skip this course so that it is not searched.
                    if ($musthaveaccess) {
                        print_error("groupnotamember", '', $CFG->wwwroot."/course/view.php?id=$course->id");
                    }
                    continue;
                }
            }
        }
        // Woo hoo we got this far which means the current user can search this
        // this course for the requested user. Although this is only the course accessibility
        // handling that is complete, the forum accessibility tests are yet to come.
        $return->courses[$course->id] = $course;
    }
    // No longer beed $courses array - lose it not it may be big
    unset($courses);

    // Make sure that we have some courses to search
    if (empty($return->courses)) {
        // If we don't have any courses to search then the reality is that the current
        // user doesn't have access to any courses is which the requested user has posted.
        // Although we do know at this point that the requested user has posts.
        if ($musthaveaccess) {
            print_error('permissiondenied');
        } else {
            return $return;
        }
    }

    // Next step: Collect all of the forums that we will want to search.
    // It is important to note that this step isn't actually about searching, it is
    // about determining which forums we can search by testing accessibility.
    $forums = hsuforum_get_forums_user_posted_in($user, array_keys($return->courses), $discussionsonly);

    // Will be used to build the where conditions for the search
    $forumsearchwhere = array();
    // Will be used to store the where condition params for the search
    $forumsearchparams = array();
    // Will record forums where the user can freely access everything
    $forumsearchfullaccess = array();
    // DB caching friendly
    $now = round(time(), -2);
    // For each course to search we want to find the forums the user has posted in
    // and providing the current user can access the forum create a search condition
    // for the forum to get the requested users posts.
    foreach ($return->courses as $course) {
        // Now we need to get the forums
        $modinfo = get_fast_modinfo($course);
        if (empty($modinfo->instances['hsuforum'])) {
            // hmmm, no forums? well at least its easy... skip!
            continue;
        }
        // Iterate
        foreach ($modinfo->get_instances_of('hsuforum') as $forumid => $cm) {
            if (!$cm->uservisible or !isset($forums[$forumid])) {
                continue;
            }
            // Get the forum in question
            $forum = $forums[$forumid];

            // This is needed for functionality later on in the forum code. It is converted to an object
            // because the cm_info is readonly from 2.6. This is a dirty hack because some other parts of the
            // code were expecting an writeable object.
            $forum->cm = new stdClass();
            foreach ($cm as $key => $value) {
                $forum->cm->$key = $value;
            }

            // Check that either the current user can view the forum, or that the
            // current user has capabilities over the requested user and the requested
            // user can view the discussion
            if (!has_capability('mod/hsuforum:viewdiscussion', $cm->context) && !($hascapsonuser && has_capability('mod/hsuforum:viewdiscussion', $cm->context, $user->id))) {
                continue;
            }

            // This will contain forum specific where clauses
            $forumsearchselect = array();
            if (!$iscurrentuser && !$hascapsonuser) {
                // Make sure we check group access
                if (groups_get_activity_groupmode($cm, $course) == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $cm->context)) {
                    $groups = $modinfo->get_groups($cm->groupingid);
                    $groups[] = -1;
                    list($groupid_sql, $groupid_params) = $DB->get_in_or_equal($groups, SQL_PARAMS_NAMED, 'grps'.$forumid.'_');
                    $forumsearchparams = array_merge($forumsearchparams, $groupid_params);
                    $forumsearchselect[] = "d.groupid $groupid_sql";
                }

                // hidden timed discussions
                if (!empty($config->enabletimedposts) && !has_capability('mod/hsuforum:viewhiddentimedposts', $cm->context)) {
                    $forumsearchselect[] = "(d.userid = :userid{$forumid} OR (d.timestart < :timestart{$forumid} AND (d.timeend = 0 OR d.timeend > :timeend{$forumid})))";
                    $forumsearchparams['userid'.$forumid] = $user->id;
                    $forumsearchparams['timestart'.$forumid] = $now;
                    $forumsearchparams['timeend'.$forumid] = $now;
                }

                // qanda access
                if ($forum->type == 'qanda' && !has_capability('mod/hsuforum:viewqandawithoutposting', $cm->context)) {
                    // We need to check whether the user has posted in the qanda forum.
                    $discussionspostedin = hsuforum_discussions_user_has_posted_in($forum->id, $user->id);
                    if (!empty($discussionspostedin)) {
                        $forumonlydiscussions = array();  // Holds discussion ids for the discussions the user is allowed to see in this forum.
                        foreach ($discussionspostedin as $d) {
                            $forumonlydiscussions[] = $d->id;
                        }
                        list($discussionid_sql, $discussionid_params) = $DB->get_in_or_equal($forumonlydiscussions, SQL_PARAMS_NAMED, 'qanda'.$forumid.'_');
                        $forumsearchparams = array_merge($forumsearchparams, $discussionid_params);
                        $forumsearchselect[] = "(d.id $discussionid_sql OR p.parent = 0)";
                    } else {
                        $forumsearchselect[] = "p.parent = 0";
                    }

                }

                if (count($forumsearchselect) > 0) {
                    $forumsearchwhere[] = "(d.forum = :forum{$forumid} AND ".implode(" AND ", $forumsearchselect).")";
                    $forumsearchparams['forum'.$forumid] = $forumid;
                } else {
                    $forumsearchfullaccess[] = $forumid;
                }
            } else {
                // The current user/parent can see all of their own posts
                $forumsearchfullaccess[] = $forumid;
            }
        }
    }

    // If we dont have any search conditions, and we don't have any forums where
    // the user has full access then we just return the default.
    if (empty($forumsearchwhere) && empty($forumsearchfullaccess)) {
        return $return;
    }

    // Prepare a where condition for the full access forums.
    if (count($forumsearchfullaccess) > 0) {
        list($fullidsql, $fullidparams) = $DB->get_in_or_equal($forumsearchfullaccess, SQL_PARAMS_NAMED, 'fula');
        $forumsearchparams = array_merge($forumsearchparams, $fullidparams);
        $forumsearchwhere[] = "(d.forum $fullidsql)";
    }

    // Prepare SQL to both count and search.
    // We alias user.id to useridx because we hsuforum_posts already has a userid field and not aliasing this would break
    // oracle and mssql.
    $userfields = user_picture::fields('u', null, 'useridx');
    $countsql = 'SELECT COUNT(*) ';
    $selectsql = 'SELECT p.*, d.forum, d.name AS discussionname, '.$userfields.' ';
    $wheresql = implode(" OR ", $forumsearchwhere);

    if ($discussionsonly) {
        if ($wheresql == '') {
            $wheresql = 'p.parent = 0';
        } else {
            $wheresql = 'p.parent = 0 AND ('.$wheresql.')';
        }
    }

    $sql = "FROM {hsuforum_posts} p
            JOIN {hsuforum_discussions} d ON d.id = p.discussion
            JOIN {hsuforum} f ON f.id = d.forum
            JOIN {user} u ON u.id = p.userid
           WHERE ($wheresql)
             AND p.userid = :userid
             AND f.anonymous = 0 ";
    $orderby = "ORDER BY p.modified DESC";
    $forumsearchparams['userid'] = $user->id;

    // Set the total number posts made by the requested user that the current user can see
    $return->totalcount = $DB->count_records_sql($countsql.$sql, $forumsearchparams);
    // Set the collection of posts that has been requested
    $return->posts = $DB->get_records_sql($selectsql.$sql.$orderby, $forumsearchparams, $limitfrom, $limitnum);

    // We need to build an array of forums for which posts will be displayed.
    // We do this here to save the caller needing to retrieve them themselves before
    // printing these forums posts. Given we have the forums already there is
    // practically no overhead here.
    foreach ($return->posts as $post) {
        if (!array_key_exists($post->forum, $return->forums)) {
            $return->forums[$post->forum] = $forums[$post->forum];
        }
    }

    return $return;
}

/**
 * Extract the user object from the post object
 *
 * @param $post
 * @param $forum
 * @param context_module $context
 * @return stdClass
 */
function hsuforum_extract_postuser($post, $forum, context_module $context) {
    $postuser     = new stdClass();
    $postuser->id = $post->userid;
    $fields = array_merge(
        get_all_user_name_fields(),
        array('imagealt', 'picture', 'email')
    );
    foreach ($fields as $field) {
        if (property_exists($post, $field)) {
            $postuser->$field = $post->$field;
        }
    }
    return hsuforum_get_postuser($postuser, $post, $forum, $context);
}

/**
 * Given a user, return post user that is ready for display (EG:
 * anonymous is enforced as well as highlighting)
 *
 * @param object $user
 * @param object $post
 * @param object $forum
 * @param context_module $context
 * @return stdClass
 */
function hsuforum_get_postuser($user, $post, $forum, context_module $context) {

    static $cache = [];

    if (empty($forum->anonymous)
        or !empty($post->reveal)) {
        if (isset($cache[$user->id])) {
            return $cache[$user->id];
        }
    }

    $postuser = hsuforum_anonymize_user($user, $forum, $post);

    if (property_exists($user, 'picture')) {
        $postuser->user_picture           = new user_picture($postuser);
        $postuser->user_picture->courseid = $forum->course;
        $postuser->user_picture->link     = (!hsuforum_is_anonymous_user($postuser));
    }
    $postuser->fullname = fullname($postuser, \mod_hsuforum\local::cached_has_capability('moodle/site:viewfullnames', $context));

    if (empty($forum->anonymous)
        or !empty($post->reveal)) {
        $cache[$user->id] = $postuser;
    }

    return $postuser;
}

/**
 * @param object $user
 * @param object $forum
 * @param object $post
 * @throws coding_exception
 * @return stdClass
 * @author Mark Nielsen
 */
function hsuforum_anonymize_user($user, $forum, $post) {
    global $USER;
    static $anonymous = null;

    if (!isset($forum->anonymous) or !isset($forum->course)) {
        throw new coding_exception('Must pass the forum\'s anonymous and course fields');
    }
    if (!isset($post->reveal)) {
        throw new coding_exception('Must pass the post\'s reveal field');
    }
    if (empty($forum->anonymous)
        or !empty($post->reveal)
        // Note: we do not check $post->privatereply against $USER->id as the poster should remain private to the
        // person who was replied to.
        or ($post->userid == $USER->id)
    ) {
        return $user;
    }
    if (is_null($anonymous)) {
        $guest = guest_user();
        $anonymous = (object) array(
            'id' => $guest->id,
            'firstname' => get_string('anonymousfirstname', 'hsuforum'),
            'lastname' => get_string('anonymouslastname', 'hsuforum'),
            'firstnamephonetic' => get_string('anonymousfirstnamephonetic', 'hsuforum'),
            'lastnamephonetic' => get_string('anonymouslastnamephonetic', 'hsuforum'),
            'middlename' => get_string('anonymousmiddlename', 'hsuforum'),
            'alternatename' => get_string('anonymousalternatename', 'hsuforum'),
            'picture' => 0,
            'email' => $guest->email,
            'imagealt' => '',
            'profilelink' => new moodle_url('/user/view.php', array('id'=>$guest->id, 'course'=>$forum->course)),
            'anonymous' => true
        );
        $anonymous->fullname = fullname($anonymous, true);
        $anonymous->imagealt = $anonymous->fullname;

        // Prevent accidental reveal of user.
        foreach(get_all_user_name_fields() as $field) {
            if (!property_exists($anonymous, $field)) {
                $anonymous->$field = '';
            }
        }
    }
    $return = clone($user);
    foreach ($anonymous as $name => $value) {
        if (property_exists($user, $name)) {
            $return->$name = $value;
        }
    }
    return $return;
}

/**
 * @param $user
 * @return bool
 * @author Mark Nielsen
 */
function hsuforum_is_anonymous_user($user) {
    static $guest = null;

    if (is_null($guest)) {
        $guest = guest_user();
    }
    return ($user->id == $guest->id);
}

/**
 * Get forum record from course module instance
 * @author Guy Thomas
 *
 * @param $cm
 * @return mixed
 */
function hsuforum_get_cm_forum($cm) {
    global $DB;

    static $cache = array();

    if (!isset($cache[$cm->instance])) {
        $cache[$cm->instance] = $DB->get_record('hsuforum', array('id' => $cm->instance), '*', MUST_EXIST);
    }
    return $cache[$cm->instance];
}

/**
 * Get course record from course module instance
 * @author Guy Thomas
 *
 * @param $cm
 * @return mixed
 */
function hsuforum_get_cm_course($cm) {
    global $DB, $COURSE;

    static $cache = array();

    if (!isset($cache[$cm->instance])) {
        if ($COURSE->id == $cm->course) {
            $cache[$cm->instance] = $COURSE;
        } else {
            $cache[$cm->instance] = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
        }
    }
    return $cache[$cm->instance];
}

/**
 * Highly specialized function to extract a discussion record
 * from the hybrid object returned from hsuforum_get_discussions()
 *
 * @author Mark Nielsen
 * @param stdClass $post Our post with discussion data embedded into it
 * @param stdClass $forum The discussion's forum
 * @return object
 */
function hsuforum_extract_discussion($post, $forum) {
    $discussion = (object) array(
        'id'           => $post->discussion,
        'course'       => $forum->course,
        'forum'        => $forum->id,
        'name'         => $post->name,
        'firstpost'    => $post->firstpost,
        'pinned'       => $post->pinned,
        'userid'       => $post->userid,
        'groupid'      => $post->groupid,
        'timemodified' => $post->timemodified,
        'usermodified' => $post->usermodified,
        'timestart'    => $post->timestart,
        'timeend'      => $post->timeend,
    );

    // Rest of these are "meta" items that might not always be there.
    if (property_exists($post, 'subscriptionid')) {
        $discussion->subscriptionid = $post->subscriptionid;
    }
    if (property_exists($post, 'replies')) {
        $discussion->replies = $post->replies;
    }
    if (property_exists($post, 'unread')) {
        $discussion->unread = $post->unread;
    }
    if (property_exists($post, 'lastpostid')) {
        $discussion->lastpostid = $post->lastpostid;
    }
    return $discussion;
}

/**
 * @param stdClass $options
 * @return bool
 * @throws comment_exception
 */
function mod_hsuforum_comment_validate(stdClass $options) {
    global $USER, $DB;

    if ($options->commentarea != 'userposts_comments') {
        throw new comment_exception('invalidcommentarea');
    }
    if (!$user = $DB->get_record('user', array('id'=>$options->itemid))) {
        throw new comment_exception('invalidcommentitemid');
    }
    $context = $options->context;

    if (!$cm = get_coursemodule_from_id('hsuforum', $context->instanceid)) {
        throw new comment_exception('invalidcontext');
    }

    if (!has_capability('mod/hsuforum:rate', $context)) {
        if (!has_capability('mod/hsuforum:replypost', $context) or ($user->id != $USER->id)) {
            throw new comment_exception('nopermissiontocomment');
        }
    }

    return true;
}

function mod_hsuforum_comment_permissions(stdClass $options) {
    global $USER, $DB;

    if ($options->commentarea != 'userposts_comments') {
        throw new comment_exception('invalidcommentarea');
    }
    if (!$user = $DB->get_record('user', array('id'=>$options->itemid))) {
        throw new comment_exception('invalidcommentitemid');
    }
    $context = $options->context;

    if (!$cm = get_coursemodule_from_id('hsuforum', $context->instanceid)) {
        throw new comment_exception('invalidcontext');
    }

    if (!has_capability('mod/hsuforum:rate', $context)) {
        if (!has_capability('mod/hsuforum:replypost', $context) or ($user->id != $USER->id)) {
            return array('view' => false, 'post' => false);
        }
    }

    return array('view' => true, 'post' => true);
}

/**
 * @param array $comments
 * @param stdClass $options
 * @return mixed
 */
function mod_hsuforum_comment_display($comments, $options) {
    foreach ($comments as $comment) {
        $comment->content = file_rewrite_pluginfile_urls($comment->content, 'pluginfile.php', $options->context->id,
                'mod_hsuforum', 'comments', $comment->id);
    }

    return $comments;
}

/**
 * @param $course
 * @param $cm
 * @param $context
 * @param $filearea
 * @param $args
 * @param $forcedownload
 * @param $options
 * @return bool
 */
function hsuforum_forum_comments_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, $options) {
    global $DB, $USER;

    // Make sure this is the comments area.
    if ($filearea !== 'comments') {
        return false;
    }

    // Get the comment record.
    $commentid = (int)array_shift($args);
    if (!$comment = $DB->get_record('comments', array('id'=>$commentid))) {
        return false;
    }

    // Try to get the file.
    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_hsuforum/$filearea/$commentid/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }

    // Check permissions.
    if (!has_capability('mod/hsuforum:rate', $context)) {
        if (!has_capability('mod/hsuforum:replypost', $context) or ($comment->itemid != $USER->id)) {
            return false;
        }
    }

    // finally send the file
    send_stored_file($file, 86400, 0, true, $options);
}

/**
 * @param stdClass $comment
 * @param stdClass $options
 * @throws comment_exception
 */
function mod_hsuforum_comment_message(stdClass $comment, stdClass $options) {
    global $DB;

    if ($options->commentarea != 'userposts_comments') {
        throw new comment_exception('invalidcommentarea');
    }
    if (!$user = $DB->get_record('user', array('id'=>$options->itemid))) {
        throw new comment_exception('invalidcommentitemid');
    }
    /** @var context $context */
    $context = $options->context;

    if (!$cm = get_coursemodule_from_id('hsuforum', $context->instanceid)) {
        throw new comment_exception('invalidcontext');
    }

    // Get all the users with the ability to rate.
    $recipients = get_users_by_capability($context, 'mod/hsuforum:rate');

    // Add the item user if they are different from commenter.
    if ($comment->userid != $user->id and has_capability('mod/hsuforum:replypost', $context, $user)) {
        $recipients[$user->id] = $user;
    }

    // Sender is the author of the comment.
    $sender = $DB->get_record('user', array('id' => $comment->userid));

    // Make sure that the commenter is not getting the message.
    unset($recipients[$comment->userid]);

    if (\core_component::get_plugin_directory('local', 'joulegrader') !== null) {
        // Joule Grader is installed and control panel enabled.
        $gareaid = component_callback('local_joulegrader', 'area_from_context', array($context, 'hsuforum'));
        $contexturl = new moodle_url('/local/joulegrader/view.php', array('courseid' => $cm->course,
                'garea' => $gareaid, 'guser' => $user->id));
    } else {
        $contexturl = $context->get_url();
    }


    $params = array($comment, $recipients, $sender, $cm->name, $contexturl);
    component_callback('local_mrooms', 'comment_send_messages', $params);
}

/**
 * Set the per-forum maildigest option for the specified user.
 *
 * @param stdClass $forum The forum to set the option for.
 * @param int $maildigest The maildigest option.
 * @param stdClass $user The user object. This defaults to the global $USER object.
 * @throws invalid_digest_setting thrown if an invalid maildigest option is provided.
 */
function hsuforum_set_user_maildigest($forum, $maildigest, $user = null) {
    global $DB, $USER;

    if (is_number($forum)) {
        $forum = $DB->get_record('hsuforum', array('id' => $forum));
    }

    if ($user === null) {
        $user = $USER;
    }

    $course  = $DB->get_record('course', array('id' => $forum->course), '*', MUST_EXIST);
    $cm      = get_coursemodule_from_instance('hsuforum', $forum->id, $course->id, false, MUST_EXIST);
    $context = context_module::instance($cm->id);

    // User must be allowed to see this forum.
    require_capability('mod/hsuforum:viewdiscussion', $context, $user->id);

    // Validate the maildigest setting.
    $digestoptions = hsuforum_get_user_digest_options($user);

    if (!isset($digestoptions[$maildigest])) {
        throw new moodle_exception('invaliddigestsetting', 'mod_hsuforum');
    }

    // Attempt to retrieve any existing forum digest record.
    $subscription = $DB->get_record('hsuforum_digests', array(
        'userid' => $user->id,
        'forum' => $forum->id,
    ));

    // Create or Update the existing maildigest setting.
    if ($subscription) {
        if ($maildigest == -1) {
            $DB->delete_records('hsuforum_digests', array('forum' => $forum->id, 'userid' => $user->id));
        } else if ($maildigest !== $subscription->maildigest) {
            // Only update the maildigest setting if it's changed.

            $subscription->maildigest = $maildigest;
            $DB->update_record('hsuforum_digests', $subscription);
        }
    } else {
        if ($maildigest != -1) {
            // Only insert the maildigest setting if it's non-default.

            $subscription = new stdClass();
            $subscription->forum = $forum->id;
            $subscription->userid = $user->id;
            $subscription->maildigest = $maildigest;
            $subscription->id = $DB->insert_record('hsuforum_digests', $subscription);
        }
    }
}

/**
 * Determine the maildigest setting for the specified user against the
 * specified forum.
 *
 * @param Array $digests An array of forums and user digest settings.
 * @param stdClass $user The user object containing the id and maildigest default.
 * @param int $forumid The ID of the forum to check.
 * @return int The calculated maildigest setting for this user and forum.
 */
function hsuforum_get_user_maildigest_bulk($digests, $user, $forumid) {
    if (isset($digests[$forumid]) && isset($digests[$forumid][$user->id])) {
        $maildigest = $digests[$forumid][$user->id];
        if ($maildigest === -1) {
            $maildigest = $user->maildigest;
        }
    } else {
        $maildigest = $user->maildigest;
    }
    return $maildigest;
}

/**
 * Retrieve the list of available user digest options.
 *
 * @param stdClass $user The user object. This defaults to the global $USER object.
 * @return array The mapping of values to digest options.
 */
function hsuforum_get_user_digest_options($user = null) {
    global $USER;

    // Revert to the global user object.
    if ($user === null) {
        $user = $USER;
    }

    $digestoptions = array();
    $digestoptions['0']  = get_string('emaildigestoffshort', 'mod_hsuforum');
    $digestoptions['1']  = get_string('emaildigestcompleteshort', 'mod_hsuforum');
    $digestoptions['2']  = get_string('emaildigestsubjectsshort', 'mod_hsuforum');

    // We need to add the default digest option at the end - it relies on
    // the contents of the existing values.
    if (isset($user->maildigest)) {
        $digestoptions['-1'] = get_string('emaildigestdefault', 'mod_hsuforum',
                $digestoptions[$user->maildigest]);

        // Resort the options to be in a sensible order.
        ksort($digestoptions);
    }

    return $digestoptions;
}


/**
 * Reduce the precision of the time e.g. 1 min 10 secs ago -> 1 min ago
 * @return int
 */
function hsuforum_simpler_time($seconds) {
    if ($seconds >= DAYSECS) {
        return floor($seconds / DAYSECS) * DAYSECS;
    } else if ($seconds >= 3600) {
        return floor($seconds / 3600) * 3600;
    } else if ($seconds >= 60) {
        return floor($seconds / 60) * 60;
    } else {
        return $seconds;
    }
}

/**
 * Format a date/time (seconds) as weeks, days, hours etc as needed
 *
 * Given an amount of time in seconds, returns string
 * formatted nicely as years, days, hours etc as needed
 *
 * @package core
 * @category time
 * @uses MINSECS
 * @uses HOURSECS
 * @uses DAYSECS
 * @uses YEARSECS
 * @param int $totalsecs Time in seconds
 * @param stdClass $str Should be a time object
 * @return string A nicely formatted date/time string
 */
function hsuforum_format_time($totalsecs, $str = null) {

    $totalsecs = abs($totalsecs);

    if (!$str) {
        // Create the str structure the slow way.
        $str = new stdClass();
        $str->day   = get_string('day', 'mod_hsuforum');
        $str->days  = get_string('days', 'mod_hsuforum');
        $str->hour  = get_string('hour', 'mod_hsuforum');
        $str->hours = get_string('hours', 'mod_hsuforum');
        $str->min   = get_string('minute', 'mod_hsuforum');
        $str->mins  = get_string('minutes', 'mod_hsuforum');
        $str->sec   = get_string('second', 'mod_hsuforum');
        $str->secs  = get_string('seconds', 'mod_hsuforum');
        $str->year  = get_string('year', 'mod_hsuforum');
        $str->years = get_string('years', 'mod_hsuforum');
    }

    $years     = floor($totalsecs/YEARSECS);
    $remainder = $totalsecs - ($years*YEARSECS);
    $days      = floor($remainder/DAYSECS);
    $remainder = $totalsecs - ($days*DAYSECS);
    $hours     = floor($remainder/HOURSECS);
    $remainder = $remainder - ($hours*HOURSECS);
    $mins      = floor($remainder/MINSECS);
    $secs      = $remainder - ($mins*MINSECS);

    $ss = ($secs == 1)  ? $str->sec  : $str->secs;
    $sm = ($mins == 1)  ? $str->min  : $str->mins;
    $sh = ($hours == 1) ? $str->hour : $str->hours;
    $sd = ($days == 1)  ? $str->day  : $str->days;
    $sy = ($years == 1)  ? $str->year  : $str->years;

    $oyears = '';
    $odays = '';
    $ohours = '';
    $omins = '';
    $osecs = '';

    if ($years) {
        $oyears  = $years . $sy;
    }
    if ($days) {
        $odays  = $days . $sd;
    }
    if ($hours) {
        $ohours = $hours . $sh;
    }
    if ($mins) {
        $omins  = $mins . $sm;
    }
    if ($secs) {
        $osecs  = $secs . $ss;
    }

    if ($years) {
        return trim($oyears . $odays);
    }
    if ($days) {
        return trim($odays . $ohours);
    }
    if ($hours) {
        return trim($ohours . $omins);
    }
    if ($mins) {
        return trim($omins . $osecs);
    }
    if ($secs) {
        return $osecs;
    }
    return get_string('now', 'mod_hsuforum');
}

/**
 * Return friendly relative time (e.g. "1 min ago", "1 year ago") in a <time> tag
 *
 * @param int $timeinpast
 * @param null|array $attributes Tag attributes
 * @return string
 * @throws coding_exception
 */
function hsuforum_relative_time($timeinpast, $attributes = null) {
    if (!is_numeric($timeinpast)) {
        throw new coding_exception('Relative times must be calculated from the raw timestamp');
    }

    $precisedatetime = userdate($timeinpast);
    $datetime = date(DateTime::W3C, $timeinpast);
    $secondsago = time() - $timeinpast;

    // Default time tag attributes.
    $defaultatts = array(
        'datetime' => $datetime,
        'title' => $precisedatetime,
    );

    if (abs($secondsago) > (365 * DAYSECS)) {
        $displaytime = $precisedatetime;
        $defaultatts['title'] = '';
    } else {
        $secondsago = hsuforum_simpler_time($secondsago);
        $displaytime = hsuforum_format_time($secondsago);
        if ($secondsago != 0) {
            $displaytime = get_string('ago', 'mod_hsuforum', $displaytime);
        }
    }

    // Override default attributes with those passed in (if any).
    if (!empty($attributes)) {
        foreach ($attributes as $key => $val) {
            $defaultatts[$key] = $val;
        }
    }

    return html_writer::tag('time', $displaytime, $defaultatts);
}

/**
 * @param int $replies
 * @return string pluralized text
 */
function hsuforum_xreplies($replies) {
    if ($replies == 1) {
        return get_string('onereply', 'hsuforum');
    }
    return get_string('xreplies', 'hsuforum', $replies);
}

/**
 * Is a string empty.
 * @param string $str
 * @return bool
 */
function hsuforum_str_empty($str) {
    // Remove line breaks from string as they are just whitespace.
    $str = str_ireplace('<br/>', '', $str);
    $str = str_ireplace('<br />', '', $str);
    // Check for void tags (self closing tags like <img>).
    // Note, html5 doesn't require void tags to close with /> so we can't just use a regex to find them.
    $voidtags = array(
        'area',
        'base',
        'col',
        'command',
        'embed',
        'hr',
        'img',
        'input',
        'keygen',
        'link',
        'meta',
        'param',
        'source',
        'track',
        'wbr'
    );
    foreach ($voidtags as $check) {
        if (stripos($str, $check) !== false) {
            return false;
        }
    }
    $str = strip_tags($str);
    $str = str_ireplace('&nbsp;', '', $str);
    $str = trim($str);
    return ($str === '');
}

/**
 * Determine the current context if one was not already specified.
 *
 * If a context of type context_module is specified, it is immediately
 * returned and not checked.
 *
 * @param int $forumid The ID of the forum
 * @param context_module $context The current context.
 * @return context_module The context determined
 */
function hsuforum_get_context($forumid, $context = null) {
    global $PAGE;

    if (!$context || !($context instanceof context_module)) {
        // Find out forum context. First try to take current page context to save on DB query.
        if ($PAGE->cm && $PAGE->cm->modname === 'hsuforum' && $PAGE->cm->instance == $forumid
                && $PAGE->context->contextlevel == CONTEXT_MODULE && $PAGE->context->instanceid == $PAGE->cm->id) {
            $context = $PAGE->context;
        } else {
            $cm = get_coursemodule_from_instance('hsuforum', $forumid);
            $context = \context_module::instance($cm->id);
        }
    }

    return $context;
}

/**
 * Mark the activity completed (if required) and trigger the course_module_viewed event.
 *
 * @param  stdClass $forum   forum object
 * @param  stdClass $course  course object
 * @param  stdClass $cm      course module object
 * @param  stdClass $context context object
 * @since Moodle 2.9
 */
function hsuforum_view($forum, $course, $cm, $context) {

    // Completion.
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);

    // Trigger course_module_viewed event.

    $params = array(
        'context' => $context,
        'objectid' => $forum->id
    );

    $event = \mod_hsuforum\event\course_module_viewed::create($params);
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('hsuforum', $forum);
    $event->trigger();
}

/**
 * Trigger the discussion viewed event
 *
 * @param  stdClass $modcontext module context object
 * @param  stdClass $forum      forum object
 * @param  stdClass $discussion discussion object
 * @since Moodle 2.9
 */
function hsuforum_discussion_view($modcontext, $forum, $discussion) {
    $params = array(
        'context' => $modcontext,
        'objectid' => $discussion->id,
    );

    $event = \mod_hsuforum\event\discussion_viewed::create($params);
    $event->add_record_snapshot('hsuforum_discussions', $discussion);
    $event->add_record_snapshot('hsuforum', $forum);
    $event->trigger();
}

/**
 * Trigger the mark all posts as read
 *
 * @param  stdClass $discussion the posts id
 * @since Moodle 2.9
 */
function hsuforum_mark_all_read($discussion){
    global $DB, $USER;

    $config = get_config('hsuforum');
    $now = time();
    $cutoffdate = $now - ($config->oldpostdays * 24 * 3600);

    if (!empty($config)) {
        try {
            if ($DB->record_exists('hsuforum_discussions', array('id' => $discussion->id))) {
                $sql = "INSERT INTO {hsuforum_read} (userid, postid, discussionid, forumid, firstread, lastread)

                SELECT ?, p.id, p.discussion, d.forum, ?, ?
                  FROM {hsuforum_posts} p
                       JOIN {hsuforum_discussions} d ON d.id = p.discussion
                 WHERE p.discussion = ? AND p.modified >= ?";
                $DB->execute($sql, array($USER->id, $now, $now, $discussion->id, $cutoffdate));

            }
        } catch (Exception $e) {
            error_log("Hsuforum hsuforum_mark_all_read: " .$e->getMessage());
        }
    }
}

/**
 * Trigger the mark parent post as read
 *
 * @param  stdClass $discussion the posts id
 * @since Moodle 2.9
 */
function hsuforum_mark_parent_post_read($userid, $postid){
    global $DB;

    $config = get_config('hsuforum');

    $now = time();
    $cutoffdate = $now - ($config->oldpostdays * 24 * 3600);
    $parentid = $DB->get_record('hsuforum_posts', array('userid' => $userid, 'id' => $postid));

    if (!empty($config)) {
        try {
            if (!$DB->record_exists('hsuforum_read', array('userid' => $userid, 'postid' => $parentid->id))) {
                $sql = "INSERT INTO {hsuforum_read} (userid, postid, discussionid, forumid, firstread, lastread)

                SELECT ?, p.parent, p.discussion, d.forum, ?, ?
                  FROM {hsuforum_posts} p
                       JOIN {hsuforum_discussions} d ON d.id = p.discussion
                 WHERE p.id = ? AND p.modified >= ?";
                return $DB->execute($sql, array($userid, $now, $now, $parentid->id, $cutoffdate));

            } else {
                $sql = "UPDATE {hsuforum_read}
                   SET lastread = ?
                 WHERE userid = ? AND postid = ?";
                return $DB->execute($sql, array($now, $userid, $userid));
            }
        } catch (Exception $e) {
            error_log("Hsuforum hsuforum_mark_parent_post_read: " .$e->getMessage());
        }
    }

}

/**
 * Set the discussion to pinned and trigger the discussion pinned event
 *
 * @param  stdClass $modcontext module context object
 * @param  stdClass $forum      forum object
 * @param  stdClass $discussion discussion object
 * @since Moodle 3.1
 */
function hsuforum_discussion_pin($modcontext, $forum, $discussion) {
    global $DB;

    $DB->set_field('hsuforum_discussions', 'pinned', HSUFORUM_DISCUSSION_PINNED, array('id' => $discussion->id));

    $params = array(
        'context' => $modcontext,
        'objectid' => $discussion->id,
        'other' => array('forumid' => $forum->id)
    );

    $event = \mod_hsuforum\event\discussion_pinned::create($params);
    $event->add_record_snapshot('hsuforum_discussions', $discussion);
    $event->trigger();
}

/**
 * Set discussion to unpinned and trigger the discussion unpin event
 *
 * @param  stdClass $modcontext module context object
 * @param  stdClass $forum      forum object
 * @param  stdClass $discussion discussion object
 * @since Moodle 3.1
 */
function hsuforum_discussion_unpin($modcontext, $forum, $discussion) {
    global $DB;

    $DB->set_field('hsuforum_discussions', 'pinned', HSUFORUM_DISCUSSION_UNPINNED, array('id' => $discussion->id));

    $params = array(
        'context' => $modcontext,
        'objectid' => $discussion->id,
        'other' => array('forumid' => $forum->id)
    );

    $event = \mod_hsuforum\event\discussion_unpinned::create($params);
    $event->add_record_snapshot('hsuforum_discussions', $discussion);
    $event->trigger();
}

/**
 * Add nodes to myprofile page.
 *
 * @param \core_user\output\myprofile\tree $tree Tree object
 * @param stdClass $user user object
 * @param bool $iscurrentuser
 * @param stdClass $course Course object
 *
 * @return bool
 */
function mod_hsuforum_myprofile_navigation(core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    if (isguestuser($user)) {
        // The guest user cannot post, so it is not possible to view any posts.
        // May as well just bail aggressively here.
        return false;
    }
    $postsurl = new moodle_url('/mod/hsuforum/user.php', array('id' => $user->id));
    if (!empty($course)) {
        $postsurl->param('course', $course->id);
    }
    $string = get_string('myprofileotherpost', 'mod_hsuforum');
    $node = new core_user\output\myprofile\node('miscellaneous', 'hsuforumposts', $string, null, $postsurl);
    $tree->add_node($node);

    $discussionssurl = new moodle_url('/mod/hsuforum/user.php', array('id' => $user->id, 'mode' => 'discussions'));
    if (!empty($course)) {
        $discussionssurl->param('course', $course->id);
    }
    $string = get_string('myprofileotherdis', 'mod_hsuforum');
    $node = new core_user\output\myprofile\node('miscellaneous', 'hsuforumdiscussions', $string, null,
        $discussionssurl);
    $tree->add_node($node);

    return true;
}

/**
 * Function to check a post message for mentions and capture ids in an array.
 *
 * @param string $postmessage The post message
 * @return array The array of user id's mentioned in the post.
 */
function mention_id_array($postmessage="") {
    $id_array = array();
    if ($postmessage) {
        $string_array = explode('userid="', $postmessage);

        for ($x = 1; $x < count($string_array); $x++) {
            $string = $string_array[$x];
            $id = explode('">', $string)[0];
            array_push($id_array, $id);
        }
    }
    return $id_array;
}

/**
 * Function to delete all expired drafts based on the number of days set in the config
 * @return void
 */
function delete_expired_custom_drafts() {
    global $DB;

    $daystopersistdrafts = false;

    try {
        $daystopersistdrafts = get_config('hsuforum', 'daystopersistdrafts');
    } catch (\Exception $e) {
        error_log("Hsuforum delete_expired_custom_drafts: failed to read config value");
    }

    if (!$daystopersistdrafts) {
        error_log("Hsuforum delete_expired_custom_drafts: no setting found for days to persist drafts");
        return;
    }

    $cutofftimestamp = strtotime("now -$daystopersistdrafts days");

    $sql = <<<SQL
        timemodified <= ?
    SQL;

    try {
        $DB->delete_records_select('hsuforum_custom_drafts', $sql, [$cutofftimestamp]);
    } catch (\Exception $e) {
        error_log("Hsuforum delete_expired_custom_drafts: {$e->getMessage()}");
    }
}
