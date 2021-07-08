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
     * Builds new subscription button html.
     * @param $currentlysubbed true/false if current user
     * is subscribed to the current forum topic.
     * @return string
     */
    public function topic_subcription_button($currentlysubbed = false) {
        global $OUTPUT;

        $following = $followingmobile = $pinned = $latestposttime = '';

        if(!empty($currentlysubbed)) {
            $following = get_string('topicfollowing','hsuforum');
        } else {
            $following = get_string('topicfollowdesktop','hsuforum');
        }

        return $OUTPUT->render_from_template('mod_hsuforum/hsuforum_topic_buttons',
            [
                'buttonclass' => SELF::BUTTONCLASS,
                'following' => $following
            ]
        );
    }

    /**
     * topic_button_meta
     * Return fully HTML, button with meta data.
     * @param string $button button HTML.
     * @param object $discussion dicussion object.
     */
    public function topic_button_meta($button, $discussion) {
        global $OUTPUT;

        $meta = $latestposttime = $pinned = '';

        if (!empty($discussion->timemodified) && !empty($discussion->replies)) {
            $latestposttime = get_string('lastposttimeago', 'hsuforum', hsuforum_relative_time($discussion->timemodified));
        }

        if ($discussion->pinned == 1) {
            $pinned = $OUTPUT->render_from_template('mod_hsuforum/_topic/hsuforum_topic_button_meta',
                [
                    'lastposttime' => $latestposttime,
                    'pinned' => get_string('discussionpinnedpost', 'hsuforum'),
                ]
            );
        }

        $meta = $pinned . $button;

        return $meta;
    }

    /**
     * contributors_html
     * Takes a list of users, and builds the avatar html based on settings.
     * @param $users list of user avatar img tags
     * @return string
     */
    public function contributors_html($discussion) {
        return '';
        $participants = '';
        $avatarlist = '';
        $avatars = implode(' ', $discussion->replyavatars);

        $config = get_config('hsuforum');

        if(!empty($config->avatarnumberstorenders)) {
            for($x = 0; $x < $config->avatarnumberstorenders; $x++) {
                if(!empty($discussion->replyavatars[$x])) {
                    $avatarlist .= $discussion->replyavatars[$x];
                } else {
                    continue;
                }
            }
        }

        if(empty($avatarlist)) {
            $avatarlist = $avatars;
        }

        if($discussion->unread != '-') {
            if(!empty($avatarlist)) {
                $participants .= '<div class="hsuforum-thread-participants">' . $avatarlist . '<span class="badge badge-pink">' . get_string('avatarnewbadge', 'hsuforum') . '</span></div>';
            }
        } else {
            if(!empty($avatarlist)) {
                $participants .= '<div class="hsuforum-thread-participants">' . $avatarlist . '</span></div>';
            }
        }



        return $participants;
    }

}

