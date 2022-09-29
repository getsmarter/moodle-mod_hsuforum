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

namespace mod_hsuforum;

use core_date;

defined('MOODLE_INTERNAL') || die();

/**
 * General utility class for hsu forum.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class util {
    /**
     * Function to return users set timezone and flag
     * @return string
     */
    public static function set_user_flag_and_timezone($data) {
        global $USER;
        $countrycode = strtolower($data->country);

        $time = userdate(time(), '%H:%M', core_date::get_user_timezone($USER));

        $output = '<div class="userpicture-additional">';
        $output .= '<div class="userpicture-time">' . $time . '</div>';

        if (!empty($countrycode)) {
            $output .= '<div class="f16 userpicture-flag"><span class="flag ' . $countrycode . '"></span></div>';
        }
        $output .= '</div>';
        return $output;
    }
}
