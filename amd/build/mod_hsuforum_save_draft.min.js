define([], function() {
    return {
        init: function(userid) {
            $(function() {

                function autoSave(discussionid, postid, text, userid) {
                    if (discussionid != '' && postid != '' && text != '' && userid != '') {
                        $.ajax({
                            url: "save_post_draft.php",
                            method: "POST",
                            data: {
                                discussionid: discussionid,
                                postid: postid,
                                text: text,
                                userid: userid
                            },
                            dataType: "text",
                            success: function(data) {
                                console.log(data);
                                if (data != '') {
                                    $('#post_id').val(data);
                                }
                                var time = showTime();
                                $('#autoSave').text("Draft Autosaved " + time).show();
                                $('#autoSave').fadeOut(3000);
                            }
                        });
                    }
                }

                function fetchAutoSave(discussionid, postid, userid) {
                    var datareturn = '';
                    if (discussionid != '' && postid != '' && userid != '') {
                        
                        $.ajax({
                            url: "get_post_draft.php",
                            method: "GET",
                            data: {
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

                function showTime() {

                    var timeNow = new Date();
                    var hours = timeNow.getHours();
                    var minutes = timeNow.getMinutes();
                    var seconds = timeNow.getSeconds();
                    var timeString = "" + ((hours > 12) ? hours - 12 : hours);
                    timeString += ((minutes < 10) ? ":0" : ":") + minutes;
                    timeString += ((seconds < 10) ? ":0" : ":") + seconds;
                    timeString += (hours >= 12) ? " P.M." : " A.M.";
                    
                    return timeString;

                }


                $('.hsuforum-reply-link').on('click', function(){
                    window.drafttext = '';

                    var parent = $(this).closest('.hsuforum-post-wrapper');
                    var postid = $(parent).attr('data-postid');
                    var discussionid = $(parent).attr('data-discussionid');

                    fetchAutoSave(discussionid, postid, userid);

                    setTimeout(function(event) {
                        console.log( $(parent).find('#hiddenadvancededitoreditable'));
                        $(parent).find('#hiddenadvancededitoreditable').html(window.drafttext);
                    }, 1000);
                });


                

                var timer;
                $(document).on("keyup", ".editor_atto_content", function(event) {

                    var parent = $(this).closest('.hsuforum-post-wrapper');
                    var postid = $(parent).attr('data-postid');
                    var discussionid = $(parent).attr('data-discussionid');

                    var text = $(this).text();

                    if (timer) {
                        clearTimeout(timer);
                    }

                    timer = setTimeout(function(event) {
                       autoSave(discussionid, postid, text, userid)
                    }, 5000); //wait 1000 milliseconds before triggering even.

                })             
            });
        }
    };
});
