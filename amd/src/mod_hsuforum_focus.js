/**
 * Small function that ties in with bootsrap collapse event classes to scroll a element
 * into view if not on the viewport
 */
 define(['jquery'], function($) {
 
    return {
        init: function() {
            window.hascompleted = false;
            var postid = window.location.hash
            if (!window.hascompleted) {
                if (postid) {
                    if ($(postid).closest('li').data('depth') > 0) {
                        $(postid).closest('.posts-collapse-container').addClass('show');
                    }
                    $('html,body').animate({scrollTop: $(postid).offset().top - 53}, 1000);
                }
                window.hascompleted = true;
            }

            $('.posts-collapse-container').on('hide.bs.collapse', function() {
                // Collapsable container id
                let id = $(this)[0].id;
                if (id !== undefined) {
                    let collapseTarget = $(`.collapse-top[data-target="#${id}"]`);
                    let parentPost = $(collapseTarget)[0].parentElement;

                    if (parentPost !== undefined) {
                        let position =  parentPost.getBoundingClientRect();

                        // Only scroll if element is not in viewport
                        if (position.y < 0) {
                            let parentId = `#${$(parentPost)[0].id}`;
                            // Only scroll once collapse event is done
                            $(`#${id}.posts-collapse-container`).on('hidden.bs.collapse', function() {
                                let yAxis = $(parentId).offset().top - 80;
                                // Smoothing out the animation
                                $([document.documentElement, document.body]).animate({
                                    scrollTop: yAxis
                                }, 500);
                            })
                        }
                    }
                }
            });
        }
    };
});