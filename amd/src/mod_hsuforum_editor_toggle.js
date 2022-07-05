define(['jquery'], function($) {
    return {
        init: function(selector) {
            var targetnode;

            // Options for the observer (which mutations to observe)
            const config = {attributes: false, childList: true, subtree: true};

            const observer = new MutationObserver(function(mutationlist) {
                try {
                    for (const mutation of mutationlist) {
                        if (mutation.type === 'childList') {
                            if (mutation.addedNodes.length) {
                                // Iterate over added nodes and check if the wrapper
                                mutation.addedNodes.forEach(function (node) {
                                    // If it is the wrapper, find button and click it
                                    if (node.classList !== undefined && node.classList.contains('hsuforum-reply-wrapper')) {
                                        var button = node.querySelector('.hsuforum-use-advanced');
                                        if (button) {
                                            button.click();

                                            var el = document.getElementById("hiddenadvancededitoreditable");
                                            el.focus();
                                            return;
                                        }
                                    }
                                });
                            }
                        }
                    }
                } catch (e) {
                        // Silently fail as it means that it isn't the correct element
                }
            });

            window.onload = function() {
                // Select the node that will be observed for mutations
                targetnode = document.querySelector(selector);
                // Find any current forum display toggle buttons and click them
                var currentbuttons = document.querySelector('.hsuforum-use-advanced');

                if (currentbuttons) {
                    currentbuttons.click();
                    document.body.scrollTop = document.documentElement.scrollTop = 0;
                }

                // Start observing
                observer.observe(targetnode, config);
            };

            // Dynamically injected cancel button
            $(document).on('click', '.hsuforum-cancel', function() {
                $('.hsuforum-footer-reply .hsuforum-textarea[contenteditable="true"]').text('');
            });

            // Toggle editor button
            $(document).on('click', '.hsuforum-use-advanced', function() {
                if ($(this).parents('.hsuforum-footer-reply').length === 0) {
                    $('.hsuforum-footer-reply .hsuforum-textarea[contenteditable="true"]').text('');
                }
            });
        }
    };
});
