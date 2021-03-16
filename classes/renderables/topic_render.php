<?php

defined('MOODLE_INTERNAL') || die();

class topic_render {
    // Should always follow default button class of css theme.
    const BUTTON_CLASS = 'btn btn-primary';


    public function __construct(){

    }

    public function topic_subcription_button($last_reply_html = '') {
        $button_group = '';
        $button_group .= '<div class="d-block d-lg-block d-xl-block" style="bottom: 0px;float: right;">
                                <div class="last-reply-block">' . $last_reply_html . '</div>
                                <button id="subscribe" type="button" class="' . self::BUTTON_CLASS . '">'
                                    . get_string('topicfollowdesktop','hsuforum') .
                                '</button>
                          </div>
                          
                          <div class="d-none d-sm-block d-md-none" style=" bottom: 0px;float: right;">
                                <div class="last-reply-block">' . $last_reply_html . '</div>
                                <button id="subscribe" type="button" class="' . self::BUTTON_CLASS . '">'
                                    . get_string('topicfollowmobile','hsuforum') .
                                '</button>
                          </div>';
        return $button_group;
    }

    /**
     * SVG icon sprite
     *
     * @return string
     */
        //fill="#FFFFFF"
    public function svg_sprite() {
        return '<svg style="display:none" x="0px" y="0px"
             viewBox="0 0 100 100" enable-background="new 0 0 100 100">
        <g id="substantive">
            <polygon points="49.9,3.1 65,33.8 99,38.6 74.4,62.6 80.2,96.3 49.9,80.4 19.7,96.3 25.4,62.6
            0.9,38.6 34.8,33.8 "/>
        </g>
        <g id="bookmark">
            <polygon points="88.7,93.2 50.7,58.6 12.4,93.2 12.4,7.8 88.7,7.8 "/>
        </g>
        </svg>';
    }

}