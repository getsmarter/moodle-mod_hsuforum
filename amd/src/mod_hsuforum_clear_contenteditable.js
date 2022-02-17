/**
 * hsurofum dicussion paste only tex inside div posting class
 * Small JS file to mark all posts as read.
 */
define(['jquery'], function($) {
    return {
        init: function() {
            $('.posting').on('copy',function (event) {
                event.preventDefault();
                const selection = document.getSelection();
                event.originalEvent.clipboardData.setData('text/plain', selection.toString());
            });
        }
    };
});

