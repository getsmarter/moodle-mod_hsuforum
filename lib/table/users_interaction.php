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
 * Student interaction table
 *
 * @package    mod
 * @subpackage hsuforum
 * @copyright  2021 Brendon Pretorius <bpretorius@2u.com>
 * @author     Brendon Pretorius
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->libdir.'/tablelib.php');

class hsuforum_lib_table_users_interaction extends table_sql
{
    const BASE_COLUMNS = [
        'userpic',
        'fullname',
        'total',
        'posts',
        'replies'
    ];

    /** @var array */
    var $actionstotrack = [];

    /**
     * @param string $uniqueid a string identifying this table.Used as a key in session  vars.
     * @var array $actionstotrack array of actions to retrieve from the hsuforum_actions table
     */
    public function __construct($uniqueid, $actionstotrack = [], $roles = [], $discussionid = null)
    {
        global $PAGE;
        parent::__construct($uniqueid);

        $this->actionstotrack = $actionstotrack;
        $contextid = context_course::instance($PAGE->course->id)->id;
        $actionssql = '';
        $discussionsql = '';
        $columns = array_merge(self::BASE_COLUMNS, $this->actionstotrack);
        $headers = [
            '',
            get_string('fullnameuser'),
            get_string('totalposts', 'hsuforum'),
            get_string('posts', 'hsuforum'),
            get_string('replies', 'hsuforum'),
        ];

        foreach ($this->actionstotrack as $actiontotrack) {
            $headers[] = get_string("{$actiontotrack}interactionheading", 'hsuforum');
            $actionssql .= ", SUM(CASE WHEN a.action LIKE '%$actiontotrack%' THEN 1 ELSE 0 END) AS `$actiontotrack`";
        }

        $this->define_columns($columns);
        $this->define_headers($headers);

        $fields = user_picture::fields('u', null, 'id');
        $params = [
            $PAGE->activityrecord->id,
            $contextid
        ];

        if (!empty($discussionid)) {
            $params[] = $discussionid;
            $discussionsql = 'AND d.id = ?';
        }
        $params = array_merge($params, $roles);

        // TODO - check cap types needed

        $inplaceholders = str_repeat('?,', count($roles) - 1) . '?';
        $this->set_sql(
            "$fields,
            COUNT(*) AS total,
            SUM(CASE WHEN p.parent = 0 THEN 1 ELSE 0 END) AS posts,
            SUM(CASE WHEN p.parent != 0 THEN 1 ELSE 0 END) AS replies
            $actionssql",
            "{hsuforum_posts} p
            INNER JOIN {hsuforum_discussions} d ON p.discussion = d.id
            INNER JOIN {hsuforum} f ON d.forum = f.id
            INNER JOIN {user} u ON u.id = p.userid
            INNER JOIN {role_assignments} ra ON u.id = ra.userid
            INNER JOIN {role} r ON r.id = ra.roleid 
            LEFT JOIN {hsuforum_actions} a ON p.id = a.postid AND u.id = a.userid",
            "f.id = ? AND ra.contextid = ? {$discussionsql} AND r.shortname IN ({$inplaceholders}) GROUP BY u.id",
            $params
        );

        $this->set_count_sql(
        "SELECT COUNT(DISTINCT p.userid)
              FROM {hsuforum_posts} p
              JOIN {user} u ON u.id = p.userid
              JOIN {hsuforum_discussions} d ON d.id = p.discussion
              JOIN {hsuforum} f ON f.id = d.forum
              INNER JOIN {role_assignments} ra ON u.id = ra.userid
              INNER JOIN {role} r ON r.id = ra.roleid 
              WHERE f.id = ? AND ra.contextid = ? {$discussionsql} AND r.shortname IN ({$inplaceholders})  GROUP BY u.id
        ", $params);
    }

    public function col_userpic($row) {
        global $OUTPUT;
        return $OUTPUT->user_picture(user_picture::unalias($row, null, 'id'));
    }
}
