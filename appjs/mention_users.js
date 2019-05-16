/**
 * Mod_hsuforum mobile js library written in vanilla js and ES6. The library needs to be echo'd into the return array 
 * for mobile functions in order for it to work. Vanilla js or ES6 is mandatory in the ammendment of this library because
 * of the angular involvement of the mobile app.
 *
 * @package	mod_hsuforum
 * @author JJ Swanevelder
 */

(function (mobile) {
    mobile.mod_hsuforum = {
        //-----------------------------------//
        // Class helper functions definitions
        //-----------------------------------//

        /**
         * Function to add a new discussion
         * @param {object} that The this object when calling this function.
         * @return {void}
         */
        addDiscussion: (that) => {
            let subject = that.CONTENT_OTHERDATA.discussiontitle;
            let message = that.controls[1].value;
            let groupId = that.CONTENT_OTHERDATA.groupselection;
            let forumId = that.CONTENT_OTHERDATA.forumid;
            let attachments = that.CONTENT_OTHERDATA.files;
            let modal;
            let promise;

            if (!subject) {
                that.CoreUtilsProvider.domUtils.showErrorModal(that.CONTENT_OTHERDATA.errormessages['erroremptysubject'], true);
                return;
            }

            modal = that.CoreUtilsProvider.domUtils.showModalLoading('core.sending', true);
            message = that.CoreTextUtilsProvider.formatHtmlLines(message);

            // Upload draft attachments first if any.
            if (attachments.length) {
                promise = that.CoreFileUploaderProvider.uploadOrReuploadFiles(attachments, 'mod_hsuforum', forumId);
            } else {
                promise = Promise.resolve(1);
            }

            promise.then(draftAreaId => {
                // Try to send it to server.
                let site = that.CoreSitesProvider.getCurrentSite();
                let params = {
                    forumid: forumId,
                    subject: subject,
                    message: message,
                    groupid: groupId,
                    draftid: draftAreaId,
                };
                return site.write('mod_hsuforum_add_discussion', params).then(response => {
                    // Other errors ocurring.
                    if (!response || !response.discussionid) {
                        return Promise.reject(that.CoreWSProvider.createFakeWSError(response.warnings));
                    } else {
                        return response.discussionid;
                    }
                });
            }).then(() => {
                that.NavController.pop();
            }).catch(msg => {
                that.CoreUtilsProvider.domUtils.showErrorModalDefault(msg, 'addon.mod_forum.cannotcreatediscussion', true);
            }).finally(() => {
                modal.dismiss();
            });
        },

        /**
         * Function to build formcontrols for all the advanced editors on the page
         * @param {object} mobile The moodlemobile instance
         * @return {void}
         */
        buildFormControls: (mobile) => {
            mobile.controls = [];
            // 1. Setting up firstpost formcontrol
            if (mobile.CONTENT_OTHERDATA.firstpost !== undefined) {
                mobile.controls[mobile.CONTENT_OTHERDATA.firstpost.id] = mobile.FormBuilder.control('');
            }
        
            // 2. Setting up formcontrol for replies
            if (mobile.CONTENT_OTHERDATA.replies && mobile.CONTENT_OTHERDATA.replies.length) {
                mobile.CONTENT_OTHERDATA.replies.forEach((reply) => {
                    mobile.controls[reply.id] = mobile.FormBuilder.control('');
                });
            }
            // 3. Setting up formcontrol for an add discussion page
            if (mobile.CONTENT_OTHERDATA.firstpost == undefined && mobile.CONTENT_OTHERDATA.replies == undefined) {
                mobile.controls[1] = mobile.FormBuilder.control('');
            }
        },


        //-------------------//
        // Init declarations
        //-------------------//

        /**
         * Initialisation for the discussions page.
         * @param {object} mobile The moodlemobile instance
         * @return {void}
         */
        addDiscussionInit: function(mobile) {
            window.addDiscussion = function() {
                mobile.mod_hsuforum.addDiscussion(mobile);
            };
        },

        /**
         * Function to initialize formcontrols for all pages
         * @param {object} mobile The moodlemobile instance
         * @return {void}
         */
        formControlInit: function(mobile) {
            this.buildFormControls(mobile);
        },
    };


    //------------------------------------------------//
    // Inits being called based on page variables set.
    //------------------------------------------------//

    switch(mobile.CONTENT_OTHERDATA.page) { 
        case 'add_discussion': { 
            mobile.mod_hsuforum.addDiscussionInit(mobile);
        }
        // Case for if page is undefined or inits that should run on all pages
        default: { 
            mobile.mod_hsuforum.formControlInit(mobile);
            break; 
        }
    }
})(this);

// @TODO - Convert below legacy code into class based definitions as above. Then rename file to app_pageload.js 
// so it makes more sense to distinguish between app_init and app_pageload
/* ------------------------------------------------------------------------------------------- /
    MAIN FUNCTIONS
    1. Set window object with a usable click function ("activate_mention_users") then call init() on click for that func
    2. Call init function to initialise the store and the ion-card that has been clicked on
    3. If on a new discussion page we call the init function on load
/* ------------------------------------------------------------------------------------------ */
setTimeout(() => {
    // Check if on new discussion page
    let new_discussion = document.getElementById('cardid_newdiscussion');
    if (new_discussion !== null) {
        init('newdiscussion');
    }

    // Set window with a callable proto function
    window.activate_mention_users = e => {
        init(e.target.id);
    };
});

function init(ref) {
    let postref = ref;
    if (postref && (postref > 0 || postref == "newdiscussion")) {
        // Setting up store(data structure)
        if (window.filterSearch == undefined) window.filterSearch = [];
        if (postref && window.filterSearch[postref] == undefined) window.filterSearch[postref] = {};
    }

    // Get ion card wrapper
    let ion_card = document.getElementById('cardid_' + postref);
    if (ion_card !== undefined) {
        // Setup event listeners for: textarea, mention_users and cancel element.
        setup_event_listeners(ion_card, postref);
    }
}

/* ----------------- */
 // Helper functions *
/* ----------------- */

// Function to setup event listeners for all elements needed on ion-card
function setup_event_listeners(ion_card, postref) {
    setTimeout(() => { 
    // Track textarea changes in data structure
        let textarea = (ion_card !== null) ? ion_card.querySelector('div[contenteditable="true"]') : null;
        if (textarea !== null) {
                textarea.addEventListener("input", e => { 
                    // Populating store with textarea
                    window.filterSearch[postref].textarea = e.target.innerHTML;
                });

    // Track click event on "mention user" button
            let mention_user_button = ion_card.querySelector('.mention-user');
            mention_user_button.addEventListener('touchstart', e => {
                reset_filter_elements(postref);
                toggle_mention_user_element(ion_card);
                replaceSelectionWithHtml("<span id=caret_pos></span>", ion_card);
                window.filterSearch[postref].search_input.value = " ";
            })

    // Track input changes to filter the list
            let search_element = get_mention_user_input(ion_card, postref);
            if (search_element) {
                search_element.addEventListener('input', e => {
                    // Populating store with searchstring
                    window.filterSearch[postref].search_string = e.target.value.toLowerCase();
                    filter_list(ion_card, postref);
                });
            }

    // Track cancel button on input search field
            let input_cancel_button = ion_card.querySelector('button.searchbar-clear-icon');
            if (input_cancel_button !== undefined) {
                input_cancel_button.addEventListener('touchstart', e => {
                    reset_filter_elements(postref);
                });
            }

    // Track filter element(filtered user) click event
            let filter_elements = get_filter_elements(ion_card, postref);
            if (filter_elements.length) {
                filter_elements.forEach( filter_element => {
                    filter_element.addEventListener('click', e => {
                        mention_user(filter_element, ion_card, postref);
                    });
                });
            }
        }
    });
}

/* ----------------------------------------------------- */
 // Helper functions for event listeners - Logic Section *
/* ----------------------------------------------------- */

// Function to return mention user search input element
function get_mention_user_input(ion_card, postref) {
    let mention_user_input = ion_card.querySelector('.searchfilter input');
    if (mention_user_input !== undefined) {
        // Populating store with filter list
        window.filterSearch[postref].search_input = mention_user_input;
        return mention_user_input;
    }
    return false;
}


// Function to return filter elements
function get_filter_elements(ion_card, postref) {
    let filter_list = ion_card.getElementsByClassName('filter_list');
    if (filter_list.length) {
        // Populating store with filter list
        window.filterSearch[postref].filter_elements = filter_list[0].querySelectorAll('ion-item');
        return filter_list[0].querySelectorAll('ion-item');
    }
    return false;
}


// Function to filter the currently active user list
function filter_list(ion_card, postref) {
    let filter_elements = get_filter_elements(ion_card, postref);
    // Clear possible hidden attributes on filter elements
    reset_filter_elements(postref);
    // Loop through list to check for value against list items.
    filter_elements.forEach( filter_element => {
        let element_innerhtml = filter_element.querySelector('ion-label').innerHTML.toLowerCase();
        if (element_innerhtml.indexOf(window.filterSearch[postref].search_string) == -1) {
            filter_element.style.display = "none";
        }
    });
}


// Function to remove filter element styles attributes
function reset_filter_elements(postref) {
    if (window.filterSearch[postref].filter_elements) {
        window.filterSearch[postref].filter_elements.forEach( filter_element => {
            filter_element.style.display = "block";
        });
    }
}


// Function toggle the whole mention user element
function toggle_mention_user_element(ion_card) {
    let mention_user_element = ion_card.querySelector('.searchfilter');
    mention_user_element.style.display = mention_user_element.style.display == "none" ?  "block" : "none";
}


/**
 * Function to mention a user.
 * @param HTMLObjectElement user_element
 * @param HTMLObjectElement ion_card
 * 
 * How this works:
 * a) The text area will already have a placeholder span element with id 'caret_pos' that we can target (replaceSelectionWithHtml())
 * b) We will replace the caret_pos span to the link string.
 * c) With the replacement to the new mentioned user link we will add a trailing span with id 'last_insert'
 * d) The trailing span 'last_insert' will be used to position caret and focus on the element.
 */
function mention_user(user_element, ion_card) {
    let profile_string = user_element.querySelector('ion-label').innerHTML;
    let link_string = '<a href=mobileappuser/view.php?id=' + user_element.id + ' userid="' + user_element.id + '">' + profile_string + '</a><span id="last_insert">&nbsp;</span>';
    let mention_textarea = ion_card.querySelector('div[contenteditable="true"]');

    // Replace dummy pan with link_string
    let regex = /<span id="caret_pos"><\/span>/;
    let new_text = mention_textarea.innerHTML.replace(regex, link_string);
    mention_textarea.innerHTML = new_text;

    // Focus element
    let focusnode = mention_textarea.querySelector('span#last_insert');
    setCaret(focusnode, mention_textarea, 0);

    // Deleting node identifier
    mention_textarea.querySelector('span#last_insert').removeAttribute('id');

    // Trigger key up to for template bind that is happening ((keyup)="CONTENT_OTHERDATA.sectionbody = $event.target.innerHTML")
    triggerEvent(mention_textarea, 'keyup');

    // Close mention user element
    toggle_mention_user_element(ion_card);
}

// Function to trigger events
function triggerEvent(el, type){
    if ('createEvent' in document) {
         var e = document.createEvent('HTMLEvents');
         e.initEvent(type, false, true);
         el.dispatchEvent(e);
     } else {
         var e = document.createEventObject();
         e.eventType = type;
         el.fireEvent('on'+e.eventType, e);
     }
 }

 // Function to insert dummy span with id to track where to insert new html
function replaceSelectionWithHtml(html, ion_card) {
    let mention_textarea = ion_card.querySelector('div[contenteditable="true"]');
    let range;

    if (mention_textarea.innerHTML.length) {
        range = window.getSelection().getRangeAt(0);
        range.deleteContents();
        let div = document.createElement("div");
        div.innerHTML = html;
        let frag = document.createDocumentFragment(), child;
        while ( (child = div.firstChild) ) {
            frag.appendChild(child);
        }
        range.insertNode(frag);
    } else {
        mention_textarea.innerHTML = html;
    }
}

// Function to set caret and focus
function setCaret(caret_element, focus_element, pos) {
    let range = document.createRange();
    let sel = window.getSelection();

    range.setStart(caret_element, pos);
    range.collapse(true);
    sel.removeAllRanges();
    sel.addRange(range);
    focus_element.focus({preventScroll:false});
}
