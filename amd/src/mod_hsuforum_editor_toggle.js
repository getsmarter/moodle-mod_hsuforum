define([], function() {
    return {
        init: function(selector) {
            var targetnode;

            // Options for the observer (which mutations to observe)
            const config = { attributes: false, childList: true, subtree: true };

            const observer = new MutationObserver(function(mutationlist, observer) {
                for (const mutation of mutationlist) {
                    try {
                        if (mutation.type === 'childList' && mutation.addedNodes.length) {
                            // Iterate over added nodes and check if the wrapper
                            mutation.addedNodes.forEach(function(node, i) {
                                // If it is the wrapper, find button and click it
                                if (node.classList !== undefined && node.classList.contains('hsuforum-reply-wrapper')) {
                                    var button = node.querySelector('.hsuforum-use-advanced');
                                    if (button) {
                                        button.click();
                                        return;
                                    }
                                }
                            });
                        }
                    } catch (e) {
                        // Silently fail as it means that it isn't the correct element
                    }
                }
            });

            window.onload = function() {
                // Select the node that will be observed for mutations
                targetnode = document.querySelector(selector);
                // Find any current forum display toggle buttons and click them
                var currentbuttons = document.querySelector('.hsuforum-use-advanced');

                if (currentbuttons) {
                    currentbuttons.click();
                }

                // Start observing
                observer.observe(targetnode, config);
            };
        }
    }
})
