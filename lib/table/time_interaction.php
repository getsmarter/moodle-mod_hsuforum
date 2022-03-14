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
 * Time interaction table
 *
 * @package    mod
 * @subpackage hsuforum
 * @copyright  2021 Brendon Pretorius <bpretorius@2u.com>
 * @author     Brendon Pretorius
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->libdir.'/tablelib.php');

class hsuforum_lib_table_time_interaction extends table_sql
{
    public function __construct($uniqueid, $roles, $discussionid)
    {
        global $PAGE;
        parent::__construct($uniqueid);
        $contextid = context_course::instance($PAGE->course->id)->id;
        $headers = [
            get_string('postcountheader', 'hsuforum'),
            get_string('postcreatedhourheader', 'hsuforum')
        ];

        $this->define_columns([
                'postcount',
                'postcreated'
            ]);
        $this->define_headers($headers);

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
            "DATE_FORMAT(FROM_UNIXTIME(p.created), '%H') AS postcreated,
            count(p.id) AS `postcount`",
            '{hsuforum_posts} p
            INNER JOIN {hsuforum_discussions} d ON p.discussion = d.id
            INNER JOIN {hsuforum} f ON d.forum = f.id
            INNER JOIN {user} u ON u.id = p.userid
            INNER JOIN {role_assignments} ra ON u.id = ra.userid
            INNER JOIN {role} r ON r.id = ra.roleid',
            "f.id = ? AND ra.contextid = ? {$discussionsql} AND r.shortname IN ({$inplaceholders})
            GROUP BY postcreated",
            $params
        );

        $this->set_count_sql(
            "SELECT
            count(p.id) AS `postcount`,
            DATE_FORMAT(FROM_UNIXTIME(p.created), '%H') AS postcreated
            FROM
                {hsuforum_posts} p
                INNER JOIN {hsuforum_discussions} d ON p.discussion = d.id
                INNER JOIN {hsuforum} f ON d.forum = f.id
                INNER JOIN {user} u ON u.id = p.userid
                INNER JOIN {role_assignments} ra ON u.id = ra.userid
                INNER JOIN {role} r ON r.id = ra.roleid
            WHERE f.id = ? AND ra.contextid = ? {$discussionsql} AND r.shortname IN ({$inplaceholders})
            GROUP BY postcreated
        ", $params);
    }

    public function col_postcreated($row) {
        return sprintf(
            '%s:00-%s:00',
            $row->postcreated,
            str_pad($row->postcreated + 1, 2, '0', STR_PAD_LEFT)
        );
    }
}
