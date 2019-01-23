/**
 * Mod_hsuforum mobile tag js library written in vanilla js. The library needs to be echo'd into the return array 
 * for mobile functions in order for it to work. Vanilla js is mandatory in the ammendment of this library because
 * of the angular involvement of the mobile app.
 *
 * @package	mod_hsuforum
 * @author JJ Swanevelder
 */

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
    // @TODO - Setup caret tracking if needed. For now append the text
        let textarea = (ion_card !== null) ? ion_card.querySelector('div[contenteditable="true"]') : null;
        if (textarea !== null) {
// console.log(this.CONTENT_OTHERDATA);
                textarea.addEventListener("input", e => { 
                    // Populating store with textarea
                    window.filterSearch[postref].textarea = e.target.innerHTML;
                });

    // Track click event on "mention user" button
            let mention_user_button = ion_card.querySelector('.mention-user');
            mention_user_button.addEventListener('touchstart', e => {
                reset_filter_elements(postref);
                toggle_mention_user_element(ion_card);
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
                    filter_element.addEventListener('touchstart', e => {
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


// Function toggle the whole mention user element
function mention_user(user_element, ion_card, postref) {
    let profile_string = user_element.querySelector('ion-label').innerHTML;
    let link_string = '<a href=mobileappuser/view.php?id=' + user_element.id + ' userid="' + user_element.id + '">' + profile_string + '</a>';
    let mention_textarea = ion_card.querySelector('div[contenteditable="true"]');
    let textarea = window.filterSearch[postref].textarea == undefined ? "Mentioned" : window.filterSearch[postref].textarea;

    mention_textarea.innerHTML = textarea + " " + link_string;
    triggerEvent(mention_textarea, 'keyup')
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