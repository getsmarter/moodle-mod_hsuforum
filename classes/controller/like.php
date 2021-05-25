<?php

namespace mod_hsuforum\controller;

use mod_hsuforum\controller\action;

defined('MOODLE_INTERNAL') || die();

class like implements action {

    CONST ACTION = 'like';
    CONST TABLE = '{hsuforum_posts}';
    CONST ACTIONS_TABLE = '{hsuforum_actions}';

    public function __construct() {}

    /**
     * function get_action 
     * returns all like data for specific discussion.
     * @param $discussionid
     * @return object
     **/ 
    public function get_action($discussionid) {

        global $DB, $CFG;

        require_once($CFG->dirroot.'/mod/hsuforum/hsuforum_actions_lib.php');

        $result = (object)[];
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
     * function set_action
     * Add action 'like' to database
     * @param $postid
     * @return object
     **/ 
	public function set_action($postid) {
        global $DB, $CFG, $USER;

        require_once($CFG->dirroot.'/mod/hsuforum/hsuforum_actions_lib.php');

        $result = (object)[];
        $result->result = false; // set in case uncaught error happens
        $result->content = 'Unknown error';

        //Only allow to add action if logged in
        if(isloggedin()) {

            /**
            * Checking if user has already liked the post
            */
            
            $sql = "SELECT COUNT(id) AS likescounter FROM " . self::ACTIONS_TABLE . " WHERE postid = ? AND userid = ?";
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
            $record = (object)[];
            $record->postid = $postid;
            $record->userid = $USER->id;
            $record->action = self::ACTION;
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
                p.id = $postid
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
     * function delete_action
     * Remove action 'like' from database
     * unliking a post.
     * @param $postid
     * @return object
     **/ 
	public function delete_action($postid) {
        global $DB, $CFG, $USER;

        require_once($CFG->dirroot.'/mod/hsuforum/hsuforum_actions_lib.php');

        $result = (object)[];
        $result->result = false; // set in case uncaught error happens
        $result->content = 'Unknown error';

        //Only allow to remove action if logged in
        if(isloggedin()) {

            $deleted = $DB->delete_records('hsuforum_actions', array('postid' => $postid, 'userid' => $USER->id, 'action' => self::ACTION));
            
            //Get post to return
            $sql = "
            SELECT
                p.id,
                p.discussion
            FROM
                " . self::TABLE . " p
            WHERE
                p.id = $postid
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
    public function render_action($post) {
        global $DB, $USER;

        // TODO: move this to class, check to see if current post for user has been liked or not.            
        $sql = "SELECT * FROM " . self::ACTIONS_TABLE . "
                WHERE postid = ? 
                AND userid = ?
                AND action = 'like'";

        $params = [
            $post->id,
            $USER->id
        ];

        $like = $DB->record_exists_sql($sql, $params);

        if ($like) {
            return '
            <a title="" class="hsuforum-reply-link btn btn-default" id="like-action-'. $post->id .'" href="javascript:M.local_hsuforum_actions.action(`unlike`,' . $post->id . ');">
                <div class="hsuforumdropdownmenuitem"><i class="fa fa-thumbs-down" id="like-' .$post->id. '"></i>
                </div>
            </a>';
        } else {
            return '
            <a title="" class="hsuforum-reply-link btn btn-default" id="like-action-'. $post->id .'" href="javascript:M.local_hsuforum_actions.action(`like`,' . $post->id . ');">
                <div class="hsuforumdropdownmenuitem"><i class="fa fa-thumbs-up" id="like-' . $post->id . '"></i>
                </div>
            </a>';
        }
    }
}
