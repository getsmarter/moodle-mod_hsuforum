define([], function() {
    /**
     * Makes AJAX call to write current text for forum/discussion/post to DB for restoration later on
     * @param forumid int
     * @param discussionid int
     * @param postid int
     * @param text string
     * @param userid int
     */
    function autoSave(forumid, discussionid, postid, text, userid) {
        if ((forumid != '' || discussionid != '') && text != '' && userid != '') {
            $.ajax({
                url: "save_post_draft.php",
                method: "POST",
                data: {
                    forumid: forumid,
                    discussionid: discussionid,
                    postid: postid,
                    text: text,
                    userid: userid
                },
                dataType: "text",
                success: function() {
                    // Do nothing, write-only call
                }
            });
        }
    }

    /**
     * Makes AJAX call to restore draft if it exists
     * @param forumid
     * @param discussionid
     * @param postid
     * @param userid
     */
    function fetchAutoSave(forumid, discussionid, postid, userid) {
        if ((forumid != '' || discussionid != '' || postid != '') && userid != '') {
            $.ajax({
                url: "get_post_draft.php",
                method: "GET",
                data: {
                    forumid: forumid,
                    discussionid: discussionid,
                    postid: postid,
                    userid: userid
                },
                dataType: "text",
                success: function(data) {
                    window.drafttext = data;
                }
            });
        }
    }

    return {
        init: function(forumid, discussionid, userid) {
            $(function() {
                let timer;
                // This is to restore the draft when the page loads and programatic click occurs
                let pageloadclick = false;

                // Bind click to the reply
                $('.hsuforum-reply-link').on('click', function() {
                    window.drafttext = '';

                    let parent = $(this).closest('.hsuforum-post-wrapper');
                    let postid = $(parent).attr('data-postid');

                    fetchAutoSave(forumid, discussionid, postid, userid);

                    setTimeout(function() {
                        $(parent).find('#hiddenadvancededitoreditable').html(window.drafttext);
                    }, 1000);
                });

                $('.hsuforum-footer-reply .hsuforum-use-advanced').not('.hideadvancededitor').on('click', function(e) {
                    // Because we toggle editors programmatically depending on how the users interact with the forum, we
                    // need to differentiate between programmatic clicks after the initial page load click and genuine
                    // user interactions. If programmatic after page load, prevent any further execution
                    if (!e.hasOwnProperty('originalEvent') && pageloadclick) {
                        e.preventDefault();
                        return false;
                    }

                    window.drafttext = '';
                    let postid = null;
                    pageloadclick = true;

                    fetchAutoSave(forumid, discussionid, postid, userid);

                    setTimeout(function() {
                        $('.hsuforum-footer-reply').find('#hiddenadvancededitoreditable').html(window.drafttext);
                    }, 1000);
                });


                // Bind click to new discussion topic button
                $('#newdiscussionform input[type="submit"]').on('click', function() {
                    window.drafttext = '';

                    let postid = null;

                    fetchAutoSave(forumid, discussionid, postid, userid);

                    setTimeout(function() {
                        $('.hsuforum-add-discussion-target').find('#hiddenadvancededitoreditable').html(window.drafttext);
                    }, 1000);
                });

                // Bind to keyup to trigger autosave
                $(document).on("keyup", ".editor_atto_content", function() {
                    let parent = $(this).closest('.hsuforum-post-wrapper');
                    let postid = $(parent).attr('data-postid') || null;
                    let text = $(this).text();

                    if (timer) {
                        clearTimeout(timer);
                    }

                    timer = setTimeout(function() {
                       autoSave(forumid, discussionid, postid, text, userid);
                    }, 5000); // Wait 5000 milliseconds before triggering event.
                });
            });
        }
    };
});
