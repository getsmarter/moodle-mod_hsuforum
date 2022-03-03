/**
 * Small function that ties in with bootsrap collapse event classes to scroll a element
 * into view if not on the viewport
 */
define(['jquery'], function($) {
    return {
        init: function() {
            $('body').on('click', '.hsuforum-cancel', function() {
                $('.hsuforum-add-discussion input').focus();
            });

            // // Force focus on clicked element
            // $('body').on('click', '.hsuforum-tools a.like', function() {
            //     let id = $(this).attr('id');
            //     document.body.addEventListener("spinnerStopEvent", () => {
            //         $('#' + id).focus();
            //     });
            //     // $('#' + id).focus();
            // });
        }
    };
});
