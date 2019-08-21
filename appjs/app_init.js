// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * support for the mdl35+ mobile app
 * This file is the equivalent of
 * qtype/wordselect/classes/wordselect.ts in the core app
 * e.g.
 * https://github.com/moodlehq/moodlemobile2/blob/v3.5.0/src/addon/qtype/ddwtos/classes/ddwtos.ts
 */

(function (t) {

    /* Register a link handler to open mod/hsuforum/view.php links anywhere in the app. */
    function AddonModHsuforumModuleLinkHandler() {
        t.CoreContentLinksModuleIndexHandler.call(this, t.CoreCourseHelperProvider, 'mmaModHsuforum', 'hsuforum');

        this.pattern = /\/mod\/hsuforum\/discuss\.php.*([\&\?]d=\d+)/;
        this.name = "AddonModHsuforumLinkHandler";
    }

    function AddonModHsuforumModuleViewLinkHandler() {
        t.CoreContentLinksModuleIndexHandler.call(this, t.CoreCourseHelperProvider, 'mmaModHsuforum', 'hsuforum');

        this.pattern = /\/mod\/hsuforum\/view\.php.*([\&\?]id=\d+)/;
        this.name = "AddonModHsuforumViewLinkHandler";
    }

    function AddonModHsuforumMarkNotificationRead(urlHash) {
        let site = t.CoreSitesProvider.getCurrentSite();
        let toUser = site.getUserId();

        const data = {
            useridto: toUser,
            useridfrom: 0,
            type: 'notifications',
            read: 0,
            newestfirst: 1,
            limitfrom: 0,
            limitnum: 0
        };

        // Check if there is an unread notification that matches urlHash. Below todo will fix this section.
        site.read('core_message_get_messages', data).then((response) => {
            if (response.messages) {
                let unreadNotification = response.messages.filter(notification => notification.contexturl.includes(urlHash));

                // Mark notification as read if found
                if (unreadNotification.length) {
                    let params = {
                        messageid: unreadNotification[0].id, 
                        userid: toUser,
                        markallnotifications: 0, 
                    };
                    Promise.resolve(site.write('theme_legend_custom_notifications', params)).catch((err) => {
                        // TODO refactor above function to include notification id in urlcontext and change return type to json
                        // This will throw an error at this point due to the moodle mobile post function only accepts json as 
                        // a return type.
                        console.log(err);
                    });
                }
            }
        });
    }

    AddonModHsuforumModuleLinkHandler.prototype = Object.create(t.CoreContentLinksModuleIndexHandler.prototype);
    AddonModHsuforumModuleLinkHandler.prototype.constructor = AddonModHsuforumModuleLinkHandler;

    AddonModHsuforumModuleViewLinkHandler.prototype = Object.create(t.CoreContentLinksModuleIndexHandler.prototype);
    AddonModHsuforumModuleViewLinkHandler.prototype.constructor = AddonModHsuforumModuleViewLinkHandler;

    AddonModHsuforumModuleLinkHandler.prototype.getActions = function (siteIds, url, params, courseId) {
        return [{
            action: function() {
                let site = t.CoreSitesProvider.getCurrentSite();
                let navigation = site.appProvider.getRootNavController();

                const pageParams = {
                    discussionid: parseInt(params.d, 10),
                    urlHash: params.urlHash
                };

                return Promise.resolve(t.CoreSitePluginsProvider.getContent('mod_hsuforum', 'view_discussion', pageParams)).then((contentResult) => {
                    // Do some tests here on the contentResult if need be since we have all the data.
                    return Promise.resolve(navigation.push('CoreSitePluginsPluginPage', {
                        title: (contentResult['otherdata']['discussiontitle'] !== undefined) ? contentResult['otherdata']['discussiontitle'] : '',
                        component: 'mod_hsuforum',
                        method: 'view_discussion',
                        args: pageParams,
                        initResult: {},
                        jsData: {},
                    }).then((success) => {
                        // Handle marking notification as read if not already read
                        if (success && pageParams.urlHash.match(/p(\d+)/)) {
                            AddonModHsuforumMarkNotificationRead(pageParams.urlHash);
                        }
                    }));
                });
            }
        }];
    }

    t.CoreContentLinksDelegate.registerHandler(new AddonModHsuforumModuleLinkHandler());

    AddonModHsuforumModuleViewLinkHandler.prototype.getActions = function (siteIds, url, params, courseId) {
        return [{
            action: function() {
                let site = t.CoreSitesProvider.getCurrentSite();
                let navigation = site.appProvider.getRootNavController();

                const pageParams = {
                    cmid: parseInt(params.id, 10),
                };

                return Promise.resolve(t.CoreSitePluginsProvider.getContent('mod_hsuforum', 'forum_discussions_view', pageParams)).then((contentResult) => {
                    // Do some tests here on the contentResult if need be since we have all the data.
                    return Promise.resolve(navigation.push('CoreSitePluginsPluginPage', {
                        title: (contentResult['otherdata']['discussiontitle'] !== undefined) ? contentResult['otherdata']['discussiontitle'] : '',
                        component: 'mod_hsuforum',
                        method: 'forum_discussions_view',
                        args: pageParams,
                        initResult: {},
                        jsData: {},
                    }));
                });
            }
        }];
    }

    t.CoreContentLinksDelegate.registerHandler(new AddonModHsuforumModuleViewLinkHandler());
    
})(this);
