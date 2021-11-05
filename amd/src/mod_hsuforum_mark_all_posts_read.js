/**
 * Small JS file to mark all posts as read.
 */
define(['jquery'], function($) {

    return {
        init: function(discussionid, userid) {
            $(document).on("click", "#markallasread", function() {
                $.ajax({
                    url: "mark_all_posts_read.php",
                    method: "POST",
                    data: {
                        discussionid: discussionid,
                        userid: userid
                    },
                    dataType: "text",
                    success: function(data) {
                        window.location.reload();
                    }
                });
            })
        }
    };
});
