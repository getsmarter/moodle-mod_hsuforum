/**
 * Mod_hsuforum mobile tag js library written in vanilla js. The library needs to be echo'd into the return array 
 * for mobile functions in order for it to work. Vanilla js is mandatory in the ammendment of this library because
 * of the angular involvement of the mobile app.
 *
 * @package	mod_hsuforum
 * @author JJ Swanevelder
 */


/* ----------------- */
 // Helper functions *
/* ----------------- */
// Function to reset filter list item styles
function reset_children_styles(elements, child_type) {
    return elements.querySelectorAll(child_type).forEach(function(element) {
        element.style.display = "block";
    });
}


// Function to build profile link
function create_profile_link(text_area_text, profile_string, user_id, textarea_id) {
        let base_url = window.location.href;
        let link_string = "<a href=" + base_url + "user/view.php?id=" + user_id + ">" + profile_string + "</a>";
        let old_textarea_string = text_area_text

        let regex = /@(.*)<span id="caret_pos"><\/span>/;
        let new_text = old_textarea_string.replace(regex, link_string);
        document.getElementById(textarea_id).innerHTML = new_text
}

// Function to check for filter_li_elements
function filter_elements_exist() {
    let result = false;

    let filter_element_container_check = document.querySelector(".tribute-container");
    if (filter_element_container_check != null) {
        let filter_li_elements_check = filter_element_container_check.querySelectorAll("li");
        result = (filter_li_elements_check != null && filter_li_elements_check.length) ? true : false;
    }
    return result;
}

// Function to return filter_li_elements
function return_filter_elements() {
    let filter_li_elements = false;
    if (filter_elements_exist()) {
        let filter_element_container_element = document.querySelector(".tribute-container");
        filter_li_elements = filter_element_container_element.querySelectorAll("li");
    }

    return filter_li_elements;
}

// Function to insert dummy span with id to track where to insert new html
function replaceSelectionWithHtml(html) {
    let range;
    if (window.getSelection && window.getSelection().getRangeAt) {
        range = window.getSelection().getRangeAt(0);
        range.deleteContents();
        let div = document.createElement("div");
        div.innerHTML = html;
        let frag = document.createDocumentFragment(), child;
        while ( (child = div.firstChild) ) {
            frag.appendChild(child);
        }
        range.insertNode(frag);
    } else if (document.selection && document.selection.createRange) {
        range = document.selection.createRange();
        range.pasteHTML(html);
    }
}

// Function to check for dummy span element
function at_span_element_exist() {
    let spancheck = false
    let at_span_element = document.getElementById("caret_pos");
    spancheck = (at_span_element != null) ? true : false;

    return spancheck;
}
/* ------------------------ */
 // End of helper functions *
/* ------------------------ */

function init() {
    // Setting init default vars
    let active_search_id = false;
    let at_position_start = 0;
    let at_position_end = 0;
    let searchstring = "";
    let text_areas = document.querySelectorAll(".js_tagging");
    let filter_element = document.querySelector(".tribute-container");
    let filter_li_elements = false;
    let at_span_element = null;

    /* ------------------------------------------------------------------ */
     // There will only be a filter element if there are tagable students *
    /* ------------------------------------------------------------------ */
    if (filter_elements_exist() && text_areas != null) {
        filter_li_elements = return_filter_elements();

        text_areas.forEach(function(text_area) {
            if (text_area) {
                /* ---------------------------------------------- */
                  // Using input event so that it works on mobile *
                /* ---------------------------------------------- */
                text_area.addEventListener("input", function(e) {
                    if (e.data == "@" && at_span_element == null) {
                        active_search_id = e.target.id;
                        at_position_start = window.getSelection().anchorOffset;

                        // Insert dummy span with id to get position on screen and to replace text with user link.
                        replaceSelectionWithHtml("<span id=caret_pos></span>");
                        at_span_element = document.getElementById("caret_pos");
                    }
                    /* ------------------------------------------------------ */
                      // Position ul filter element to the span dummy element *
                    /* ------------------------------------------------------ */
                    if (active_search_id && at_span_element != null) {
                        filter_element.style.display = "block";
                        if (at_span_element != null) {
                            filter_element.style.top = (at_span_element.offsetTop) + 5 + "px";
                            filter_element.style.left = (at_span_element.getBoundingClientRect().x) - 15 + "px";
                        }
                        at_position_end = window.getSelection().anchorOffset;

                        /* ---------------- */
                         // Filter elements *
                        /* ---------------- */
                        // Dont filter for "@"
                        if (e.data != "@") {
                            if (filter_elements_exist()) {
                                // Handle backspace on search. Input event recognize @ as null
                                if (e.data == null) {
                                    // Reset filter elements to search by new string
                                    reset_children_styles(filter_element, "li");
                                    searchstring = searchstring.substring(0, searchstring.length - 1);
                                } else {
                                    searchstring += e.data.toLowerCase();
                                }
                                filter_li_elements.forEach(function(element) {
                                    let element_text = element.innerHTML.toLowerCase();
                                    if (element_text.indexOf(searchstring) == -1) {
                                        element.style.display = "none";
                                    }
                                });
                            }
                        }

                        /* -------------------------------------------------- */
                        // Remove ul once span dummy element has been removed *
                        /* -------------------------------------------------- */
                        if (at_position_end < at_position_start || e.keyCode == 32) {
                            active_search_id = false;
                            filter_element.style.display = "none";
                            searchstring = "";
                            reset_children_styles(filter_element, "li");
                            if (at_span_element_exist()) {
                                document.getElementById("caret_pos").outerHTML = "";
                                at_span_element = null;
                            }
                        }
                    }
                });
            }
        });

    }

    /* ----------------------------- */
     // Click events for li elements *
    /* ----------------------------- */
    if (filter_elements_exist()) {
        // Events for list items on click
        return_filter_elements().forEach(function(element) {
            element.addEventListener("touchstart", function(e) {
                // Get textarea by active id
                let text_area = document.querySelector("#" + active_search_id);
                if (text_area != null) {
                    create_profile_link(text_area.innerHTML, e.target.innerText, e.target.id, active_search_id);
                }
                // @TODO create destroy function
                active_search_id = false;
                filter_element.style.display = "none";
                searchstring = "";
                reset_children_styles(filter_element, "li");
                at_span_element = null;
            });
        });
    }

}

// Now we can run the init function to initialize tagging on the dom
setTimeout(function() { 
    init();
    /* -------------------------------------------------------------------- */
    // Run init again once click on a reply since angular injects new html *
    /* -------------------------------------------------------------------- */
    let reply_buttons = document.querySelectorAll(".js_reply");
    reply_buttons.forEach(function(button) {
        button.addEventListener("touchstart", function(e) {
            setTimeout(function() {
                init();
                }, 100);
        });
    });
});