<?php

defined('MOODLE_INTERNAL') || die();

class topic_render {
    // Should always follow default button class of css theme.
    const BUTTON_CLASS = 'btn btn-primary';

    public function topic_subcription_button($last_reply_html = '', $currently_subbed = null) {

        $button_group = '';
        if(!empty($currently_subbed)) {
            $button_group .= '<div class="d-block d-lg-block d-xl-block subscriber-wrapper">
                                <div class="last-reply-block">' . $last_reply_html . '</div>
                                    <button type="button" class="trigger-subscribe ' . self::BUTTON_CLASS . '">'
                                . get_string('topicfollowing','hsuforum') .
                                '</button>
                              </div>
                              <div class="d-none d-sm-block d-md-none mobile-btn subscriber-wrapper">
                                 <div class="last-reply-block">' . $last_reply_html . '</div>
                                    <button  type="button" class="trigger-subscribe ' . self::BUTTON_CLASS . '">'
                                    . get_string('topicfollowing','hsuforum') .
                                    '</button>
                              </div>';
        } else {
            $button_group .= '<div class="d-block d-lg-block d-xl-block subscriber-wrapper">
                                <div class="last-reply-block">' . $last_reply_html . '</div>
                                    <button type="button" class="trigger-subscribe ' . self::BUTTON_CLASS . '">'
                                    . get_string('topicfollowdesktop','hsuforum') .
                                    '</button>
                              </div>
                              <div class="d-none d-sm-block d-md-none mobile-btn subscriber-wrapper">
                                 <div class="last-reply-block">' . $last_reply_html . '</div>
                                    <button  type="button" class="trigger-subscribe ' . self::BUTTON_CLASS . '">'
                                    . get_string('topicfollowmobile','hsuforum') .
                                    '</button>
                              </div>';
        }

        return $button_group;
    }

    public function contributors_html($users) {
        $participants = '';

        $participants .= '<div class="hsuforum-thread-participants">' . implode(' ', $users->replyavatars) . '</div>';

        return $participants;
    }


}