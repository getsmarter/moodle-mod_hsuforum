/**
 * hsurofum dicussion paste only tex inside div posting class
 * Small JS file to mark all posts as read.
 */
define(['jquery'], function($) {
    const copyText = (event) => {
        // eslint-disable-next-line no-console
        console.log(event);
        document.getSelection();
        event.clipboardData.setData('text/plain');
        event.preventDefault();
    };
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

