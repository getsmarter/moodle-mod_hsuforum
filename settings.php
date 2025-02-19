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
 * @copyright  2009 Petr Skoda (http://skodak.org)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright Copyright (c) 2012 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @author Mark Nielsen
 */

defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {
    require_once($CFG->dirroot.'/mod/hsuforum/lib.php');

    $config = get_config('hsuforum');

    $settings->add(new admin_setting_configcheckbox('hsuforum/replytouser', get_string('replytouser', 'hsuforum'),
                       get_string('configreplytouser', 'hsuforum'), 1));

    // Less non-HTML characters than this is short
    $settings->add(new admin_setting_configtext('hsuforum/shortpost', get_string('shortpost', 'hsuforum'),
                       get_string('configshortpost', 'hsuforum'), 300, PARAM_INT));

    // More non-HTML characters than this is long
    $settings->add(new admin_setting_configtext('hsuforum/longpost', get_string('longpost', 'hsuforum'),
                       get_string('configlongpost', 'hsuforum'), 600, PARAM_INT));

    // Number of discussions on a page
    $settings->add(new admin_setting_configtext('hsuforum/manydiscussions', get_string('manydiscussions', 'hsuforum'),
                       get_string('configmanydiscussions', 'hsuforum'), 100, PARAM_INT));

    if (isset($CFG->maxbytes)) {
        $maxbytes = 0;
        if (isset($config->maxbytes)) {
            $maxbytes = $config->maxbytes;
        }
        $settings->add(new admin_setting_configselect('hsuforum/maxbytes', get_string('maxattachmentsize', 'hsuforum'),
                           get_string('configmaxbytes', 'hsuforum'), 512000, get_max_upload_sizes($CFG->maxbytes, 0, 0, $maxbytes)));
    }

    // Default number of attachments allowed per post in all forums
    $options = array(
        0 => 0,
        1 => 1,
        2 => 2,
        3 => 3,
        4 => 4,
        5 => 5,
        6 => 6,
        7 => 7,
        8 => 8,
        9 => 9,
        10 => 10,
        20 => 20,
        50 => 50,
        100 => 100
    );
    $settings->add(new admin_setting_configselect('hsuforum/maxattachments', get_string('maxattachments', 'hsuforum'),
                       get_string('configmaxattachments', 'hsuforum'), 9, $options));

    // Default number of days that a post is considered old
    $settings->add(new admin_setting_configtext('hsuforum/oldpostdays', get_string('oldpostdays', 'hsuforum'),
                       get_string('configoldpostdays', 'hsuforum'), 14, PARAM_INT));

    $options = array();
    for ($i = 0; $i < 24; $i++) {
        $options[$i] = sprintf("%02d",$i);
    }
    // Default time (hour) to execute 'clean_read_records' cron
    $settings->add(new admin_setting_configselect('hsuforum/cleanreadtime', get_string('cleanreadtime', 'hsuforum'),
                       get_string('configcleanreadtime', 'hsuforum'), 2, $options));

    // Default time (hour) to send digest email
    $settings->add(new admin_setting_configselect('hsuforum/digestmailtime', get_string('digestmailtime', 'hsuforum'),
                       get_string('configdigestmailtime', 'hsuforum'), 17, $options));

    if (empty($CFG->enablerssfeeds)) {
        $options = array(0 => get_string('rssglobaldisabled', 'admin'));
        $str = get_string('configenablerssfeeds', 'hsuforum').'<br />'.get_string('configenablerssfeedsdisabled2', 'admin');

    } else {
        $options = array(0=>get_string('no'), 1=>get_string('yes'));
        $str = get_string('configenablerssfeeds', 'hsuforum');
    }
    $settings->add(new admin_setting_configselect('hsuforum/enablerssfeeds', get_string('enablerssfeeds', 'admin'),
                       $str, 0, $options));

    if (!empty($CFG->enablerssfeeds)) {
        $options = array(
            0 => get_string('none'),
            1 => get_string('discussions', 'hsuforum'),
            2 => get_string('posts', 'hsuforum')
        );
        $settings->add(new admin_setting_configselect('hsuforum_rsstype', get_string('rsstypedefault', 'hsuforum'),
                get_string('configrsstypedefault', 'hsuforum'), 0, $options));

        $options = array(
            0  => '0',
            1  => '1',
            2  => '2',
            3  => '3',
            4  => '4',
            5  => '5',
            10 => '10',
            15 => '15',
            20 => '20',
            25 => '25',
            30 => '30',
            40 => '40',
            50 => '50'
        );
        $settings->add(new admin_setting_configselect('hsuforum_rssarticles', get_string('rssarticles', 'hsuforum'),
                get_string('configrssarticlesdefault', 'hsuforum'), 0, $options));
    }

    $settings->add(new admin_setting_configcheckbox('hsuforum/enabletimedposts', get_string('timedposts', 'hsuforum'),
                       get_string('configenabletimedposts', 'hsuforum'), 0));

    $settings->add(new admin_setting_configcheckbox('hsuforum/hiderecentposts', get_string('hiderecentposts', 'hsuforum'),
                       get_string('confighiderecentposts', 'hsuforum'), 0));

    $name = 'hsuforum/avatarnumberstorenders';
    $title = get_string('avatarnumberstorenders', 'hsuforum');
    $description = get_string('avatarnumberstorendersdescription', 'hsuforum');
    $default = '';
    $options = array(
        ''  => 'default',
        5  => '5',
        10  => '10',
        15  => '15',
        20  => '20',
    );
    $settings->add(new admin_setting_configselect($name, $description, $default, 0, $options));

    // New Avatar HSU forum settings
    $name = 'hsuforum/newavatarbadgeheading';
    $heading = get_string('avatarnewbadgesettingsheader', 'hsuforum');
    $information = '';
    $setting = new admin_setting_heading($name, $heading, $information);
    $settings->add($setting);

    //bg color
    $name = 'hsuforum/newavatarbadgebackgroundcolour';
    $title = get_string('new_avatar_badge_backgroundcolour', 'hsuforum');
    $default = '#E51470';
    $previewconfig = null;
    $setting = new admin_setting_configcolourpicker($name, $title, $description, $default, $previewconfig);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $settings->add($setting);

    //border color
    $name = 'hsuforum/newavatarbadgebordercolour';
    $title = get_string('new_avatar_badge_bordercolour', 'hsuforum');
    $default = '#E51470';
    $previewconfig = null;
    $setting = new admin_setting_configcolourpicker($name, $title, $description, $default, $previewconfig);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $settings->add($setting);

    //text color
    $name = 'hsuforum/newavatarbadgetextcolour';
    $title = get_string('new_avatar_badge_textcolour', 'hsuforum');
    $default = '#fff';
    $previewconfig = null;
    $setting = new admin_setting_configcolourpicker($name, $title, $description, $default, $previewconfig);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $settings->add($setting);

    //border radius
    $name = 'hsuforum/newavatarbadgeborderradius';
    $title = get_string('new_avatar_badge_borderradius', 'hsuforum');
    $description = get_string('new_avatar_badge_borderradius_desc', 'hsuforum');
    $setting = new admin_setting_configtext($name, $title, $description, '.188rem');
    $setting->set_updatedcallback('theme_reset_all_caches');
    $settings->add($setting);

    //Hover background color
    $name = 'hsuforum/newavatarbadgehoverbackgroundcolour';
    $title = get_string('new_avatar_badge_hoverbackgroundcolor', 'hsuforum');
    $default = '#CD1268';
    $setting = new admin_setting_configcolourpicker($name, $title, '', $default);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $settings->add($setting);

    //Hover text color
    $name = 'hsuforum/newavatarbadgehovertextcolour';
    $title = get_string('new_avatar_badge_hovertextcolor', 'hsuforum');
    $default = '#fff';
    $setting = new admin_setting_configcolourpicker($name, $title,'', $default);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $settings->add($setting);

    // New Avatar HSU forum settings
    $name = 'hsuforum/customdraftsheading';
    $heading = get_string('customdraftsheader', 'hsuforum');
    $information = '';
    $setting = new admin_setting_heading($name, $heading, $information);
    $settings->add($setting);

    $name = 'hsuforum/daystopersistdrafts';
    $title = get_string('customdraftdurationlabel', 'hsuforum');
    $description = get_string('customdraftdurationdesc', 'hsuforum');
    $default = 2;
    // Quick way to get array with key/value pairs from 1-7
    $options = array_slice(range(0, 7), 1, null, true);
    $settings->add(new admin_setting_configselect($name, $title, $description, $default, $options));
}
