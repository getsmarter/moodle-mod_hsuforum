/**
 * Small JS file to add New tag to the parent of any thread with an unread reply in it.
 */
define(['jquery'], function($) {

    return {
        init: function() {
            const unreadparentelements = document.getElementsByClassName('unreadparent');

            // Nice little ECMA 5 script https://stackoverflow.com/questions/679915/how-do-i-test-for-an-empty-javascript-object
            // https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Object/keys#browser_compatibility
            if (Object.keys(unreadparentelements).length > 0) {
                for (let i = 0; i < unreadparentelements.length; i++) {
                    displayunreadpill(unreadparentelements[i])
                }
            }

            function displayunreadpill(unreadparentelements) {
                document.getElementById('hsuforum-new-parent-container-' + unreadparentelements.dataset.unreadparentid).innerHTML = '<span class="hsuforum-unreadcount">New</span>'
            }
        }
    };
});
