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
                Y.log('Not binding event handlers on search page', 'info', 'Article');
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
                Y.log('Cannot view discussion because discussion node not found', 'error', 'Article');
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
            Y.log('Deleting post: ' + postId);

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
