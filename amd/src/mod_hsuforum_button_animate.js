define(['jquery'], function ($) {
    return {
        init: function (unfollow, following) {

            $('.hsuforum-toggled .subscriber-wrapper .trigger-subscribe').mouseenter(function (e) {
                e.target.innerText = following
            })

            $('.hsuforum-toggled .subscriber-wrapper .trigger-subscribe').mouseleave(function (e) {
                e.target.innerText = unfollow
            })
        }
    }   
})
