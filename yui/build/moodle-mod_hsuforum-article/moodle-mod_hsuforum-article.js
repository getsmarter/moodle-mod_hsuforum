YUI.add('moodle-mod_hsuforum-article', function (Y, NAME) {

var CSS = {
        DISCUSSION_EDIT: 'hsuforum-thread-edit',
        DISCUSSION_EXPANDED: 'hsuforum-thread-article-expanded',
        POST_EDIT: 'hsuforum-post-edit'
    },
    SELECTORS = {
        ADD_DISCUSSION: '#newdiscussionform input[type=submit]',
        ADD_DISCUSSION_TARGET: '.hsuforum-add-discussion-target',
        ALL_FORMS: '.hsuforum-reply-wrapper form',
        CONTAINER: '.mod-hsuforum-posts-container',
        CONTAINER_LINKS: '.mod-hsuforum-posts-container a',
        DISCUSSION: '.hsuforum-thread',
        DISCUSSIONS: '.hsuforum-threads-wrapper',
        DISCUSSION_EDIT: '.' + CSS.DISCUSSION_EDIT,
        DISCUSSION_BY_ID: '.hsuforum-thread[data-discussionid="%d"]',
        DISCUSSION_COUNT: '.hsuforum-discussion-count',
        DISCUSSION_TARGET: '.hsuforum-new-discussion-target',
        DISCUSSION_TEMPLATE: '#hsuforum-discussion-template',
        DISCUSSION_VIEW: '.hsuforum-thread-view',
        EDITABLE_MESSAGE: '[contenteditable]',
        FORM: '.hsuforum-form',
        FORM_ADVANCED: '.hsuforum-use-advanced',
        FORM_REPLY_WRAPPER: '.hsuforum-reply-wrapper',
        INPUT_FORUM: 'input[name="forum"]',
        INPUT_MESSAGE: 'textarea[name="message"]',
        INPUT_REPLY: 'input[name="reply"]',
        INPUT_SUBJECT: 'input[name="subject"]',
        LINK_CANCEL: '.hsuforum-cancel',
        NO_DISCUSSIONS: '.forumnodiscuss',
        NOTIFICATION: '.hsuforum-notification',
        OPTIONS_TO_PROCESS: '.hsuforum-options-menu.unprocessed',
        PLACEHOLDER: '.thread-replies-placeholder',
        POSTS: '.hsuforum-thread-replies',
        POST_BY_ID: '.hsuforum-post-target[data-postid="%d"]',
        POST_EDIT: '.' + CSS.POST_EDIT,
        POST_TARGET: '.hsuforum-post-target',
        RATE: '.forum-post-rating',
        RATE_POPUP: '.forum-post-rating a',
        REPLY_TEMPLATE: '#hsuforum-reply-template',
        SEARCH_PAGE: '#page-mod-hsuforum-search',
        VALIDATION_ERRORS: '.hsuforum-validation-errors',
        VIEW_POSTS: '.hsuforum-view-posts'
    },
    EVENTS = {
        DISCUSSION_CREATED: 'discussion:created',
        DISCUSSION_DELETED: 'discussion:deleted',
        FORM_CANCELED: 'form:canceled',
        POST_CREATED: 'post:created',
        POST_DELETED: 'post:deleted',
        POST_UPDATED: 'post:updated'
    };

M.mod_hsuforum = M.mod_hsuforum || {};
/**
 * DOM Updater
 *
 * @module moodle-mod_hsuforum-dom
 */

/**
 * Handles updating forum DOM structures.
 *
 * @constructor
 * @namespace M.mod_hsuforum
 * @class Dom
 * @extends Y.Base
 */
function DOM() {
    DOM.superclass.constructor.apply(this, arguments);
}

DOM.NAME = 'moodle-mod_hsuforum-dom';

DOM.ATTRS = {
    /**
     * Used for requests
     *
     * @attribute io
     * @type M.mod_hsuforum.Io
     * @required
     */
    io: { value: null }
};

Y.extend(DOM, Y.Base,
    {
        /**
         * Flag currently displayed rating widgets as processed
         * and initialize existing menus.
         *
         * @method initializer
         */
        initializer: function() {
            // Any ratings initially on the page will already be processed.
            Y.all(SELECTORS.RATE).addClass('processed');
            // Initialize current menu options.
            this.initOptionMenus();
        },

        /**
         * Initialize thread JS features that are not handled by
         * delegates.
         *
         * @method initFeatures
         */
        initFeatures: function() {
            this.initOptionMenus();
            this.initRatings();
        },

        /**
         * Wire up ratings that have been dynamically added to the page.
         *
         * @method initRatings
         */
        initRatings: function() {
            Y.all(SELECTORS.RATE).each(function(node) {
                if (node.hasClass('processed')) {
                    return;
                }
                M.core_rating.Y = Y;
                node.all('select.postratingmenu').each(M.core_rating.attach_rating_events, M.core_rating);
                node.all('input.postratingmenusubmit').setStyle('display', 'none');
                node.addClass('processed');
            });
        },

        /**
         * Initialize option menus.
         *
         * @method initOptionMenus
         */
        initOptionMenus: function() {
            Y.all(SELECTORS.OPTIONS_TO_PROCESS).each(function(node) {
                node.removeClass('unprocessed');

                var menu = new Y.YUI2.widget.Menu(node.generateID(), { lazyLoad: true });

                // Render to container otherwise tool region gets wonky huge!
                menu.render(Y.one(SELECTORS.CONTAINER).generateID());

                Y.one('#' + node.getData('controller')).on('click', function(e) {
                    e.preventDefault();
                    menu.cfg.setProperty('y', e.currentTarget.getY() + e.currentTarget.get('offsetHeight'));
                    menu.cfg.setProperty('x', e.currentTarget.getX());
                    menu.show();
                });
            });
        },

        /**
         * For dynamically loaded ratings, we need to handle the view
         * ratings pop-up manually.
         *
         * @method handleViewRating
         * @param e
         */
        handleViewRating: function(e) {
            if (e.currentTarget.ancestor('.helplink') !== null) {
                return; // Ignore help link.
            }
            e.preventDefault();
            openpopup(e, {
                url: e.currentTarget.get('href') + '&popup=1',
                name: 'ratings',
                options: "height=400,width=600,top=0,left=0,menubar=0,location=0," +
                    "scrollbars,resizable,toolbar,status,directories=0,fullscreen=0,dependent"
            });
        },

        /**
         * Mark a post as read
         *
         * @method markPostAsRead
         * @param {Integer} postid
         * @param {Function} fn
         * @param {Object} context Specifies what 'this' refers to.
         */
        markPostAsRead: function(postid, fn, context) {
            this.get('io').send({
                postid: postid,
                action: 'markread'
            }, fn, context);
        },

        /**
         * Can change the discussion count displayed to the user.
         *
         * Method name is misleading, you can also decrement it
         * by passing negative numbers.
         *
         * @method incrementDiscussionCount
         * @param {Integer} increment
         */
        incrementDiscussionCount: function(increment) {
            // Update number of discussions.
            var countNode = Y.one(SELECTORS.DISCUSSION_COUNT);
            if (countNode !== null) {
                // Increment the count and update display.
                countNode.setData('count', parseInt(countNode.getData('count'), 10) + increment);
                countNode.setHTML(M.util.get_string('xdiscussions', 'mod_hsuforum', countNode.getData('count')));
            }
        },

        /**
         * Display a notification
         *
         * @method displayNotification
         * @param {String} html
         */
        displayNotification: function(html) {
            var node = Y.Node.create(html);
            Y.one(SELECTORS.NOTIFICATION).append(node);

            setTimeout(function() {
                node.remove(true);
            }, 10000);
        },

        /**
         * Displays a notification from an event
         *
         * @method handleNotification
         * @param e
         */
        handleNotification: function(e) {
            if (Y.Lang.isString(e.notificationhtml) && e.notificationhtml.trim().length > 0) {
                this.displayNotification(e.notificationhtml);
            }
        },

        /**
         * Update discussion HTML
         *
         * @method handleUpdateDiscussion
         * @param e
         */
        handleUpdateDiscussion: function (e) {
            // Put date fields back to original place in DOM.
            var node = Y.one('#discussionsview');
            if (node) {
                // We are viewing all disussions on one page (view.php).
                node.setHTML(e.html);
            } else {
                // We are viewing a single discussion with the replies underneath.
                node = Y.one(SELECTORS.DISCUSSION_BY_ID.replace('%d', e.discussionid));
                if (node) {
                    // Updating existing discussion.
                    node.replace(e.html);
                    this.initRatings();
                } else {
                    // Adding new discussion.
                    Y.one(SELECTORS.DISCUSSION_TARGET).insert(e.html, 'after');
                }
            }
        },

        /**
         * Discussion created event handler
         *
         * Some extra tasks needed on discussion created
         *
         * @method handleDiscussionCreated
         */
        handleDiscussionCreated: function() {
            // Remove no discussions message if on the page.
            if (Y.one(SELECTORS.NO_DISCUSSIONS)) {
                Y.one(SELECTORS.NO_DISCUSSIONS).remove();
            }
            location.reload();
        },

        /**
         * Either redirect because we are viewing a single discussion
         * that has just been deleted OR remove the discussion
         * from the page and update navigation links on the
         * surrounding discussions.
         *
         * @method handleDiscussionDeleted
         * @param e
         */
        handleDiscussionDeleted: function(e) {
            var node = Y.one(SELECTORS.POST_BY_ID.replace('%d', e.postid));
            if (node === null || !node.hasAttribute('data-isdiscussion')) {
                return;
            }
            if (Y.one(SELECTORS.DISCUSSIONS)) {
                node.remove(true);
                this.incrementDiscussionCount(-1);
                Y.one(SELECTORS.DISCUSSION_COUNT).focus();
            } else {
                // Redirect because we are viewing a single discussion.
                window.location.href = e.redirecturl;
            }
        }
    }
);

M.mod_hsuforum.Dom = DOM;
/**
 * Forum Router
 *
 * @module moodle-mod_hsuforum-router
 */

/**
 * Handles URL routing
 *
 * @constructor
 * @namespace M.mod_hsuforum
 * @class Router
 * @extends Y.Router
 */
var ROUTER = Y.Base.create('hsuforumRouter', Y.Router, [], {
    /**
     *
     * @method initializer
     */
    initializer: function() {
    },

    /**
     * View a discussion
     *
     * @method discussion
     * @param {Object} req
     */
    discussion: function(req) {
        this.get('article').viewDiscussion(req.query.d, req.query.postid);
    },

    /**
     * Post editing
     *
     * @method post
     * @param {Object} req
     */
    post: function(req) {
        if (!Y.Lang.isUndefined(req.query.reply)) {
            this.get('article').get('form').showReplyToForm(req.query.reply);
        } else if (!Y.Lang.isUndefined(req.query.forum)) {
            this.get('article').get('form').showAddDiscussionForm(req.query.forum);
        } else if (!Y.Lang.isUndefined(req.query['delete'])) {
            this.get('article').confirmDeletePost(req.query['delete']);
        } else if (!Y.Lang.isUndefined(req.query.edit)) {
            this.get('article').get('form').showEditForm(req.query.edit);
        } else if (!Y.Lang.isUndefined(req.query.prune)) {
            window.location.href = req.url;
        }
    },

    /**
     * Focus hashed element.
     *
     * @param el
     */
    focusHash: function(el) {
        var ta = el.get('href').split('#');
        // Without this timeout it doesn't always focus on the desired element.
        setTimeout(function(){
            try {
                Y.one('#' + ta[1]).ancestor('li').focus();
            } catch (err) {
            }
        },300);
    },


    /**
     * Handles routing of link clicks
     *
     * @param e
     */
    handleRoute: function(e) {
        // Don't allow atto menu editor dropdown menu do any routing (else the editor gets toggled and form hidden).
        if (e.currentTarget.getAttribute('role') == 'menuitem') {
            if (e.currentTarget.get('href').indexOf('#') >-1){
                return;
            }
        }

        // Allow the native behavior on middle/right-click, or when Ctrl or Command are pressed.
        if (e.button !== 1 || e.ctrlKey || e.metaKey
            || e.currentTarget.hasClass('disable-router')
            || e.currentTarget.hasClass('autolink')
            || e.currentTarget.ancestor('.posting')
        ) {
            if (e.currentTarget.get('href').indexOf('#') >-1){
                this.focusHash(e.currentTarget);
            }
            return;
        }
        // Whenever a route takes us somewhere else we need to move the editor back to its original container.
        M.mod_hsuforum.restoreEditor();

        if (this.routeUrl(e.currentTarget.get('href'))) {
            e.preventDefault();
        }
    },

    /**
     * Route a URL if possible
     *
     * @method routeUrl
     * @param {String} url
     * @returns {boolean}
     */
    routeUrl: function(url) {
        if (this.hasRoute(url)) {
            this.save(this.removeRoot(url));
            return true;
        }
        return false;
    },

    /**
     * Add discussion button handler
     *
     * @method handleAddDiscussionRoute
     * @param e
     */
    handleAddDiscussionRoute: function(e) {
        e.preventDefault();

        // Put editor back to its original place in DOM.
        M.mod_hsuforum.restoreEditor();

        if (typeof(e.currentTarget) === 'undefined') {
            // Page possiibly hasn't finished loading.
            return;
        }

        var formNode = e.currentTarget.ancestor('form'),
            forumId  = formNode.one(SELECTORS.INPUT_FORUM).get('value');

        this.save(formNode.get('action') + '?forum=' + forumId);
    },

    /**
     * Update route to view the discussion
     *
     * Usually done after the discussion was added
     * or updated.
     *
     * @method handleViewDiscussion
     * @param e
     */
    handleViewDiscussion: function(e) {
        var path = '/discuss.php?d=' + e.discussionid;
        if (!Y.Lang.isUndefined(e.postid)) {
            path = path + '&postid=' + e.postid;
        }
        this.save(path);
    },

    /**
     * Middleware: before executing a route, hide
     * all of the open forms.
     *
     * @method hideForms
     * @param req
     * @param res
     * @param next
     */
    hideForms: function(req, res, next) {
        this.get('article').get('form').restoreDateFields();
        this.get('article').get('form').removeAllForms();
        next();
    }
}, {
    ATTRS: {
        /**
         * Used for responding to routing actions
         *
         * @attribute article
         * @type M.mod_hsuforum.Article
         * @required
         */
        article: { value: null },

        /**
         * Root URL
         *
         * @attribute root
         * @type String
         * @default '/mod/hsuforum'
         * @required
         */
        root: {
            valueFn: function() {
                return M.cfg.wwwroot.replace(this._regexUrlOrigin, '')+'/mod/hsuforum';
            }
        },

        /**
         * Default routes
         *
         * @attribute routes
         * @type Array
         * @required
         */
        routes: {
            value: [
                { path: '/view.php', callbacks: ['hideForms'] },
                { path: '/discuss.php', callbacks: ['hideForms', 'discussion'] },
                { path: '/post.php', callbacks: ['hideForms', 'post'] }
            ]
        }
    }
});

M.mod_hsuforum.Router = ROUTER;
/**
 * Form Handler
 *
 * @module moodle-mod_hsuforum-form
 */

/**
 * Handles the display and processing of several forms, including:
 *  Adding a reply
 *  Adding a discussion
 *
 * @constructor
 * @namespace M.mod_hsuforum
 * @class Form
 * @extends Y.Base
 */
function FORM() {
    FORM.superclass.constructor.apply(this, arguments);
}

FORM.NAME = 'moodle-mod_hsuforum-form';

FORM.ATTRS = {
    /**
     * Used for requests
     *
     * @attribute io
     * @type M.mod_hsuforum.Io
     * @required
     */
    io: { value: null }
};

Y.extend(FORM, Y.Base,
    {
        /**
         * Remove crud from content on paste
         *
         *
         */
        handleFormPaste: function(e) {
            var datastr = '';
            var sel = window.getSelection();

            /**
             * Clean up html - remove attributes that we don't want.
             * @param html
             * @returns {string}
             */
            var cleanHTML = function(html) {
                var cleanhtml = document.createElement('div');
                cleanhtml.innerHTML = html;
                tags = cleanhtml.getElementsByTagName("*");
                for (var i=0, max=tags.length; i < max; i++){
                    tags[i].removeAttribute("id");
                    tags[i].removeAttribute("style");
                    tags[i].removeAttribute("size");
                    tags[i].removeAttribute("color");
                    tags[i].removeAttribute("bgcolor");
                    tags[i].removeAttribute("face");
                    tags[i].removeAttribute("align");
                }
                return cleanhtml.innerHTML;
            };

            var clipboardData = false;
            if (e._event && e._event.clipboardData && e._event.clipboardData.getData){
                // Proper web browsers.
                clipboardData = e._event.clipboardData;
            } else if (window.clipboardData && window.clipboardData.getData){
                // IE11 and below.
                clipboardData = window.clipboardData;
            }

            if (clipboardData) {
                if (clipboardData.types) {
                    // Get data the standard way.
                    if (/text\/html/.test(clipboardData.types)
                        || clipboardData.types.contains('text/html')
                    ) {
                        datastr = clipboardData.getData('text/html');
                    }
                    else if (/text\/plain/.test(clipboardData.types)
                        || clipboardData.types.contains('text/plain')
                    ) {
                        datastr = clipboardData.getData('text/plain');
                    }
                } else {
                    // Get data the IE11 and below way.
                    datastr = clipboardData.getData('Text');
                }
                if (datastr !== '') {
                    if (sel.getRangeAt && sel.rangeCount) {
                        var range = sel.getRangeAt(0);

                        var newnode = document.createElement('p');
                        newnode.innerHTML = cleanHTML(datastr);

                        // Get rid of this node - we don't want it.
                        if (newnode.childNodes[0].tagName === 'META') {
                            newnode.removeChild(newnode.childNodes[0]);
                        }

                        // Get the last node as we will need this to position cursor.
                        var lastnode = newnode.childNodes[newnode.childNodes.length-1];
                        for (var n = 0; n <= newnode.childNodes.length; n++) {
                            var insertnode = newnode.childNodes[newnode.childNodes.length-1];
                            range.insertNode(insertnode);
                        }

                        range.setStartAfter(lastnode);
                        range.setEndAfter(lastnode);

                        sel.removeAllRanges();
                        sel.addRange(range);
                    }

                    if (e._event.preventDefault) {
                        e._event.stopPropagation();
                        e._event.preventDefault();
                    }
                    return false;
                }
            }

            /**
             * This is the best we can do when we can't access cliboard - just stick cursor at the end.
             */
            setTimeout(function() {
                var cleanhtml = cleanHTML(e.currentTarget.get('innerHTML'));

                e.currentTarget.setContent(cleanhtml);

                var range = document.createRange();
                var sel = window.getSelection();

                /**
                 * Get last child of node.
                 * @param el
                 * @returns {*}
                 */
                var getLastChild = function(el){
                    var children = el.childNodes;
                    if (!children){
                        return false;
                    }
                    var lastchild = children[children.length-1];
                    if (!lastchild || typeof(lastchild) === 'undefined') {
                        return el;
                    }
                    // Get last sub child of lastchild
                    var lastsubchild = getLastChild(lastchild);
                    if (lastsubchild && typeof(lastsubchild) !== 'undefined') {
                        return lastsubchild;
                    } else if (lastchild && typeof(lastchild) !== 'undefined') {
                        return lastchild;
                    } else {
                        return el;
                    }
                };

                var lastchild = getLastChild(e.currentTarget._node);
                var lastchildlength = 1;
                if (typeof(lastchild.innerHTML) !== 'undefined') {
                    lastchildlength = lastchild.innerHTML.length;
                } else {
                    lastchildlength = lastchild.length;
                }

                range.setStart(lastchild, lastchildlength);
                range.collapse(true);
                sel.removeAllRanges();
                sel.addRange(range);

            },100);
        },

        handlePostToGroupsToggle: function(e) {
            var formNode = e.currentTarget.ancestor('form');
            var selectNode = formNode.one('#menugroupinfo');
            if (e.currentTarget.get('checked')) {
                selectNode.set('disabled', 'disabled');
            } else {
                selectNode.set('disabled', '');
            }
        },

        handleTimeToggle: function(e) {
            if (e.currentTarget.get('checked')) {
                e.currentTarget.ancestor('.fdate_selector').all('select').set('disabled', '');
            } else {
                e.currentTarget.ancestor('.fdate_selector').all('select').set('disabled', 'disabled');
            }
        },

        /**
         * Displays the reply form for a discussion
         * or for a post.
         *
         * @method _displayReplyForm
         * @param parentNode
         * @private
         */
        _displayReplyForm: function(parentNode) {
            var template    = Y.one(SELECTORS.REPLY_TEMPLATE).getHTML(),
                wrapperNode = parentNode.one(SELECTORS.FORM_REPLY_WRAPPER);

            if (wrapperNode instanceof Y.Node) {
                wrapperNode.replace(template);
            } else {
                parentNode.append(template);
            }
            wrapperNode = parentNode.one(SELECTORS.FORM_REPLY_WRAPPER);

            this.attachFormWarnings();

            // Update form to reply to our post.
            wrapperNode.one(SELECTORS.INPUT_REPLY).setAttribute('value', parentNode.getData('postid'));

            var advNode = wrapperNode.one(SELECTORS.FORM_ADVANCED);
            advNode.setAttribute('href', advNode.getAttribute('href').replace(/reply=\d+/, 'reply=' + parentNode.getData('postid')));

            if (parentNode.hasAttribute('data-ispost')) {
                wrapperNode.one('legend').setHTML(
                    M.util.get_string('replytox', 'mod_hsuforum', parentNode.getData('author'))
                );
            }
        },

        /**
         * Copies the content editable message into the
         * text area so it can be submitted by the form.
         *
         * @method _copyMessage
         * @param node
         * @private
         */
        _copyMessage: function(node) {
            var message = node.one(SELECTORS.EDITABLE_MESSAGE).get('innerHTML');
            node.one(SELECTORS.INPUT_MESSAGE).set('value', message);
        },

        /**
         * Submits a form and handles errors.
         *
         * @method _submitReplyForm
         * @param wrapperNode
         * @param {Function} fn
         * @private
         */
        _submitReplyForm: function(wrapperNode, fn) {
            wrapperNode.all('button').setAttribute('disabled', 'disabled');
            this._copyMessage(wrapperNode);

            // Make sure form has draftid for processing images.
            var fileinputs = wrapperNode.all('form input[type=file]');
            var draftid = Y.one('#hiddenadvancededitordraftid');
            if (draftid) {
                var clonedraftid = draftid.cloneNode();
                clonedraftid.id = 'hiddenadvancededitordraftidclone';
                wrapperNode.one('form input').insert(clonedraftid, 'before');
            }

            this.get('io').submitForm(wrapperNode.one('form'), function(data) {
                // TODO - yuiformsubmit won't work here as the data will already have been sent at this point. The form is the data, the data variable is what comes back
                data.yuiformsubmit = 1; // So we can detect and class this as an AJAX post later!
                if (data.errors === true) {
                    wrapperNode.one(SELECTORS.VALIDATION_ERRORS).setHTML(data.html).addClass('notifyproblem');
                    wrapperNode.all('button').removeAttribute('disabled');
                } else {
                    fn.call(this, data);
                }
            }, this, fileinputs._nodes.length > 0);
        },

        /**
         * All of our forms need to warn the user about
         * navigating away when they have changes made
         * to the form.  This ensures all forms have
         * this feature enabled.
         *
         * @method attachFormWarnings
         */
        attachFormWarnings: function() {
            Y.all(SELECTORS.ALL_FORMS).each(function(formNode) {
                if (!formNode.hasClass('form-checker-added')) {
                    var ChangeChecker = require('core_form/changechecker');
                    var checker = ChangeChecker.watchFormById(formNode.generateID());
                    formNode.addClass('form-checker-added');

                    // On edit of content editable, trigger form change checker.
                    formNode.one(SELECTORS.EDITABLE_MESSAGE).on('keypress', ChangeChecker.markAllFormsAsDirty, checker);
                }
            });
        },

        /**
         * Removes all dynamically opened forms.
         *
         * @method removeAllForms
         */
        removeAllForms: function() {

            Y.all(SELECTORS.POSTS + ' ' + SELECTORS.FORM_REPLY_WRAPPER).each(function(node) {
                // Don't removing forms for editing, for safety.
                if (!node.ancestor(SELECTORS.DISCUSSION_EDIT) && !node.ancestor(SELECTORS.POST_EDIT)) {
                    node.remove(true);
                }
            });

            var node = Y.one(SELECTORS.ADD_DISCUSSION_TARGET);
            if (node !== null) {
                node.empty();
            }
        },

        /**
         * A reply or edit form was canceled
         *
         * @method handleCancelForm
         * @param e
         */
        handleCancelForm: function(e) {
            e.preventDefault();

            // Put date fields back to original place in DOM.
            this.restoreDateFields();

            // Put editor back to its original place in DOM.
            M.mod_hsuforum.restoreEditor();

            var node = e.target.ancestor(SELECTORS.POST_TARGET);
            if (node) {
                node.removeClass(CSS.POST_EDIT)
                    .removeClass(CSS.DISCUSSION_EDIT);
                e.target.ancestor(SELECTORS.FORM_REPLY_WRAPPER).remove(true);
            } else {
                node = e.target.ancestor(SELECTORS.ADD_DISCUSSION_TARGET);
                e.target.ancestor(SELECTORS.FORM_REPLY_WRAPPER).remove(true);
                if (node) {
                    // This is a discussion we were adding and are now cancelling, return.
                    return;
                } else {
                    // We couldn't find a discussion or post target, this is an error, log + return.
                    return;
                }
            }

            // Handle post form cancel.
            this.fire(EVENTS.FORM_CANCELED, {
                discussionid: node.getData('discussionid'),
                postid: node.getData('postid')
            });
        },

        /**
         * Handler for when the form is submitted
         *
         * @method handleFormSubmit
         * @param e
         */
        handleFormSubmit: function(e) {

            e.preventDefault();

            // Put editor back to its original place in DOM.
            M.mod_hsuforum.restoreEditor();

            var wrapperNode = e.currentTarget.ancestor(SELECTORS.FORM_REPLY_WRAPPER);

            this._submitReplyForm(wrapperNode, function(data) {

                // Put date fields back to original place in DOM.
                this.restoreDateFields();

                switch (data.eventaction) {
                    case 'postupdated':
                        this.fire(EVENTS.POST_UPDATED, data);
                        break;
                    case 'postcreated':
                        this.fire(EVENTS.POST_UPDATED, data);
                        break;
                    case 'discussioncreated':
                        this.fire(EVENTS.DISCUSSION_CREATED, data);
                        break;
                }
            });
        },

        /**
         * Show a reply form for a given post
         *
         * @method showReplyToForm
         * @param postId
         */
        showReplyToForm: function(postId) {
            var postNode = Y.one(SELECTORS.POST_BY_ID.replace('%d', postId));

            if (postNode.hasAttribute('data-ispost')) {
                this._displayReplyForm(postNode);
            }
            postNode.one(SELECTORS.EDITABLE_MESSAGE).focus();
        },

        /**
         * Set individual date restriction field
         *
         * @param {string} field
         * @param {bool} enabled
         * @param {int} timeuts
         */
        setDateField: function(field, enabled, timeuts) {
            var dt = new Date(timeuts * 1000),
                dd = dt.getDate(),
                mm = dt.getMonth()+1,
                yyyy = dt.getFullYear();

            if (enabled) {
                Y.one('#id_time' + field + '_enabled').set('checked', 'checked');
            } else {
                Y.one('#id_time' + field + '_enabled').removeAttribute('checked');
            }
            Y.one('#id_time'+field+'_day').set('value', dd);
            Y.one('#id_time'+field+'_month').set('value', mm);
            Y.one('#id_time'+field+'_year').set('value', yyyy);

            this.setDateFieldsClassState();
        },

        /**
         * Reset individual date field.
         * @param field
         */
        resetDateField: function(field) {
            if (!Y.one('#discussion_dateform fieldset')) {
                return;
            }

            var nowuts = Math.floor(Date.now() / 1000);

            this.setDateField(field, false, nowuts);
        },

        /**
         * Reset values of date fields to today's date and remove enabled status if required.
         */
        resetDateFields: function() {
            var fields = ['start', 'end'];

            for (var f in fields) {
                this.resetDateField(fields[f]);
            }
        },

        /**
         * Apply disabled state if necessary.
         */
        setDateFieldsClassState: function() {
            var datefs = Y.one('fieldset.dateform_fieldset');
            if (!datefs) {
                return;
            }
            // Set initial toggle state for date fields.
            datefs.all('.fdate_selector').each(function(el){
                if (el.one('input').get('checked')) {
                    el.all('select').set('disabled', '');
                } else {
                    el.all('select').set('disabled', 'disabled');
                }
            });
        },

        /**
         * Add date fields to current date form target.
         */
        applyDateFields: function() {

            var datefs = Y.one('#discussion_dateform fieldset');
            if (!datefs) {
                return;
            }
            datefs.addClass('dateform_fieldset');
            datefs.removeClass('hidden');
            // Remove legend if present
            if (datefs.one('legend')) {
                datefs.one('legend').remove();
            }

            Y.one('.dateformtarget').append(datefs);
            // Stop calendar button from routing.
            Y.all('.dateformtarget .fitem_fdate_selector a').addClass('disable-router');

            this.setDateFieldsClassState();
        },

        /**
         * Set date fields.
         *
         * @param int startuts
         * @param int enduts
         */
        setDateFields: function(startuts, enduts) {
            if (startuts == 0) {
                this.resetDateField('start');
            } else {
                this.setDateField('start', true, startuts);
            }
            if (enduts == 0) {
                this.resetDateField('end');
            } else {
                this.setDateField('end', true, enduts);
            }
        },

        /**
         * Put date fields back to where they were.
         *
         * @method restoreDateFields
         */
        restoreDateFields: function () {
            if (Y.one('#discussion_dateform')) {
                Y.one('#discussion_dateform').append(Y.one('.dateform_fieldset'));
            }
        },

        /**
         * Show the add discussion form
         *
         * @method showAddDiscussionForm
         */
        showAddDiscussionForm: function() {
            Y.one(SELECTORS.ADD_DISCUSSION_TARGET)
                .setHTML(Y.one(SELECTORS.DISCUSSION_TEMPLATE).getHTML())
                .one(SELECTORS.INPUT_SUBJECT)
                .focus();

            this.resetDateFields();
            this.applyDateFields();
            this.attachFormWarnings();
        },

        /**
         * Display editing form for a post or discussion.
         *
         * @method showEditForm
         * @param {Integer} postId
         */
        showEditForm: function(postId) {
            var postNode = Y.one(SELECTORS.POST_BY_ID.replace('%d', postId));
            if (postNode.hasClass(CSS.DISCUSSION_EDIT) || postNode.hasClass(CSS.POST_EDIT)) {
                postNode.one(SELECTORS.EDITABLE_MESSAGE).focus();
                return;
            }
            var self = this;
            var draftid = Y.one('#hiddenadvancededitordraftid');
            this.get('io').send({
                discussionid: postNode.getData('discussionid'),
                postid: postNode.getData('postid'),
                draftid: draftid ? draftid.get('value') : 0,
                action: 'edit_post_form'
            }, function(data) {
                postNode.prepend(data.html);

                if (postNode.hasAttribute('data-isdiscussion')) {
                    postNode.addClass(CSS.DISCUSSION_EDIT);
                } else {
                    postNode.addClass(CSS.POST_EDIT);
                }
                postNode.one(SELECTORS.EDITABLE_MESSAGE).focus();

                if (data.isdiscussion) {
                    self.applyDateFields();
                    self.setDateFields(data.timestart, data.timeend);
                }

                this.attachFormWarnings();
            }, this);
        }
    }
);

M.mod_hsuforum.Form = FORM;
/**
 * Forum Article View
 *
 * @module moodle-mod_hsuforum-article
 */

/**
 * Handles updating forum article structure
 *
 * @constructor
 * @namespace M.mod_hsuforum
 * @class Article
 * @extends Y.Base
 */
function ARTICLE() {
    ARTICLE.superclass.constructor.apply(this, arguments);
}

ARTICLE.NAME = NAME;

ARTICLE.ATTRS = {
    /**
     * Current context ID, used for AJAX requests
     *
     * @attribute contextId
     * @type Number
     * @default undefined
     * @required
     */
    contextId: { value: undefined },

    /**
     * Used for REST calls
     *
     * @attribute io
     * @type M.mod_hsuforum.Io
     * @readOnly
     */
    io: { readOnly: true },

    /**
     * Used primarily for updating the DOM
     *
     * @attribute dom
     * @type M.mod_hsuforum.Dom
     * @readOnly
     */
    dom: { readOnly: true },

    /**
     * Used for routing URLs within the same page
     *
     * @attribute router
     * @type M.mod_hsuforum.Router
     * @readOnly
     */
    router: { readOnly: true },

    /**
     * Displays, hides and submits forms
     *
     * @attribute form
     * @type M.mod_hsuforum.Form
     * @readOnly
     */
    form: { readOnly: true },

    /**
     * Maintains an aria live log.
     *
     * @attribute liveLog
     * @type M.mod_hsuforum.init_livelog
     * @readOnly
     */
    liveLog: { readOnly: true },

    /**
     * Observers mutation events for editor.
     */
    editorMutateObserver: null,

    /**
     * The show advanced edit link that was clicked most recently,
     */
    currentEditLink: null
};

Y.extend(ARTICLE, Y.Base,
    {
        /**
         * Setup the app
         */
        initializer: function() {
            this._set('router', new M.mod_hsuforum.Router({article: this, html5: false}));
            this._set('io', new M.mod_hsuforum.Io({contextId: this.get('contextId')}));
            this._set('dom', new M.mod_hsuforum.Dom({io: this.get('io')}));
            this._set('form', new M.mod_hsuforum.Form({io: this.get('io')}));
            this._set('liveLog', M.mod_hsuforum.init_livelog());
            this.bind();
            // this.get('router').dispatch();
        },

        /**
         * Bind all event listeners
         * @method bind
         */
        bind: function() {
            var firstUnreadPost = document.getElementsByClassName("hsuforum-post-unread")[0];
            if(firstUnreadPost && location.hash === '#unread') {
                // get the post parent to focus on
                var post = document.getElementById(firstUnreadPost.id).parentNode;
                // Workaround issues that IE has with negative margins in themes.
                if (navigator.userAgent.match(/Trident|MSIE/)) {
                    var y, e;
                    y = post.offsetTop;
                    e = post;
                    while ((e = e.offsetParent)) {
                        y += e.offsetTop;
                    }
                    window.scrollTo(0, y);
                } else {
                    post.scrollIntoView();
                }
                post.focus();
            }

            if (Y.one(SELECTORS.SEARCH_PAGE) !== null) {
                return;
            }

            var dom     = this.get('dom'),
                form    = this.get('form'),
                router  = this.get('router');

            /* Clean html on paste */
            Y.delegate('paste', form.handleFormPaste, document, '.hsuforum-textarea', form);

            // Implement toggling for post to all groups checkbox and groups select
            var posttoallgroups = '.hsuforum-discussion input[name="posttomygroups"]';
            Y.delegate('click', form.handlePostToGroupsToggle, document, posttoallgroups, form);

            // Implement toggling for the start and time elements for discussions.
            var discussiontimesselector = '.hsuforum-discussion .fdate_selector input';
            Y.delegate('click', form.handleTimeToggle, document, discussiontimesselector, form);

            // We bind to document otherwise screen readers read everything as clickable.
            Y.delegate('click', form.handleCancelForm, document, SELECTORS.LINK_CANCEL, form);
            Y.delegate('click', router.handleRoute, document, SELECTORS.CONTAINER_LINKS, router);
            Y.delegate('click', dom.handleViewRating, document, SELECTORS.RATE_POPUP, dom);

            // Advanced editor.
            Y.delegate('click', function(e){
                var editCont = Y.one('#hiddenadvancededitorcont'),
                    editor,
                    editArea,
                    advancedEditLink = this,
                    checkEditArea;

                if (!editCont){
                    return;
                }

                // Note, preventDefault is intentionally here as if an editor container is not present we want the
                // link to work.
                e.preventDefault();

                editArea = Y.one('#hiddenadvancededitoreditable');
                editor = editArea.ancestor('.editor_atto');

                if (editor){
                    M.mod_hsuforum.toggleAdvancedEditor(advancedEditLink);
                } else {
                    // The advanced editor isn't available yet, lets try again periodically.
                    advancedEditLink.setContent(M.util.get_string('loadingeditor', 'hsuforum'));
                    checkEditArea = setInterval(function(){
                        editor = editArea.ancestor('.editor_atto');
                        if (editor) {
                            clearInterval(checkEditArea);
                            M.mod_hsuforum.toggleAdvancedEditor(advancedEditLink);
                        }
                    }, 500);
                }

            }, document, '.hsuforum-use-advanced');

            // We bind to document for these buttons as they get re-added on each discussion addition.
            Y.delegate('submit', form.handleFormSubmit, document, SELECTORS.FORM, form);
            Y.delegate('click', router.handleAddDiscussionRoute, document, SELECTORS.ADD_DISCUSSION, router);

            // On post created, update HTML, URL and log.
            form.on(EVENTS.POST_CREATED, dom.handleUpdateDiscussion, dom);
            form.on(EVENTS.POST_CREATED, dom.handleNotification, dom);
            form.on(EVENTS.POST_CREATED, router.handleViewDiscussion, router);
            form.on(EVENTS.POST_CREATED, this.handleLiveLog, this);

            // On post updated, update HTML and URL and log.
            form.on(EVENTS.POST_UPDATED, this.handlePostUpdated, this);

            // On discussion created, update HTML, display notification, update URL and log it.
            form.on(EVENTS.DISCUSSION_CREATED, dom.handleUpdateDiscussion, dom);
            form.on(EVENTS.DISCUSSION_CREATED, dom.handleDiscussionCreated, dom);
            form.on(EVENTS.DISCUSSION_CREATED, dom.handleNotification, dom);
            form.on(EVENTS.DISCUSSION_CREATED, router.handleViewDiscussion, router);
            form.on(EVENTS.DISCUSSION_CREATED, this.handleLiveLog, this);

            // On discussion delete, update HTML (may redirect!), display notification and log it.
            this.on(EVENTS.DISCUSSION_DELETED, dom.handleDiscussionDeleted, dom);
            this.on(EVENTS.DISCUSSION_DELETED, dom.handleNotification, dom);
            this.on(EVENTS.DISCUSSION_DELETED, this.handleLiveLog, this);

            // On post deleted, update HTML, URL and log.
            this.on(EVENTS.POST_DELETED, dom.handleUpdateDiscussion, dom);
            this.on(EVENTS.POST_DELETED, router.handleViewDiscussion, router);
            this.on(EVENTS.POST_DELETED, dom.handleNotification, dom);
            this.on(EVENTS.POST_DELETED, this.handleLiveLog, this);

            // On form cancel, update the URL to view the discussion/post.
            form.on(EVENTS.FORM_CANCELED, router.handleViewDiscussion, router);
        },

        handlePostUpdated: function(e) {
            var dom     = this.get('dom'),
                form    = this.get('form'),
                router  = this.get('router');
            form.restoreDateFields();
            dom.handleUpdateDiscussion(e);
            router.handleViewDiscussion(e);
            dom.handleNotification(e);
            this.handleLiveLog(e);
        },

        /**
         * Inspects event object for livelog and logs it if found
         * @method handleLiveLog
         * @param e
         */
        handleLiveLog: function(e) {
            if (Y.Lang.isString(e.livelog)) {
                this.get('liveLog').logText(e.livelog);
            }
        },

        /**
         * View a discussion
         *
         * @method viewDiscussion
         * @param discussionid
         * @param [postid]
         */
        viewDiscussion: function(discussionid, postid) {
            var node = Y.one(SELECTORS.DISCUSSION_BY_ID.replace('%d', discussionid));
            if (!(node instanceof Y.Node)) {
                return;
            }
            if (!Y.Lang.isUndefined(postid)) {
                var postNode = Y.one(SELECTORS.POST_BY_ID.replace('%d', postid));
                if (postNode === null || postNode.hasAttribute('data-isdiscussion')) {
                    node.focus();
                } else {
                    postNode.get('parentNode').focus();
                }
            } else {
                node.focus();
            }
        },

        /**
         * Confirm deletion of a post
         *
         * @method confirmDeletePost
         * @param {Integer} postId
         */
        confirmDeletePost: function(postId) {
            var node = Y.one(SELECTORS.POST_BY_ID.replace('%d', postId));
            if (node === null) {
                return;
            }
            if (window.confirm(M.str.mod_hsuforum.deletesure) === true) {
                this.deletePost(postId);
            }
        },

        /**
         * Delete a post
         *
         * @method deletePost
         * @param {Integer} postId
         */
        deletePost: function(postId) {
            var node = Y.one(SELECTORS.POST_BY_ID.replace('%d', postId));
            if (node === null) {
                return;
            }

            this.get('io').send({
                postid: postId,
                sesskey: M.cfg.sesskey,
                action: 'delete_post'
            }, function(data) {
                if (node.hasAttribute('data-isdiscussion')) {
                    this.fire(EVENTS.DISCUSSION_DELETED, data);
                } else {
                    this.fire(EVENTS.POST_DELETED, data);
                }
            }, this);
        }
    }
);

M.mod_hsuforum.Article = ARTICLE;
M.mod_hsuforum.init_article = function(config) {
    new ARTICLE(config);
};

/**
 * Trigger click event.
 * @param el
 */
M.mod_hsuforum.dispatchClick = function(el) {
    if (document.createEvent) {
        var event = new MouseEvent('click', {
            'view': window,
            'bubbles': true,
            'cancelable': true
        });
        el.dispatchEvent(event);
    } else if (el.fireEvent) {
        el.fireEvent('onclick');
    }
};

/**
 * Restore editor to original position in DOM.
 */
M.mod_hsuforum.restoreEditor = function() {
    var editCont = Y.one('#hiddenadvancededitorcont');
    if (editCont) {
        var editArea = Y.one('#hiddenadvancededitoreditable');
        if (!editArea) {
            return;
        }
        var editor = editArea.ancestor('.editor_atto'),
            advancedEditLink = M.mod_hsuforum.Article.currentEditLink,
            contentEditable = false;

        if (advancedEditLink) {
            contentEditable = advancedEditLink.previous('.hsuforum-textarea');
        }

        var editorHidden = (!editor || editor.getComputedStyle('display') === 'none');

        // If the editor is visible then we need to make sure content is passed back to content editable div.
        // Are we in source mode?
        if (!editorHidden) {
            if (editor.one('.atto_html_button.highlight')) {
                // Trigger click on atto source button - we need to update the editor content.
                M.mod_hsuforum.dispatchClick(editor.one('.atto_html_button.highlight')._node);
            }
            // Update content editable div.
            if (contentEditable) {
                contentEditable.setContent(editArea.getContent());
            }
        }



        // Switch all editor links to hide mode.
        M.mod_hsuforum.toggleAdvancedEditor(false, true);

        // Put editor back in its correct place.
        Y.one('#hiddenadvancededitorcont').show();
        Y.one('#hiddenadvancededitorcont')._node.style.display='block';
        editCont.appendChild(editor);
        editCont.appendChild(Y.one('#hiddenadvancededitor'));
    }
};

/**
 * Toggle advanced editor in place of plain text editor.
 */
M.mod_hsuforum.toggleAdvancedEditor = function(advancedEditLink, forcehide, keepLink) {

    var showEditor = false;
    if (!forcehide) {
        showEditor = advancedEditLink && advancedEditLink.getAttribute('aria-pressed') === 'false';
    }

    if (advancedEditLink) {
        M.mod_hsuforum.Article.currentEditLink = advancedEditLink;
        if (showEditor) {
            advancedEditLink.removeClass('hideadvancededitor');
        } else {
            advancedEditLink.addClass('hideadvancededitor');
        }
    }

    // @TODO - consider a better explantion of forcehide
    // Force hide is required for doing things like hiding all editors except for the link that was just clicked.
    // So if you click reply against a topic and then open the editor and then click reply against another topic and
    // then open the editor you need the previous editor link to be reset.
    if (forcehide) {
        // If advancedEditLink is not set and we are forcing a hide then we need to hide every instance and change all labels.
        if (!advancedEditLink){
            var links = Y.all('.hsuforum-use-advanced');
            for (var l = 0; l<links.size(); l++) {
                var link = links.item(l);
                if (keepLink && keepLink === link){
                    continue; // Do not process this link.
                }
                // To hide this link and restore the editor, call myself.
                M.mod_hsuforum.toggleAdvancedEditor(link, true);
            }

            return;
        }
    } else {
        // OK we need to make sure the editor isn't available anywhere else, so call myself.
        M.mod_hsuforum.toggleAdvancedEditor(false, true, advancedEditLink);
    }

    var editCont = Y.one('#hiddenadvancededitorcont'),
        editArea,
        contentEditable = advancedEditLink.previous('.hsuforum-textarea'),
        editor;

    if (editCont){
        editArea = Y.one('#hiddenadvancededitoreditable');
        editor = editArea.ancestor('.editor_atto');
        if (contentEditable){
            editArea.setStyle('height', contentEditable.getDOMNode().offsetHeight+'px');
        }
    } else {
        //@TODO - throw error
        throw "Failed to get editor";
    }

    var editorHidden = false;
    if (!editor || editor.getComputedStyle('display') === 'none'){
        editorHidden = true;
    }

    if (showEditor) {
        advancedEditLink.setAttribute('aria-pressed', 'true');
        advancedEditLink.setContent(M.util.get_string('hideadvancededitor', 'hsuforum'));
        contentEditable.hide();
        // Are we in source mode?
        if (editor.one('.atto_html_button.highlight')) {
            Y.one('#hiddenadvancededitor').show();
        }
        editor.show();
        contentEditable.insert(editor, 'before');
        contentEditable.insert(Y.one('#hiddenadvancededitor'), 'before');
        var draftid = Y.one('#hiddenadvancededitordraftid');
        if (draftid) {
            var clonedraftid = draftid.cloneNode();
            clonedraftid.id = 'hiddenadvancededitordraftidclone';
            contentEditable.insert(clonedraftid, 'before');
        }
        editArea.setContent(contentEditable.getContent());

        // Focus on editarea.
        editArea.focus();

        /**
         * Callback for when editArea content changes.
         */
        var editAreaChanged = function(){
            contentEditable.setContent(editArea.getContent());
        };

        // Whenever the html editor changes its content, update the text area.
        if (window.MutationObserver){
            M.mod_hsuforum.Article.editorMutateObserver = new MutationObserver(editAreaChanged);
            M.mod_hsuforum.Article.editorMutateObserver.observe(editArea.getDOMNode(), {childList: true, characterData: true, subtree: true});
        } else {
            // Don't use yui delegate as I don't think it supports this event type
            editArea.getDOMNode().addEventListener ('DOMCharacterDataModified', editAreachanged, false);
        }
    } else {
        advancedEditLink.setAttribute('aria-pressed', 'false');
        if (M.mod_hsuforum.Article.editorMutateObserver){
            M.mod_hsuforum.Article.editorMutateObserver.disconnect();
        }
        advancedEditLink.setContent(M.util.get_string('useadvancededitor', 'hsuforum'));
        contentEditable.show();

        // If editor is not hidden then we need to update content editable div with editor content.
        if (!editorHidden) {
            // Are we in source mode?
            if (editor.one('.atto_html_button.highlight')) {
                // Trigger click on atto source button - we need to update the editor content.
                M.mod_hsuforum.dispatchClick(editor.one('.atto_html_button.highlight')._node);
            }
            // Update content of content editable div.

            contentEditable.setContent(editArea.getContent());
        }
        Y.one('#hiddenadvancededitor').hide();
        editor.hide();
    }
};


}, '@VERSION@', {
    "requires": [
        "base",
        "node",
        "event",
        "router",
        "core_rating",
        "querystring",
        "moodle-mod_hsuforum-io",
        "moodle-mod_hsuforum-livelog",
        "moodle-core-formchangechecker"
    ]
});
