<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Class topic_render
 * @package    mod
 * @subpackage hsuforum
 * @author    khendricks@2u.com
 * @copyright Copyright (c) 2U 2u.com
 */

class topic_render {
    // Should always follow default button class of css theme.
    const BUTTON_CLASS = 'btn btn-primary';

    /**
     * topic_subcription_button
     * Builds new subscription button html desktop/mobile.
     * @param string $last_reply_html html built in render.php,
     * last replied date
     * @param null $currently_subbed true/false if current user
     * is subscribed to the current forum topic
     * @return string
     */
    public function topic_subcription_button($last_reply_html = '', $currently_subbed = null) {

        $button_group = '';
        if(!empty($currently_subbed)) {
            $button_group .= '<div class="d-block d-lg-block d-xl-block subscriber-wrapper">
                                <div class="last-reply-block">' . $last_reply_html . '</div>
                                    <button type="button" class="trigger-subscribe rounded-pill ' . self::BUTTON_CLASS . '">'
                                . get_string('topicfollowing','hsuforum') .
                                '</button>
                              </div>
                              <div class="d-none d-sm-block d-md-none mobile-btn subscriber-wrapper">
                                 <div class="last-reply-block">' . $last_reply_html . '</div>
                                    <button  type="button" class="trigger-subscribe rounded-pill ' . self::BUTTON_CLASS . '">'
                                    . get_string('topicfollowing','hsuforum') .
                                    '</button>
                              </div>';
        } else {
            $button_group .= '<div class="d-block d-lg-block d-xl-block subscriber-wrapper">
                                <div class="last-reply-block">' . $last_reply_html . '</div>
                                    <button type="button" class="trigger-subscribe rounded-pill ' . self::BUTTON_CLASS . '">'
                                    . get_string('topicfollowdesktop','hsuforum') .
                                    '</button>
                              </div>
                              <div class="d-none d-sm-block d-md-none mobile-btn subscriber-wrapper">
                                 <div class="last-reply-block">' . $last_reply_html . '</div>
                                    <button  type="button" class="trigger-subscribe rounded-pill ' . self::BUTTON_CLASS . '">'
                                    . get_string('topicfollowmobile','hsuforum') .
                                    '</button>
                              </div>';
        }

        return $button_group;
    }

    /**
     * contributors_html
     * Takes a llist of users, and builds the avatar html based on settings.
     * @param $users list of user avatar img tags
     * @return string
     */
    public function contributors_html($users) {
        $participants = '';
        $avatar_list = '';
        $avatars = implode(' ', $users->replyavatars);

        $config = get_config('hsuforum');

        if(!empty($config->avatarnumberstorenders)) {
            for($x = 0; $x < $config->avatarnumberstorenders; $x++) {
                if(!empty($users->replyavatars[$x])) {
                    $avatar_list .= $users->replyavatars[$x];
                } else {
                    continue;
                }
            }
        }

        if(empty($avatar_list)) {
            $avatar_list = $avatars;
        }

        if(!empty($avatar_list)) {
            $participants .= '<div class="hsuforum-thread-participants">' . $avatar_list . '<span class="badge badge-pink">' . get_string('avatarnewbadge', 'hsuforum') . '</span></div>';
        }

        return $participants;
    }

}

