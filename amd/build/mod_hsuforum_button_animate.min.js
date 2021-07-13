define(['jquery'], function ($) {
    return {
        init: function (following, unfollow) {

            $('.hsuforum-toggle').not('.hsuforum-toggle-bookmark, .hsuforum-toggle-substantive')
                .hover(function (e) {
                    if (e.currentTarget.classList.contains('hsuforum-toggled')) {
                        if (e.currentTarget.children[0].children[0].innerText == unfollow.toUpperCase()) {
                            e.currentTarget.children[0].children[0].innerText = following
                        } else if (e.currentTarget.children[0].children[0].innerText == following.toUpperCase()) {
                            e.currentTarget.children[0].children[0].innerText = unfollow
                        }
                    }
                })
                .mouseleave(function (e) {
                    if (e.currentTarget.classList.contains('hsuforum-toggled')) {
                        e.currentTarget.children[0].children[0].innerText = following
                    }
                });
        }
    }
})
