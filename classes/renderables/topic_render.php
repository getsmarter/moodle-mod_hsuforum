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
    const BUTTONCLASS = 'btn btn-primary';

    /**
     * topic_subcription_button
     * Builds new subscription button html desktop/mobile.
     * @param string $last_reply_html html built in render.php,
     * last replied date
     * @param null $currently_subbed true/false if current user
     * is subscribed to the current forum topic
     * @return string
     */
    public function topic_subcription_button($lastreplyhtml = '', $currentlysubbed = null) {

        global $OUTPUT;
        $following = $followingmobile = '';

        if(!empty($currentlysubbed)) {
            $following = get_string('topicfollowing','hsuforum');
            $followingmobile = get_string('topicfollowing','hsuforum');
        } else {
            $following = get_string('topicfollowdesktop','hsuforum');
            $followingmobile = get_string('topicfollowmobile','hsuforum');
        }

        return $OUTPUT->render_from_template('mod_hsuforum/hsuforum_topic_buttons',
            [
                'buttonclass' => SELF::BUTTONCLASS,
                'lastreplyhtml' => $lastreplyhtml,
                'currentlysubbed' => $currentlysubbed,
                'following' => $following,
                'followingmobile' => $followingmobile
            ]
        );
    }

    /**
     * contributors_html
     * Takes a llist of users, and builds the avatar html based on settings.
     * @param $users list of user avatar img tags
     * @return string
     */
    public function contributors_html($users) {
        $participants = '';
        $avatarlist = '';
        $avatars = implode(' ', $users->replyavatars);

        $config = get_config('hsuforum');

        if(!empty($config->avatarnumberstorenders)) {
            for($x = 0; $x < $config->avatarnumberstorenders; $x++) {
                if(!empty($users->replyavatars[$x])) {
                    $avatarlist .= $users->replyavatars[$x];
                } else {
                    continue;
                }
            }
        }

        if(empty($avatarlist)) {
            $avatarlist = $avatars;
        }

        if(!empty($avatarlist)) {
            $participants .= '<div class="hsuforum-thread-participants">' . $avatarlist . '<span class="badge badge-pink">' . get_string('avatarnewbadge', 'hsuforum') . '</span></div>';
        }

        return $participants;
    }

}

