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
 * Post Flag Controller
 *
 * @package    mod
 * @subpackage hsuforum
 * @copyright  2021 Brendon Pretorius <bpretorius@2u.com>
 * @author     Brendon Pretorius
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_hsuforum\controller;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/controller_abstract.php');
require_once(dirname(__DIR__, 2).'/lib/table/users_interaction.php');
require_once(dirname(__DIR__, 2).'/lib/table/time_interaction.php');

class forum_interaction_controller extends controller_abstract
{
    /** @var string[]
     * add any actions here from the hsuforum actions table that you want to track
     * for POC, this is hard-coded but written in way to be extendable
     */
    const VIEW_ACTIONS_MAPPING = [
        'student' => [
            'like'
        ],
        'facilitator' => [
            'like'
        ]
    ];

    /**
     * @inheritDoc
     */
    public function require_capability($action) {
        // Leave as void for now for POC
    }

    public function viewstudentforuminteraction_action() {
        global $PAGE, $OUTPUT;

        $userview = 'student';
        $roles = [
            'student'
        ];
        $discussionid = optional_param('discussionid', null, PARAM_INT);

        // TODO - find a better way for handling count sql returning no rows. For POC. just keeping it like this
        try {
            echo $OUTPUT->heading(get_string('foruminteractiondataheading', 'hsuforum'));

            $table = new \hsuforum_lib_table_users_interaction(
                "mod_hsuforum_view{$userview}interactions",
                self::VIEW_ACTIONS_MAPPING[$userview],
                $roles,
                $discussionid
            );
            $table->define_baseurl($PAGE->url->out());
            $table->set_attribute('class', 'generaltable generalbox hsuforum_viewposters');
            $table->column_class('userpic', 'col_userpic');
            $table->out('25', false);
        } catch (\Exception $e) {
            echo $OUTPUT->heading(get_string('nodata', 'hsuforum'));
        }
    }


    public function viewfacilitatorforuminteraction_action()
    {
        global $PAGE, $OUTPUT;

        $userview = 'facilitator';
        $roles = [
            'tutor',
            'headtutor'
        ];
        $discussionid = optional_param('discussionid', null, PARAM_INT);

        // TODO - find a better way for handling count sql returning no rows. For POC. just keeping it like this
        try {
            echo $OUTPUT->heading(get_string('foruminteractiondataheading', 'hsuforum'));

            $table = new \hsuforum_lib_table_users_interaction(
                "mod_hsuforum_view{$userview}interactions",
                self::VIEW_ACTIONS_MAPPING[$userview],
                $roles,
                $discussionid
            );
            $table->define_baseurl($PAGE->url->out());
            $table->set_attribute('class', 'generaltable generalbox hsuforum_viewposters');
            $table->column_class('userpic', 'col_userpic');
            $table->out('25', false);
        } catch (\Exception $e) {
            echo $OUTPUT->heading(get_string('nodata', 'hsuforum'));
        }
    }

    public function viewstudentforuminteractiontrimes_action() {
        global $PAGE, $OUTPUT;

        $userview = 'student';
        $roles = [
            'student'
        ];
        $discussionid = optional_param('discussionid', null, PARAM_INT);

        // TODO - find a better way for handling count sql returning no rows. For POC. just keeping it like this
        try {
            echo $OUTPUT->heading(get_string('foruminteractiondataheading', 'hsuforum'));

            $table = new \hsuforum_lib_table_time_interaction(
                "mod_hsuforum_view{$userview}timeinteractions",
                $roles,
                $discussionid
            );
            $table->define_baseurl($PAGE->url->out());
            $table->set_attribute('class', 'generaltable generalbox hsuforum_viewposters');
            $table->column_class('userpic', 'col_userpic');
            $table->out('25', false);
        } catch (\Exception $e) {
            echo $OUTPUT->heading(get_string('nodata', 'hsuforum'));
        }
    }

    public function viewfacilitatorforuminteractiontrimes_action() {
        global $PAGE, $OUTPUT;

        $userview = 'facilitator';
        $roles = [
            'tutor',
            'headtutor'
        ];
        $discussionid = optional_param('discussionid', null, PARAM_INT);

        // TODO - find a better way for handling count sql returning no rows. For POC. just keeping it like this
        try {
            echo $OUTPUT->heading(get_string('foruminteractiondataheading', 'hsuforum'));

            $table = new \hsuforum_lib_table_time_interaction(
                "mod_hsuforum_view{$userview}timeinteractions",
                $roles,
                $discussionid
            );
            $table->define_baseurl($PAGE->url->out());
            $table->set_attribute('class', 'generaltable generalbox hsuforum_viewposters');
            $table->column_class('userpic', 'col_userpic');
            $table->out('25', false);
        } catch (\Exception $e) {
            echo $OUTPUT->heading(get_string('nodata', 'hsuforum'));
        }
    }
}
