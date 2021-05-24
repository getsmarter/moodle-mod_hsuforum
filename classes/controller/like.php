<?php

namespace mod_hsuforum\controller;

use mod_hsuforum\controller\action;

defined('MOODLE_INTERNAL') || die();

class like implements action {

    CONST LIKE = 'like';
    CONST TABLE = '{hsuforum_posts}';

    public function __construct() {}

    /**
     * 
     **/ 
    public function get_action($discussionid) {

        global $DB, $CFG;

        require_once($CFG->dirroot.'/mod/hsuforum/hsuforum_actions_lib.php');

        $result = new \stdClass();
        $result->result = false; // set in case uncaught error happens
        $result->content = 'Unknown error';

        //Only allow to get actions if logged in
        if(isloggedin()) {

            //Get posts in discussion
            $sql = "
            SELECT
                p.id,
                p.discussion
            FROM
                " . self::TABLE . " p
            WHERE
                p.discussion = $discussionid
            ";
            $posts = $DB->get_records_sql($sql);

            hsuforum_populate_post_actions($posts);

            $result->result = true;
            $result->content = $posts;
        }
        else {
            $result->result = false;
            $result->content = 'Your session has timed out. Please login again.';
        }

        return $result;
    }

    /**
     * 
     **/ 
	public function set_action($postid) {
        global $DB, $CFG, $USER;

        require_once($CFG->dirroot.'/mod/hsuforum/hsuforum_actions_lib.php');

        $result = new stdClass();
        $result->result = false; // set in case uncaught error happens
        $result->content = 'Unknown error';

        //Only allow to add action if logged in
        if(isloggedin()) {

            /**
            * Checking if user has already liked the post
            */
            
            $sql = "SELECT COUNT(id) AS likescounter FROM mdl_hsuforum_actions WHERE postid = ? AND userid = ?";
            $sqlreturn = $DB->get_record_sql($sql,
                [
                    'postid' => $postid,
                    'userid' => $USER->id,
                ]
            );

            if(!empty($sqlreturn->likescounter)) {
                $result->content = get_string('toomanylikes','local_hsuforum_actions');
                return $result;
            }

            // Insert new action record
            $record = new stdClass();
            $record->postid = $p;
            $record->userid = $USER->id;
            $record->action = $action;
            $record->created = time();
            $actionid = $DB->insert_record('hsuforum_actions', $record, true);

            //Get post to return
            $sql = "
            SELECT
                p.id,
                p.discussion
            FROM
                " . self::TABLE . " p
            WHERE
                p.id = $p
            ";

            $post = $DB->get_records_sql($sql);

            hsuforum_populate_post_actions($post);

            $result->result = true;
            $result->content = $post;

        } else {
            $result->result = false;
            $result->content = 'Your session has timed out. Please login again.';
        }

        return $result;

	}

    /**
     * 
     **/ 
	public function delete_action($id) {
        global $DB, $CFG;

        require_once($CFG->dirroot.'/mod/hsuforum/hsuforum_actions_lib.php');

        $result = new stdClass();
        $result->result = false; // set in case uncaught error happens
        $result->content = 'Unknown error';

        //Only allow to remove action if logged in
        if(isloggedin()) {

            $deleted = $DB->delete_records('hsuforum_actions', array('postid' => $p, 'userid' => $USER->id, 'action' => $action));

            //Get post to return
            $sql = "
            SELECT
                p.id,
                p.discussion
            FROM
                {hsuforum_posts} p
            WHERE
                p.id = $p
            ";

            $post = $DB->get_records_sql($sql);

            hsuforum_populate_post_actions($post);

            $result->result = true;
            $result->content = $post;

        } else {
            $result->result = false;
            $result->content = 'Your session has timed out. Please login again.';
        }

        return $result;
	}
}