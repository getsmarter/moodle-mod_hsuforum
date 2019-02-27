<?php
// Library used for mobile funcitons

/**
 * @package mod_hsuforum
 * @author JJ Swanevelder
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Function to check a message for potential tagged users
 * @param string $message Message containing href links for users
 * @return array Return user id array
 */
function gettaggedusers($message) {
    $users = false;
    $regex = '/user\/view.php\?id=(\d+)/';

    if (preg_match_all($regex, $message, $matches)) {
        $users = $matches[1];
    }

    return $users;
}

/**
 * Function to replace all tagged user links and add the correct domain name
 * @param string $message Message containing href links for users
 * @return string 
 */
function fixpostbodywithtaggedlinks($message) {
    global $CFG;
    $returnmessage = "";

    $regex = '/mobileappuser\/view.php\?id=/';
    $replacementlink = $CFG->wwwroot . '/user/view.php?id=';
    if (preg_replace($regex, $replacementlink, $message)) {
        $returnmessage = preg_replace($regex, $replacementlink, $message);
    }
    return $returnmessage;
}

/**
 * Build formatted likes object with author fullname for each like.
 * @param stdClass $post object
 *
 * @return stdClass $likes object
 */
function getpostlikes($post) {
    global $DB, $USER;
    $likes = false;

    try {
        $result = $DB->get_records_sql('SELECT * FROM {hsuforum_actions} WHERE postid = ?', array($post->id));
        //  Get names for the likes
        if (count($result)) {
            $likes = $result;
            foreach ($likes as &$like) {
                $user = $DB->get_record('user', array('id' => $like->userid));
                if ($user->id == $USER->id) {
                    $like->likedfullname = "You";
                } else {
                    $like->likedfullname = $user->firstname ." ". $user->lastname;
                }
            }
        }
    } catch (Exception $e) {
        print_r($e->getMessage);
    }

    return $likes;
}

/**
 * Build formatted likes description for the bottom off mobile cards.
 * @param stdClass $likes object
 * 
 * @return string $string formmatted description string
 */
function getlikedescription($likes) {
    global $USER;
    $string = false;

    switch(true) {
        case count($likes) == 1:
            if ($likes[0]->userid == $USER->id) {
                $string = "You like this";
            } else {
                $string = $likes[0]->likedfullname . " like this";
            }
            break;
        case count($likes) == 2:
            foreach ($likes as $key => $like) {
                if ($like->userid == $USER->id) {
                    $string .= "You and ";
                } else if ($key == 1) {
                    $string .= $like->likedfullname;
                } else {
                    $string .= $like->likedfullname . " and ";
                }
            }
            $string .= " like this";
            break;
        case count($likes) > 2:
            $string .= count($likes) . " people like this";
            break;
    }

    return $string;
}

/**
 * Check to see if an user liked a post
 * @param int $postid
 * @param int $userid
 * 
 * @return bool
 */
function userlikedpost($postid, $userid) {
    global $DB;
    $liked = false;

    try {
        $result = $DB->get_records_sql('SELECT * FROM {hsuforum_actions} WHERE postid = ? AND userid = ?', array( $postid, $userid));
        $liked = count($result) ? true : false;
    } catch (Exception $e) {
        print_r($e->getMessage);
    }

    return $liked;
}

/**
 * Function to build readable assoc array of allowed tag users. Function rebuild from /mention_users/amd/src/mention_users.js
 * @param array $userarray
 * @return array
 */
function build_allowed_tag_users($userarray) {
    $returnarray = [];

    for ($i = 0; $i < count($userarray); $i += 2) {
        array_push($returnarray, [
            'name' => $userarray[$i],
            'id' => $userarray[$i + 1]
        ]);
    }

    return $returnarray;
}

/**
 * Function to return allowed tag users per group permission. Function rebuild from /mention_users/getusers.php
 * @param array $userarray @todo add everything here
 * @return array
 */
function get_allowed_tag_users($forum_id=0, $group_id=0, $advancedforum=0, $reply_id=0, $grouping_id='', $action='tribute') {
    global $CFG, $DB;

    $result = new stdClass();
    $result->result = false; // set in case uncaught error happens
    $result->content = 'Unknown error';

    if($action == 'tribute') {

        if ($reply_id != 0 && $forum_id == 0) {
            if ($advancedforum == 0) {
                $forum_discussions_id = $DB->get_field('forum_posts', 'discussion', array("id"=>$reply_id));
                $course_id = $DB->get_field('forum_discussions', "course", array("id"=>$forum_discussions_id));
                $forum_id = $DB->get_field('forum_discussions', "forum", array("id"=>$forum_discussions_id));
                $group_id = $DB->get_field('forum_discussions', "groupid", array("id"=>$forum_discussions_id));
            } elseif ($advancedforum == 1) {
                $forum_discussions_id = $DB->get_field('hsuforum_posts', 'discussion', array("id"=>$reply_id));
                $course_id = $DB->get_field('hsuforum_discussions', "course", array("id"=>$forum_discussions_id));
                $forum_id = $DB->get_field('hsuforum_discussions', "forum", array("id"=>$forum_discussions_id));
                $group_id = $DB->get_field('hsuforum_discussions', "groupid", array("id"=>$forum_discussions_id));
            }
        } elseif ($forum_id != 0 && $reply_id == 0) {
            if ($advancedforum == 0) {
                $course_id = $DB->get_field('forum', "course", array("id"=>$forum_id));
            } elseif ($advancedforum == 1) {
                $course_id = $DB->get_field('hsuforum', "course", array("id"=>$forum_id));
            }
        }

        if ($advancedforum == 0) {
            $moduleid = $DB->get_field('modules', 'id', array("name"=>'forum'));
        } elseif ($advancedforum == 1) {
            $moduleid = $DB->get_field('modules', 'id', array("name"=>'hsuforum'));
        }

        $availability = $DB->get_field('course_modules', "availability", array("course"=>$course_id, "instance"=>$forum_id, 'module'=>$moduleid));

        if ($availability) {
            $restrictions = json_decode($availability)->c;

            if (isset($restrictions)) {
                foreach ($restrictions as $restriction) {
                    if ($restriction->type == 'group') {
                        $group_id = $restriction->id;
                    } elseif ($restriction->type == 'grouping') {
                        $grouping_id = $restriction->id;
                    }
                }
            }
        }

        $context_id = $DB->get_field(
            'context',
            'id',
            array(
                'instanceid' => $course_id,
                'contextlevel' => 50,
            )
        );

        $course_staff = $DB->get_records_sql(
            "SELECT DISTINCT
                ue.userid,
                e.courseid,
                u.firstname,
                u.lastname,
                u.username,
                r.shortname
            FROM {user_enrolments} ue
            JOIN {enrol} e ON (e.id = ue.enrolid)
            JOIN {user} u ON (ue.userid = u.id)
            JOIN {role_assignments} ra ON (u.id = ra.userid AND ra.contextid = ?)
            JOIN {role} r ON (ra.roleid = r.id)
            WHERE e.courseid = ?
            AND r.shortname IN ('coursecoach', 'headtutor', 'tutor', 'support')
            ORDER BY firstname",
            array($context_id, $course_id)
        );

        if ($group_id <= 0 && $grouping_id == 0) {
            $sql = "
                SELECT DISTINCT
                    ue.userid,
                    e.courseid,
                    u.firstname,
                    u.lastname,
                    u.username,
                    r.shortname
                FROM {user_enrolments} ue
                JOIN {enrol} e ON (e.id = ue.enrolid)
                JOIN {user} u ON (ue.userid = u.id)
                JOIN {role_assignments} ra ON (u.id = ra.userid AND ra.contextid = ?)
                JOIN {role} r ON (ra.roleid = r.id)
                WHERE e.courseid = ?
                AND r.shortname = 'student'
                ORDER BY firstname
                ;";

            $users = $DB->get_records_sql($sql, array($context_id, $course_id));
        } elseif ($group_id > 0 && $grouping_id == 0) {
            $sql = "
                SELECT DISTINCT
                    ue.userid,
                    e.courseid,
                    u.firstname,
                    u.lastname,
                    u.username,
                    r.shortname
                FROM {user_enrolments} ue
                JOIN {enrol} e ON (e.id = ue.enrolid)
                JOIN {user} u ON (ue.userid = u.id)
                JOIN {role_assignments} ra ON (u.id = ra.userid AND ra.contextid = ?)
                JOIN {role} r ON (ra.roleid = r.id)
                JOIN {groups} g ON (g.courseid = e.courseid)
                JOIN {groups_members} gm ON (ue.userid = gm.userid ) AND (gm.groupid = g.id)
                WHERE e.courseid = ?
                AND g.id = ?
                AND r.shortname = 'student'
                ORDER BY firstname
                ;";

            $users = $DB->get_records_sql($sql, array($context_id, $course_id, $group_id));
        } elseif ($grouping_id != 0 && $group_id >= 0) {
            // users should only be able to mention users in their group
            $sql = "
                SELECT DISTINCT
                    ue.userid,
                    e.courseid,
                    u.firstname,
                    u.lastname,
                    u.username,
                    r.shortname
                FROM {user_enrolments} ue
                JOIN {enrol} e ON (e.id = ue.enrolid)
                JOIN {user} u ON (ue.userid = u.id)
                JOIN {role_assignments} ra ON (u.id = ra.userid AND ra.contextid = ?)
                JOIN {role} r ON (ra.roleid = r.id)
                JOIN {groups_members} gm ON (u.id = gm.userid)
                JOIN {groupings_groups} gg ON (gm.groupid = gg.groupid)
                WHERE e.courseid = ?
                AND gg.groupingid = ?
                AND gm.groupid = ?
                AND r.shortname = 'student'
                ORDER BY firstname
                ;";

            $users = $DB->get_records_sql($sql, array($context_id, $course_id, $grouping_id, $group_id));
        } elseif ($grouping_id != 0 && $group_id <= 0) {
            $sql = "
                SELECT DISTINCT
                    ue.userid,
                    e.courseid,
                    u.firstname,
                    u.lastname,
                    u.username,
                    r.shortname
                FROM {user_enrolments} ue
                JOIN {enrol} e ON (e.id = ue.enrolid)
                JOIN {user} u ON (ue.userid = u.id)
                JOIN {role_assignments} ra ON (u.id = ra.userid AND ra.contextid = ?)
                JOIN {role} r ON (ra.roleid = r.id)
                JOIN {groups_members} gm ON (u.id = gm.userid)
                JOIN {groupings_groups} gg ON (gm.groupid = gg.groupid)
                WHERE e.courseid = ?
                AND gg.groupingid = ?
                AND r.shortname = 'student'
                ORDER BY firstname
                ;";

            $users = $DB->get_records_sql($sql, array($context_id, $course_id, $grouping_id));
        }

        $users = array_merge($users, $course_staff);

        if (isset($users)) {
            $data = array();
            foreach ($users as $user) {
                array_push($data, $user->firstname . ' ' . $user->lastname, $user->userid);
            }

            $post = $data;

            $result->result = true;
            $result->courseid = $course_id;
            $result->content = $post;
        }
    }
    else {
        $result->result = false;
        $result->content = 'Invalid action';
    }

    return $result;
    }

/**
 * Function to return the stat counts for the discusssion card footer element
 * @param object $discussion the discussion
 * @param int $forumid the forum id
 * 
 * @return array the stats for the footer
 */
function get_discussion_footer_stats($discussion, $forumid){
    global $DB;
    $stats = [];

    // Created
    $stats['createdfiltered'] = get_string('posttimeago', 'hsuforum', hsuforum_relative_time($discussion->created));

    // Latest post
    $latestpost = '';
    if (!empty($discussion->modified) && !empty($discussion->replies)) {
        $latestpost = hsuforum_relative_time($discussion->timemodified);
    }
    $stats['latestpost'] = $latestpost;

    // Getting the contirbutors
    $contribsql = "select count(distinct(userid)) as contributes from {hsuforum_posts} where discussion = ?";
    $contribparams = array('discussion' => $discussion->discussion);

    if ($c = $DB->get_record_sql($contribsql, $contribparams)) {
        $stats['contribs'] = $c->contributes;
    } else {
        $stats['contribs'] = 0;
    }

    // Getting the views
    $viewssql = "select count(userid) as views from {hsuforum_read} where forumid = ? and discussionid = ?";
    $viewsparams = array('forumid' => $forumid, 'discussionid' => $discussion->discussion);
    if ($v = $DB->get_record_sql($viewssql, $viewsparams)) {
        $stats['views'] = $v->views;
    } else {
        $stats['views'] = 0;
    }

    return $stats;
}

/**
 * Function to return the stat counts for the discusssion banner element
 * Reason for this one vs footer is that the $discussion object in the footer function has additional props 
 * that moodle bundled in the query. The $discussion object in the banner one does not have this and we need to 
 * build another stat array for this view.
 * @param object $discussion the discussion
 * @param object $firstpost the firstpost for the discussion
 * @param int $forumid the forum id
 * 
 * @return array the stats for the banner
 */
function get_discussion_banner_stats($discussion, $firstpost, $forumid){
    global $DB;
    $stats = [];

    // Created
    $stats['createdfiltered'] = get_string('posttimeago', 'hsuforum', hsuforum_relative_time($firstpost->created));

    // Latest post
    $stats['latestpost'] = hsuforum_relative_time($discussion->timemodified);

    // Replies
    $stats['replies'] = hsuforum_count_replies($firstpost, $children=true);

    // Getting the contirbutors
    $contribsql = "select count(distinct(userid)) as contributes from {hsuforum_posts} where discussion = ?";
    $contribparams = array('discussion' => $discussion->id);

    if ($c = $DB->get_record_sql($contribsql, $contribparams)) {
        $stats['contribs'] = $c->contributes;
    } else {
        $stats['contribs'] = 0;
    }

    // Getting the views
    $viewssql = "select count(userid) as views from {hsuforum_read} where forumid = ? and discussionid = ?";
    $viewsparams = array('forumid' => $forumid, 'discussionid' => $discussion->id);
    if ($v = $DB->get_record_sql($viewssql, $viewsparams)) {
        $stats['views'] = $v->views;
    } else {
        $stats['views'] = 0;
    }

    return $stats;
}

/**
 * Check for discussion subscription
 * @param int $discussionid the discussion id
 * @param int $userid the user id
 *
 * @return bool
 */
function user_subscribed($discussionid, $userid) {
    global $DB;
    $subscribed = false;

    try {
        $result = $DB->get_records_sql('SELECT * FROM {hsuforum_subscriptions_disc} WHERE discussion = ? AND userid = ?', array($discussionid, $userid));
        $subscribed = count($result) ? true : false;
    } catch (Exception $e) {
        print_r($e->getMessage);
    }

    return $subscribed;
}

/**
 * Get post reply count in a dicussion
 * @param int $discussionid the discussion id
 * @param int $postid the post id
 *
 * @return int
 */
function get_post_replies($discussionid, $postid) {
    global $DB;
    $replies = 0;

    $repliessql = "select count(parent) as replies from {hsuforum_posts} where discussion = ? and parent = ?";
    $repliesparams = array('discussion' => $discussionid, 'post' => $postid);

    if ($v = $DB->get_record_sql($repliessql, $repliesparams)) {
        $replies = $v->replies;
    }

    return $replies;
}

/**
 * Get all unread post ids for a discussion
 * @param int $discussionid the discussion id
 * @param int $user the user id
 *
 * @return array the unread post ids
 */
function hsuforum_get_unread_discussionids($discussionid, $userid) {
    global $DB;
    $readids = [];

    $readssql = "select id from {hsuforum_read} where discussionid = ? and userid = ?";
    $readsparams = array('discussion' => $discussionid, 'user' => $userid);
    $result = $DB->get_records_sql($readssql, $readsparams);

    if (count($result)) {
        $readids = array_values(array_map(function($r) { return $r->id; }, $result));
    }

    return $readids;
}

/**
 * Get all nested unread post ids for a parent post
 * @param int $discussionid the discussion id
 * @param int $postid the parent post id
 * @param int $user the user id
 *
 * @return array the unread post ids
 */
function hsuforum_get_unread_nested_postids($discussionid, $postid, $userid) {
    global $DB;
    $readids = [];

    $sql = "SELECT r.postid 
            FROM {hsuforum_read} r 
            JOIN {hsuforum_posts} p ON p.id = r.postid 
            WHERE p.parent = ? 
            AND r.discussionid = ? 
            AND r.userid = ? ";
    $params = array('parent' => $postid, 'discussion' => $discussionid, 'user' => $userid);
    $result = $DB->get_records_sql($sql, $params);

    if (count($result)) {
        $readids = array_values(array_map(function($r) { return $r->postid; }, $result));
    }

    return $readids;
}

/**
 * Get all user roles and assignment ids in course
 * @param int $courseid the course id
 *
 * @return array all roles and assignment ids in course
 */
function hsuforum_get_course_roles_and_assignments($courseid) {
    global $DB;
    $coursecontext = $DB->get_record('context', array('instanceid' => $courseid, 'contextlevel' => '50'));

    // Creating basic data struct for assignments
    $roles              = [];
    $roles['htutor']    = [];
    $roles['tutor']     = [];
    $roles['smanager']  = [];
    $roles['gsmanager'] = [];
    $roles['student']   = [];

    try {
        $rolehtutor  = $DB->get_record('role', array('shortname' => 'headtutor'));
        $roletutor   = $DB->get_record('role', array('shortname' => 'tutor'));
        $smanager    = $DB->get_record('role', array('shortname' => 'coursecoach'));
        $gsmanager   = $DB->get_record('role', array('shortname' => 'support'));
        $student     = $DB->get_record('role', array('shortname' => 'student'));

        $roles['htutor']    = hsuforum_get_course_role_assignment_ids($coursecontext->id, $rolehtutor->id);
        $roles['tutor']     = hsuforum_get_course_role_assignment_ids($coursecontext->id, $roletutor->id);
        $roles['smanager']  = hsuforum_get_course_role_assignment_ids($coursecontext->id, $smanager->id);
        $roles['gsmanager'] = hsuforum_get_course_role_assignment_ids($coursecontext->id, $gsmanager->id);
        $roles['student']   = hsuforum_get_course_role_assignment_ids($coursecontext->id, $student->id);
    } catch (Exception $e) {
        print_r($e->getMessage);
    }

    return $roles;
}

/**
 * Get role assignment ids in a course
 * @param int $coursecontextid the course context id
 * @param int $roleid the role id
 *
 * @return array role assignment ids
 */
function hsuforum_get_course_role_assignment_ids($coursecontextid, $roleid) {
    global $DB;
    $roleids = [];
    try {
        $roleidssql = "select userid from {role_assignments} where contextid = ? and roleid = ?";
        $roleidsparams = array('context' => $coursecontextid, 'role' => $roleid);
        $result = $DB->get_records_sql($roleidssql, $roleidsparams);

        if (count($result)) {
            $roleids = array_values(array_map(function($r) { return $r->userid; }, $result));
        }
    } catch (Exception $e) {
        print_r($e->getMessage);
    }

    return $roleids;
}

/**
 * Function to get an orginised post/child structured array used in mobile.
 * @param int $discussionid the discussion id
 * @param string $order the SQL order by
 *
 * @return array the filtered array:
 */
function hsuforum_get_discussion_post_hierarchy($discussionid, $order = "ASC") {
    global $DB, $USER;

    $postssql        = "SELECT p.id, p.parent, p.created 
                            FROM {hsuforum_posts} p 
                            WHERE discussion = ? 
                                AND (p.privatereply = 0 OR p.privatereply = ? OR p.userid = ?)
                            ORDER BY p.created " . $order;
    $postsparams     = array('discussion' => $discussionid, 'privatereply' => $USER->id, 'user' => $USER->id);
    $discussionposts = $DB->get_records_sql($postssql, $postsparams);
    $filteredposts   = [];

    foreach ($discussionposts as $key => $post) {
        // Firstpost
        if (!$post->parent) {
            $filteredposts[$post->id] = [];
        // Firstlevel posts
        } elseif ($post->parent == $discussionid) {
            $filteredposts[$discussionid][$post->id] = [];
        // Secondlevel posts
        } elseif (isset($filteredposts[$discussionid][$post->parent])) {
            $filteredposts[$discussionid][$post->parent]['secondlevelposts'][$post->id] = [
                'id'      => $post->id,
                'parent'  => $post->parent,
                'depth'   => 2,
                'created' => $post->created
            ];
        // Children on firstreply that will be grouped together
        } else {
            $firstlevelpost = hsuforum_get_firstlevel_post($post->id, $discussionposts);
            if ($firstreplyparent = hsuforum_get_secondlevel_post($post->id, $discussionposts, $filteredposts[$discussionid][$firstlevelpost->id]['secondlevelposts'])) {
                    $filteredposts[$discussionid][$firstlevelpost->id]['secondlevelposts'][$firstreplyparent->id]['children'][$post->id] = [
                    'id'      => $post->id,
                    'parent'  => $post->parent,
                    'depth'   => $firstlevelpost->depth,
                    'created' => $post->created
                ];
            }
        }
    }

    return $filteredposts;
}

/**
 * Function find the first level posts on the firstpost
 * @param int $postid the post id
 * @param array $unfilteredposts the posts for a discussion
 *
 * @return object the root parent:
 */
function hsuforum_get_firstlevel_post($postid, $unfilteredposts = array()) {
    $firstlevelpost = false;
    $depth = 0;

    // Check if post id in arr.
    if (isset($unfilteredposts[$postid])) {
        $parentid = (int) $unfilteredposts[$postid]->parent;
        $postid = -1;
        while ($parentid !== 0) {
            foreach ($unfilteredposts as $key => $value) {
                // Found a parent for current search
                if ($key == $parentid) {
                    $parent = $unfilteredposts[$unfilteredposts[$parentid]->parent];
                    // Exit search if parent parent is 0
                    if ((int) $parent->parent == 0) {
                        $parentid = 0;
                        $depth++;
                        // Setting return values
                        $firstlevelpost = new stdClass();
                        $firstlevelpost->id = $key;
                        $firstlevelpost->depth = $depth;
                    // Else continue search
                    } else {
                        $parentid = (int) $unfilteredposts[$key]->parent;
                        $postid = (int) $unfilteredposts[$key];
                        $depth++;
                    }
                }
            }
        }
    }

    return $firstlevelpost;
}

/**
 * Function find the second level post parent for a post deeper than second level.
 * @param int $postid the post id
 * @param array $unfilteredposts the posts for a discussion
 * @param array $firstreplies the firstlevel posts on a firstpost.
 *
 * @return object the root parent:
 */
function hsuforum_get_secondlevel_post($postid, $unfilteredposts = array(), $firstreplies = array()) {
    $secondlevelpost = false;
    $depth = 0;

    // Check if post id in arr.
    if (isset($unfilteredposts[$postid]) && count($firstreplies)) {
        $parentid = (int) $unfilteredposts[$postid]->parent;
        $postid = -1;
        while ($parentid !== 0) {
            foreach ($unfilteredposts as $pid => $post) {
                // Found a parent for current search
                if ($pid == $parentid) {
                    $parent = $unfilteredposts[$unfilteredposts[$parentid]->parent];
                    // Exit search if parent parent is 0
                    if (array_key_exists($pid, $firstreplies)) {
                        $parentid = 0;
                        $depth++;
                        // Setting return values
                        $secondlevelpost = new stdClass();
                        $secondlevelpost->id = $pid;
                        $secondlevelpost->depth = $depth;
                    // Else continue search
                    } else {
                        $parentid = (int) $unfilteredposts[$pid]->parent;
                        $postid = (int) $unfilteredposts[$pid];
                        $depth++;
                    }
                }
            }
        }
    }

    return $secondlevelpost;
}
