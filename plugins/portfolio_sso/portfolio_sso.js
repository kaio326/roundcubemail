/**
 * Portfolio single sign-on: webmail belongs inside the website dashboard.
 *
 * When a main webmail view (a task with no action, e.g. ?_task=mail) is opened as
 * the top-level page, send the browser to the dashboard page that frames it, keeping
 * the current view in ?view=. Pop-up windows Roundcube opens itself (extwin) and
 * single-purpose actions such as print or attachment downloads are left alone.
 *
 * @license GNU GPLv3+
 * @author Kaio Fernandes
 */
if (window.rcmail) {
    rcmail.addEventListener('init', function () {
        var frameUrl = rcmail.env.portfolio_sso_frame_url;

        if (window.top !== window || !frameUrl || rcmail.env.extwin || rcmail.env.action) {
            return;
        }

        window.location.replace(frameUrl + '?view=' + encodeURIComponent(location.pathname + location.search));
    });
}
