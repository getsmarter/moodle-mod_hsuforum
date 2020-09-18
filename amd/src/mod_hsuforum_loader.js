define(['jquery'], function ($) {

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


    return {
        init: function () {
            $('.article :input').attr('disabled', true);
            $('.hsuforum-menu :input').attr('disabled', true);

            waitForElement("body", 30000).then(function() {
                $('.article :input').removeAttr('disabled');
                $('.hsuforum-menu :input').removeAttr('disabled');
                $('.article').show();
                $('#loading-container').hide();
            }).catch(() => {
                alert("element did not load in 30 seconds");
            });

        }
    };
});
