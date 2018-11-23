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
    $regex = '/id=(\d+)/';

    if (preg_match_all($regex, $message, $matches)) {
        $users = $matches[1];
    }

    return $users;
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
                    $string .= "You";
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