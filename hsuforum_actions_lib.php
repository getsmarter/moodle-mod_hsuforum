<?php
/**
* Forum Actions
*
* @package   hsuforum_actions
* @copyright 2014 Moodle Pty Ltd (http://moodle.com)
* @author    Mikhail Janowski <mikhail@getsmarter.co.za>
* @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

function hsuforum_populate_post_actions(&$posts)
{
   global $DB;
   global $USER;

   //Generate string for 'in' clause of query and add needed variebles to each post
   $postidarray = array();
   foreach ($posts as $key => $value) {
       $postidarray[] = $key;

       //Initialise post fields which get filled later
       $posts[$key]->liked = false;//has the current user liked this post
       $posts[$key]->likecount = 0;//how many users (exc. current user) liked this post
       $posts[$key]->liketext = '';//text to display next to like button
       $posts[$key]->likebuttontext = '';//text to display on the like button
       $posts[$key]->likeaction = '';//like button action, either like or unlike
       $posts[$key]->likeusers = array();//all users who liked this post

       $posts[$key]->thanksed = false;
       $posts[$key]->thankscount = 0;
       $posts[$key]->thankstext = '';
       $posts[$key]->thanksbuttontext = '';
       $posts[$key]->thanksaction = '';
       $posts[$key]->thanksusers = array();

       $posts[$key]->actionHTML = '';
   }
   $postidstring = implode($postidarray, ',');


   //Get actions for posts
   $sql = "
	SELECT
	    a.id,
	    a.postid,
	    a.userid,
		a.action,
		a.created,
		u.firstname,
		u.lastname
	FROM
	    {hsuforum_actions} a
	INNER JOIN
	    {user} u
	ON
	    a.userid = u.id
	WHERE
	    a.postid IN($postidstring)
	   	and action <> 'thanks'
	";
   $actions = $DB->get_records_sql($sql);


   //Add actions to posts array
   foreach ($actions as $key => $action) {

       //If its a like
       if ($action->action == 'like') {

           //Add user to list
           $user = new stdClass();
           $user->id = $action->userid;
           $user->time = $action->created;

           if ($action->userid == $USER->id) {
               $user->name = 'You';
               $posts[$action->postid]->liked = true;
           } else {
               $user->name = $action->firstname . ' ' . $action->lastname;
               //Add user to array
               $posts[$action->postid]->likeusers[] = $user;
           }

           //Increment like count
           $posts[$action->postid]->likecount++;

       }

       //If its a thanks
       if ($action->action == 'thanks') {

           //Add user to list
           $user = new stdClass();
           $user->id = $action->userid;
           $user->time = $action->created;

           if ($action->userid == $USER->id) {
               $user->name = 'You';
               $posts[$action->postid]->thanksed = true;
           } else {
               $user->name = $action->firstname . ' ' . $action->lastname;
               //Add user to array
               $posts[$action->postid]->thanksusers[] = $user;
           }

           //Increment thanks count
           $posts[$action->postid]->thankscount++;

       }
   }

   //Generate post action and text
   foreach ($posts as $key => $post) {

       //Like

       //Has not liked post
       if (!$post->liked) {
           if ($post->likecount == 1) {
               $post->liketext = hsuforum_generate_user_list($post->likeusers, $post->discussion) . ' likes this';
           } else if ($post->likecount > 1) {
               $post->liketext = hsuforum_generate_user_list($post->likeusers, $post->discussion) . ' like this';
           }
           $post->likebuttontext = ucfirst(get_config('local_hsuforum_actions', 'liketext'));
           $post->likeaction = "javascript:M.local_hsuforum_actions.add('{$key}', 'like');";
       } //Has liked post
       else {
           if (($post->likecount - 1) == 0) {
               $post->liketext = 'You like this';
           }
           if (($post->likecount - 1) == 1) {
               $post->liketext = 'You and ' . hsuforum_generate_user_list($post->likeusers, $post->discussion) . ' like this';
           } else if (($post->likecount - 1) > 1) {
               $post->liketext = 'You, ' . hsuforum_generate_user_list($post->likeusers, $post->discussion) . ' like this';
           }
           $post->likebuttontext = 'Un' . lcfirst(get_config('local_hsuforum_actions', 'liketext'));
           $post->likeaction = "javascript:M.local_hsuforum_actions.remove('{$key}', 'like');";
       }

       $post->liketext = hsuforum_wrap_action_text($post->liketext, 'Like');

       //Thanks

       //Has not thanksed post
       if (!$post->thanksed) {
           if ($post->thankscount == 1) {
               $post->thankstext = hsuforum_generate_user_list($post->thanksusers, $post->discussion) . ' said thanks';
           } else if ($post->thankscount > 1) {
               $post->thankstext = hsuforum_generate_user_list($post->thanksusers, $post->discussion) . ' said thanks';
           }
           $post->thanksbuttontext = ucfirst(get_config('local_hsuforum_actions', 'thankstext'));
           $post->thanksaction = "javascript:M.local_hsuforum_actions.add('{$key}', 'thanks');";
       } //Has thanksed post
       else {
           if (($post->thankscount - 1) == 0) {
               $post->thankstext = 'You said thanks';
           }
           if (($post->thankscount - 1) == 1) {
               $post->thankstext = 'You and ' . hsuforum_generate_user_list($post->thanksusers, $post->discussion) . ' said thanks';
           } else if (($post->thankscount - 1) > 1) {
               $post->thankstext = 'You, ' . hsuforum_generate_user_list($post->thanksusers, $post->discussion) . ' said thanks';
           }
           $post->thanksbuttontext = 'Un' . lcfirst(get_config('local_hsuforum_actions', 'thankstext'));
           $post->thanksaction = "javascript:M.local_hsuforum_actions.remove('{$key}', 'thanks');";
       }

       $post->thankstext = hsuforum_wrap_action_text($post->thankstext, 'Thanks');
   }


   //Generate post HTML
   foreach ($posts as $key => $post) {
       //Call hsuforum_generate_post_action_HTML() on each post
       $posts[$key]->actionHTML = hsuforum_generate_post_action_HTML($post);
       $posts[$key]->likeandthanksHTML = generate_like_and_thanks_buttons($post);
   }
}

function hsuforum_generate_post_action_HTML($post)
{

   $html = <<<HTML
<div class="actions" id="p{$post->id}actions">
{$post->liketext}
{$post->thankstext}
</div>
HTML;

   return $html;
}

function generate_like_and_thanks_buttons($post)
{

   $likecount = $post->likecount == 0 ? '' : $post->likecount;
   $thankscount = $post->thankscount == 0 ? '' : $post->thankscount;

   $html = <<<HTML
       <span id="p{$post->id}-likeandthanks" class="like-and-thanks">
           <a href="{$post->likeaction}"><i class="fa fa-thumbs-up"></i> <div class="hsuforumdropdownmenuitem">{$post->likebuttontext}</div></a><span>{$likecount}</span>

       </span>
HTML;

   return $html;
}

function hsuforum_generate_user_list($users, $discussionid)
{

   global $PAGE, $DB;

   $courseid = $DB->get_field('hsuforum_discussions', 'course', array('id' => $discussionid));

   //Divide array into two, first part to sisplay names, second part to display in tooltip

   $maxnames = get_config('local_hsuforum_actions', 'maxnames');

   $offset = $maxnames;//Offset + 1 = max users in list of users, all other users will be put in tooltip
   if ((count($users) - 1) < $offset) {
       $offset = count($users) - 1;
   }

   $listusers = array_slice($users, 0, $offset);
   $tooltipusers = array_slice($users, $offset);

   $html = '';

   //Concat list users
   if (count($listusers) > 0) {

       for ($i = 0; $i < count($listusers); $i++) {

           $html .= hsuforum_create_user_link($listusers[$i]->name, $listusers[$i]->id, $courseid);

           if (count($listusers) - $i > 1) {
               $html .= ', ';
           }
       }

       $html .= ' and ';
   }

   //Concat tooltip users
   if (count($tooltipusers) > 1) {

       $tooltipList = '';
       $htmlList = '';

       for ($i = 0; $i < count($tooltipusers); $i++) {

           $tooltipList .= ' ' . $tooltipusers[$i]->name;
           $htmlList .= hsuforum_create_user_link($tooltipusers[$i]->name, $tooltipusers[$i]->id, $courseid) . '<br />';

           if (count($tooltipusers) - $i > 1) {
               $tooltipList .= '&#013;';//line break
           }
       }

       $html .= '<a class="other-users-link" href="javascript:void(0);" title="' . $tooltipList . '">' . count($tooltipusers) . ' others</a>';
       $html .= '<div class="other-users" style="display:none;">' . $htmlList . '</div>';
   } else {
       $html .= hsuforum_create_user_link($tooltipusers[0]->name, $tooltipusers[0]->id, $courseid);
   }

   return $html;
}

function hsuforum_create_user_link($name, $id, $courseid)
{
   global $CFG;
   $url = $CFG->wwwroot . '/user/view.php?id=' . $id . '&course=' . $courseid;
   $html = '<a href="' . $url . '" target="_blank">' . $name . '</a>';

   return $html;
}

function hsuforum_wrap_action_text($actiontext, $action)
{

   $html = '';

   if ($actiontext != '') {
       $class = 'alert alert-info like';
       if ($action == 'Thanks') {
           $class = 'alert alert-warning thanks';
       }

       $html .= '<div class="' . $class . '">';
       $html .= $actiontext;
       $html .= '</div>';
   }

   return $html;
}
