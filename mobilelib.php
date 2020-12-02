<?php
// Library used for mobile funcitons

/**
 * @package mod_hsuforum
 * @author JJ Swanevelder
 */

defined('MOODLE_INTERNAL') || die();
define('SORT_REPLIES_MOST_TO_LEAST', 2);
define('SORT_LIKES_MOST_TO_LEAST', 3);
define('SORT_OLDEST', 4);
define('SORT_NEWEST', 5);
define('MOBILE_WEBSERVICE_USER_TOKEN', hsuforum_mobile_get_user_token());

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
                if ($like->userid == $USER->id && $key == 1) {
                    $string .= "You";
                } else if($like->userid == $USER->id) {
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
 * @param context_course $context
 * @param $user
 * @return bool
 */
function check_capability($context, $user)
{
    global $result, $USER;

    try {
        return has_capability('local/getsmarter:mention_' . $user->shortname, $context, $USER->id);
    } catch (Exception $e) {
        error_log($e);
        $result->result = false;
        $result->content = $e;

        header('Content-type: application/json');
        echo json_encode($result);
    }
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

        $context = \context_course::instance($course_id);
        $users = array_merge($users, $course_staff);
        $allUserIds = "";

        if (!empty($users)) {

            foreach($users AS $user) {
                if(check_capability($context, $user)) {
                    $allUserIds .= $user->userid . ",";
                }
            }

            $allUserIds = rtrim($allUserIds, ",");
        }

        if (isset($users)) {
            $data = array();

            if(!empty($allUserIds) && has_capability('local/getsmarter:mention_all', $context)) {
                array_push($data, '@all', $allUserIds);
            }

            foreach ($users as $user) {
                if (has_capability('local/getsmarter:mention_' . $user->shortname, $context)) {
                    array_push($data, $user->firstname . ' ' . $user->lastname, $user->userid);
                }
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
 * Internal method to walk over a list of posts, rendering
 * each post and their children.
 *
 * @param array $posts
 * @param int $parentid The current parent
 * @param int $depth The post depth level
 * @param array $post_map The map array
 * @return array
 */
function hsuforum_mobile_post_walker($posts, $parentid, $depth = 0, $post_map=array()) {
    foreach ($posts as $post) {
        if ($post->parent != $parentid) {
            continue;
        }

        $post_map[$post->id] = array('id' => $post->id,'depth' => $depth);

        if (!empty($post->children)) {
            $post_map = hsuforum_mobile_post_walker($post->children, $post->id, ($depth + 1), $post_map);
        }
    }
    return $post_map;
}

/**
 * Function to get discussion posts by field
 * @param int $discussionid The discussion id
 * @param string $fields The selected fields to return
 * @param bool Option to get nested children for post
 * @param string $order The SQL order by
 *
 * @return array The discussion posts
 */
function hsuforum_mobile_get_all_discussion_posts_by_field($discussionid, $fields="*", $children=false, $order = "ASC") {
    global $DB, $USER;

    $postssql = "SELECT " . $fields . "
                    FROM {hsuforum_posts} p 
                    WHERE discussion = ? 
                        AND (p.privatereply = 0 OR p.privatereply = ? OR p.userid = ?)
                    ORDER BY p.created " . $order;

    $postsparams = array(
                    'discussion' => $discussionid, 
                    'privatereply' => $USER->id, 
                    'user' => $USER->id
                    );

    $posts = $DB->get_records_sql($postssql, $postsparams);

    if ($children) {
        foreach ($posts as $pid=>$p) {
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
    }

    return $posts;
}

/**
 * Function to determine the margin level for a post card depending on depth level
 * @param int $depthlevel The post depth level
 *
 * @return string The margin style
 */
function hsuforum_mobile_get_style_margin(int $depthlevel) {
    $margin = 'auto';
    switch (true) {
        case $depthlevel == 2:
            $margin = '6%';
            break;
        case $depthlevel == 3:
            $margin = '8%';
            break;
        case $depthlevel == 4:
            $margin = '10%';
            break;
        case $depthlevel == 5:
            $margin = '12%';
            break;
        case $depthlevel > 5:
            $margin = '14%';
            break;
        default:
            $margin = 'auto';
            break;
    }

    return $margin;
}

/**
 * Function to filter/sort discussion rootposts. The function will iterate through the discussion
 * posts and automatically add the filtered posts to its respective rootpost for the discussion in
 * array 'filteredposts'. The function will also work out the amount of likes for a rootpost.
 * @param array $discussionrootposts The discussion root posts
 * @param int $discussionid The discussion id
 * @param int $firstpostid The firstpost for the discussion
 * @param int $filter The filter option
 * @param int $filter The sort option
 * @param object $course The course related to the discussion
 *
 * @return array The filtered/sorted posts
 */
function hsuforum_mobile_filter_sort_posts($discussionrootposts, $discussionid, $firstpostid, $filter, $sort, $course) {
    global $DB, $USER, $PAGE;

    try {
        $returnposts = [];
        $discussionposts = hsuforum_mobile_get_all_discussion_posts_by_field($discussionid, 'p.id,p.parent, p.userid');
        $filteredposts = $PAGE->get_renderer('mod_hsuforum')->filter_sort_posts($discussionposts, $filter, $sort, $course, true, false);

        foreach ($filteredposts as $postid => $post) {
            if ($postid) {
                if (in_array($post->parent, array_keys($discussionrootposts))) {
                    hsuforum_mobile_add_filteredpost_to_posts_array($discussionrootposts, $post->parent, $postid);
                } else {
                    // Run up the chain to see if the filtered post is in one of the discussionrootposts
                    if ($rootpost = hsuforum_get_firstlevel_post($postid, $discussionposts)) {
                        hsuforum_mobile_add_filteredpost_to_posts_array($discussionrootposts, $rootpost->id, $postid);
                    }
                }
            }
        }

        // With above algorithm we will also have an array 'filteredposts' for the firstpost which is the filtered rootposts
        if (isset($discussionrootposts[$firstpostid]->filteredposts) && count($discussionrootposts[$firstpostid]->filteredposts)) {
            foreach ($discussionrootposts[$firstpostid]->filteredposts as $filteredrootpost) {
                array_push($returnposts, $discussionrootposts[$filteredrootpost]);
            }
        }

        // Sort the posts
        $sortedposts = hsuforum_mobile_sort_posts($returnposts, $sort);
        return $sortedposts;

    } catch (\Exception $e) {
        print($e->getMessage());
        // Last failsafe if something goes wrong
        return $discussionrootposts;
    }
}

/**
 * Function to handle adding filtered post results to rootposts and like counts
 * @param array $posts The rootposts for the discussion
 * @param int $rootpostid The rootpost id
 * @param int $postid The filtered post id
 *
 * @return array The rootposts
 */
function hsuforum_mobile_add_filteredpost_to_posts_array(&$rootposts, $rootpostid, $postid) {
    global $DB;
    $isrootpost = false;
    $isrootpost = isset($rootposts[$postid]) ? 1 : 0;
    $postlikes = sizeof($DB->get_records('hsuforum_actions', array('postid' => $postid, 'action' => 'like')));

    // Adding post id to filteredposts array to highlight posts
    if (isset($rootposts[$rootpostid]->filteredposts)) {
        array_push($rootposts[$rootpostid]->filteredposts, $postid);
    } else {
        $rootposts[$rootpostid]->filteredposts = [];
        array_push($rootposts[$rootpostid]->filteredposts, $postid);
    }

    // Add likes for sorting options
    if (count($postlikes) > 0) {
        if ($isrootpost) {
            $rootposts[$postid]->rootpostlikes = $postlikes;
        } else {
            $rootposts[$rootpostid]->nestedreplylikes += $postlikes;
        }        
    }

    return $rootposts;
}

/**
 * Function to sort posts
 * @param array $posts The posts to be sorted
 * @param int $sort The sort option
 *
 * @return array The sorted posts
 */
function hsuforum_mobile_sort_posts($posts, $sort) {
    if ($posts) {
        switch ($sort) {
            case SORT_OLDEST:
                uasort($posts, function ($a, $b) {
                    return $a->created <=> $b->created;
                });
                break;
            
            case SORT_NEWEST:
                uasort($posts, function ($a, $b) {
                    return $b->created <=> $a->created;
                });
                break;
            
            case SORT_LIKES_MOST_TO_LEAST:
                uasort($posts, function ($a, $b) {
                    $a->rootpostlikes = !$a->rootpostlikes ? 0 : $a->rootpostlikes;
                    $b->rootpostlikes = !$b->rootpostlikes ? 0 : $b->rootpostlikes;

                    return $b->rootpostlikes <=> $a->rootpostlikes;
                });
                break;

            case SORT_REPLIES_MOST_TO_LEAST:
                uasort($posts, function ($a, $b) {
                    $a->filteredposts = !$a->filteredposts ? 0 : $a->filteredposts;
                    $b->filteredposts = !$b->filteredposts ? 0 : $b->filteredposts;

                    return $b->filteredposts <=> $a->filteredposts;
                });
                break;

            default:
                // Sort oldest as an default
                uasort($posts, function ($a, $b) {
                    return $a->created <=> $b->created;
                });
                break;
        }
    }

    return $posts;
}

/**
 * Function find the first level posts on the firstpost
 * @param int $postid the post id
 * @param array $unfilteredposts the posts for a discussion
 *
 * @return object the firstlevel post
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
 * Function to generate a token for the mobile webservice.
 * Note this is bound to the constant MOBILE_WEBSERVICE_USER_TOKEN so that it can be used anywhere
 * 
 * @return string the token
 */
function hsuforum_mobile_get_user_token() : string {
    global $DB;

    $service = $DB->get_record('external_services', array('shortname' => 'moodle_mobile_app', 'enabled' => 1));
    $tokenarr = external_generate_token_for_current_user($service);

    return $tokenarr->token;
}

/**
 * Function to get the url for a profile picture
 * @param object $postuser the postuser
 *
 * @return string the profile pic url
 */
function hsuforum_mobile_get_user_profilepic_url($postuser) : string {
    global $PAGE, $CFG;

    $postuser->user_picture->size = 100;
    $imagepath = parse_url($postuser->user_picture->get_url($PAGE)->out())['path'];

    if (strpos($imagepath, 'pluginfile.php')) {
        $imageurl = $CFG->wwwroot.'/webservice'.$imagepath.'?token='.MOBILE_WEBSERVICE_USER_TOKEN;
    } else {
        $imageurl = $postuser->user_picture->get_url($PAGE)->out();
    }

    return $imageurl;
}

    /**
     * @param $message
     * @param $modulecontextid
     * @param $postid
     * returns the message with
     * the correctly embedded images
     * looks for each image, and builds the correct url
     * to grab the image via webservices rest API
     */
function returnEmbeddedImageMessage($message, $modulecontextid, $postid) {
    global $CFG;

    $baseuri = $CFG->wwwroot . '/webservice/pluginfile.php/' . $modulecontextid . '/mod_hsuforum/post/' . $postid;
    // https://gist.github.com/vyspiansky/11285153.
    preg_match_all( '@src="([^"]+)"@' , $message, $explodedmessage );
    $goodbadimages = [];

    foreach($explodedmessage as $images) {
        if(sizeof($images) === 1) { // Handling post messages with a single image, example of data below.
            // [0]=> string(64) "src="@@PLUGINFILE@@/Screenshot%202019-07-12%20at%2011.12.43.png"".
            if (strpos($images[0], 'src=') !== false) {
                $output = null;
                preg_match('~src="(.*?)"~', $images[0], $output);
                $gooduri = str_replace('@@PLUGINFILE@@', $baseuri, $output[1]) . '?token='.MOBILE_WEBSERVICE_USER_TOKEN;
                $goodbadimages[] = array('bad_uri' => $output[0], 'good_uri' => $gooduri);
            }
        } else { // Handling post messages with a single image, example of data below.
            // array( [0]=> string(64) "src="@@PLUGINFILE@@/Screenshot%202019-07-12%20at%2011.12.43.png"".
            // [1]=> string(64) "src="@@PLUGINFILE@@/Screenshot%202019-07-12%20at%2011.12.43.png"" );.
            foreach($images as $image) {
                if (strpos($image, 'src=') !== false) {
                    $output = null;
                    preg_match('~src="(.*?)"~', $image, $output);
                    $gooduri = str_replace('@@PLUGINFILE@@', $baseuri, $output[1]) . '?token='.MOBILE_WEBSERVICE_USER_TOKEN;
                    $goodbadimages[] = array('bad_uri' => $output[0], 'good_uri' => $gooduri);
                }
            }
        }
    }

    // Replacing the bad URLs with the good ones.
    foreach($goodbadimages as $replacementimage) {
        $message = str_replace($replacementimage['bad_uri'], 'src="' . $replacementimage['good_uri'] . '""', $message);
    }

    return $message;
}

/**
 * Check what groups the user is allowed to post to.
 * @param object $cm the course module
 * @param object $forum the forum
 * @param object $modcontext the module context
 * @param array $activitygroups the available activity groups
 * @return array $allowedgroups the groups you are allowed to view/post to
 */
function mobile_hsu_get_allowed_user_post_groups($cm, $forum, $modcontext, $activitygroups) {
        $allowedgroups = [];

        foreach ($activitygroups as $groupid => $group) {
            if (hsuforum_user_can_post_discussion($forum, $groupid, -1, $cm, $modcontext)) {
                $allowedgroups[] = $group;
            }
        }

        return array_values($allowedgroups);
}
