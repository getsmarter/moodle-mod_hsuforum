define(['jquery', 'core/ajax'], function ($, ajax) {

    /**
     * Wait for an element before resolving a promise
     * @param {String} querySelector - Selector of element to wait for
     * @param {Integer} timeout - Milliseconds to wait before timing out, or 0 for no timeout
     */
    function waitForElement(querySelector, timeout= 0) {

        const startTime = new Date().getTime();

        return new Promise((resolve, reject) => {
            const timer = setInterval(() => {

                const now = new Date().getTime();
                if(document.querySelector(querySelector)) {
                    clearInterval(timer);
                    resolve();
                }else if(timeout && now - startTime >= timeout) {
                    clearInterval(timer);
                    reject();
                }
            }, 100);
        });
    }

    /**
     * Function to observe the post body for a dicsussion thread.
     *
     * Description:
     * ------------
     * Post reply/edit forms are added to the post body dynamically with php.
     * We need to watch for content changes in the post body for injected forms (reply/edit) to dispatch spinner events.
     * Stopping the spinner is tied into the scrolling action which is in local/hsuforum_actions/amd/src/hsuforum_actions.js
     */
    registerPostsObserver = function() {
        const posts = $('.hsuforum-post-wrapper');

        // Accounting for root reply box at the bottom of the page
        const footerReply = $('.hsuforum-footer-reply')[0];
        if (footerReply != undefined) {
            posts.push(footerReply);
        }

        if (posts) {
            $(posts).each(function(){

                const postObserver = new MutationObserver(() => {
                    let form = $(this).find('form');
                    if (form) {
                        let formTextarea = $(form).find('.hsuforum-textarea');
                        $(form).on('submit', () => {
                            // Check for form errors
                            if ($(formTextarea).text() != 0) {
                                var cookie = "Reply=yes";
                                document.cookie = cookie;
                                var postid = $('input:hidden[name=reply]').val(); //forms current hidden reply value, replyto
                                ajax.call([{
                                    headers: "max-age=1000",
                                    methodname: 'mod_hsuforum_mark_single_post_read',
                                    args: {postid: postid},
                                    done: setTimeout(function () {
                                        //marked new posts as read frm commenter, fix and move to func and call here
                                        var url = $(location).attr('href');
                                        var hidenew = url.substr(url.lastIndexOf("&postid=") + 8);
                                        $("#hsuforum-post-" + hidenew).find("span.hsuforum-unreadcount").hide();
                                    }, 3000),
                                }]);
                            }
                        });
                    }

                });
                postObserver.observe(this, {subtree: true, childList: true});
             });
        }

    }
    // remove cookie on reload
    $(window).on('load', function(){
        document.cookie = "Reply= ; expires = Thu, 01 Jan 1970 00:00:00 GMT"
    });

    /**
     * Handler function to start the spinner
     */
    startSpinnerHandler = function() {
        $('#hsuforum-overlay-box').show();
        $('#hsuforum-loading-container').show();
    }

    /**
     * Handler function to stop the spinner
     */
    stopSpinnerHandler = function() {
        $('#hsuforum-overlay-box').hide();
        $('#hsuforum-loading-container').hide();
    }

    /**
     * Function to register custom spinner events and make globally available.
     */
    registerSpinnerEvents = function() {
        const spinnerStartEvent = new Event('spinnerStartEvent');
        const spinnerStopEvent = new Event('spinnerStopEvent');

        window.spinnerStartEvent = spinnerStartEvent;
        window.spinnerStopEvent = spinnerStopEvent;

        document.body.addEventListener("spinnerStartEvent", () => {
            startSpinnerHandler();
        });

        document.body.addEventListener("spinnerStopEvent", () => {
            stopSpinnerHandler();
        });
    }

    return {
        init: function () {
            waitForElement("body", 30000).then(function() {
                $('.container :input').prop('disabled', false);
                $('.mod-hsuforum-posts-container').show();
                $('#hsuforum-loading-container').hide();
                $('#hsuforum-overlay-box').hide();
                window.hascompleted = false;
                var postid = window.location.hash
                if (!window.hascompleted) {
                    if (postid) {
                        if ($(postid).closest('li').data('depth') > 0) {
                            $(postid).closest('.posts-collapse-container').addClass('show').find('.posts-collapse-toggle.collapse-bottom').attr('aria-expanded', 'true');
                        }
                        $('html,body').animate({scrollTop: $(postid).offset().top - 60}, 1000);
                    }
                    window.hascompleted = true;
                }
            }).catch(() => {
                $('.container :input').prop('disabled', false);
                $('.article').show();
                $('#hsuforum-loading-container').hide();
                $('#hsuforum-overlay-box').hide();
                throw("element did not load in 30 seconds");
            });

                // Register post observers and custom spinner events.
                registerPostsObserver();
                registerSpinnerEvents();
        }
    };
});
