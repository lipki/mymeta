<?php
/**
 * @brief mymeta, a plugin for Dotclear 2
 *
 * @package Dotclear
 * @subpackage Plugins
 *
 * @author Franck Paul and contributors
 *
 * @copyright Franck Paul carnet.franck.paul@gmail.com
 * @copyright GPL-2.0 https://www.gnu.org/licenses/gpl-2.0.html
 */
declare(strict_types=1);

namespace Dotclear\Plugin\mymeta;

use dcAuth;
use dcCore;
use Dotclear\App;
use Dotclear\Core\Backend\Listing\ListingPosts;
use Dotclear\Core\Backend\Notices;
use Dotclear\Core\Backend\Page;
use Dotclear\Core\Process;
use Dotclear\Helper\Html\Html;
use Exception;
use form;

class ManageViewPosts extends Process
{
    /**
     * Initializes the page.
     */
    public static function init(): bool
    {
        dcCore::app()->admin->mymeta = new MyMeta();

        return self::status(My::checkContext(My::MANAGE) && (($_REQUEST['m'] ?? 'mymeta') === 'viewposts'));
    }

    /**
     * Processes the request(s).
     */
    public static function process(): bool
    {
        if (!self::status()) {
            return false;
        }

        if (empty($_GET['id']) || empty($_GET['value'])) {
            Notices::addErrorNotice(__('Something went wrong while editing mymeta value'));
            dcCore::app()->adminurl->redirect('admin.plugin.' . My::id());
        }

        dcCore::app()->admin->mymetaEntry = dcCore::app()->admin->mymeta->getByID($_GET['id']);
        if (dcCore::app()->admin->mymetaEntry == null) {
            Notices::addErrorNotice(__('Something went wrong while editing mymeta value'));
            dcCore::app()->adminurl->redirect('admin.plugin.' . My::id());
        }

        $value = rawurldecode($_GET['value']);

        dcCore::app()->admin->posts_actions_page = new BackendActions(
            dcCore::app()->adminurl->get('admin.plugin'),
            ['p' => My::id(), 'm' => 'viewposts', 'id' => dcCore::app()->admin->mymetaEntry->id]
        );

        dcCore::app()->admin->posts_actions_page_rendered = null;
        if (dcCore::app()->admin->posts_actions_page->process()) {
            dcCore::app()->admin->posts_actions_page_rendered = true;

            return true;
        }

        // Rename a tag
        if (!empty($_POST['rename'])) {
            $new_value = $_POST['mymeta_' . dcCore::app()->admin->mymetaEntry->id];

            try {
                if (dcCore::app()->admin->mymeta->dcmeta->updateMeta($value, $new_value, dcCore::app()->admin->mymetaEntry->id)) {
                    Notices::addSuccessNotice(sprintf(
                        __('Mymeta value successfully updated from "%s" to "%s"'),
                        Html::escapeHTML($value),
                        Html::escapeHTML($new_value)
                    ));
                    dcCore::app()->adminurl->redirect('admin.plugin.' . My::id(), [
                        'm'      => 'view',
                        'id'     => dcCore::app()->admin->mymetaEntry->id,
                        'status' => 'valchg',
                    ]);
                }
            } catch (Exception $e) {
                dcCore::app()->error->add($e->getMessage());
            }
        }

        // Delete a tag
        if (!empty($_POST['delete']) && dcCore::app()->auth->check(dcCore::app()->auth->makePermissions([
            dcAuth::PERMISSION_PUBLISH,
            dcAuth::PERMISSION_CONTENT_ADMIN,
        ]), App::blog()->id())) {
            try {
                dcCore::app()->admin->mymeta->dcmeta->delMeta($value, dcCore::app()->admin->mymetaEntry->id);
                dcCore::app()->adminurl->redirect('admin.plugin.' . My::id(), [
                    'm'   => 'view',
                    'del' => 1,
                ]);
            } catch (Exception $e) {
                dcCore::app()->error->add($e->getMessage());
            }
        }

        return true;
    }

    /**
     * Renders the page.
     */
    public static function render(): void
    {
        if (!self::status()) {
            return;
        }

        if (dcCore::app()->admin->posts_actions_page_rendered) {
            dcCore::app()->admin->posts_actions_page->render();

            return;
        }

        $value = rawurldecode($_GET['value']);

        $this_url = dcCore::app()->admin->getPageURL() . '&amp;m=viewposts&amp;id=' . dcCore::app()->admin->mymetaEntry->id . '&amp;value=' . rawurlencode($value);

        $page        = !empty($_GET['page']) ? $_GET['page'] : 1;
        $nb_per_page = 30;

        $params               = [];
        $params['limit']      = [(($page - 1) * $nb_per_page),$nb_per_page];
        $params['no_content'] = true;

        $params['meta_id']   = $value;
        $params['meta_type'] = dcCore::app()->admin->mymetaEntry->id;

        $params['post_type'] = '';

        # Get posts
        $post_list = null;
        $posts     = null;

        try {
            $posts     = dcCore::app()->admin->mymeta->dcmeta->getPostsByMeta($params);
            $counter   = dcCore::app()->admin->mymeta->dcmeta->getPostsByMeta($params, true);
            $post_list = new ListingPosts($posts, $counter->f(0));
        } catch (Exception $e) {
            dcCore::app()->error->add($e->getMessage());
        }

        # Actions combo box
        $combo_action = [];
        if (dcCore::app()->auth->check(dcCore::app()->auth->makePermissions([
            dcAuth::PERMISSION_PUBLISH,
            dcAuth::PERMISSION_CONTENT_ADMIN,
        ]), App::blog()->id())) {
            $combo_action[__('Status')] = [
                __('Publish')         => 'publish',
                __('Unpublish')       => 'unpublish',
                __('Schedule')        => 'schedule',
                __('Mark as pending') => 'pending',
            ];
        }
        $combo_action[__('Mark')] = [
            __('Mark as selected')   => 'selected',
            __('Mark as unselected') => 'unselected',
        ];
        $combo_action[__('Change')] = [__('Change category') => 'category'];
        if (dcCore::app()->auth->check(dcCore::app()->auth->makePermissions([
            dcAuth::PERMISSION_ADMIN,
        ]), App::blog()->id())) {
            $combo_action[__('Change')] = array_merge(
                $combo_action[__('Change')],
                [__('Change author') => 'author']
            );
        }
        if (dcCore::app()->auth->check(dcCore::app()->auth->makePermissions([
            dcAuth::PERMISSION_DELETE,
            dcAuth::PERMISSION_CONTENT_ADMIN,
        ]), App::blog()->id())) {
            $combo_action[__('Delete')] = [__('Delete') => 'delete'];
        }

        # --BEHAVIOR-- adminPostsActionsCombo
        dcCore::app()->callBehavior('adminPostsActionsCombo', [&$combo_action]);

        $head = My::cssLoad('style.css') .
        Page::jsLoad('js/_posts_list.js') .
        Page::jsJson('mymeta', ['msg' => __('Are you sure you want to remove this metadata?')]) .
        My::jsLoad('mymeta.js') .
        Page::jsPageTabs('mymeta') .
        dcCore::app()->admin->mymetaEntry->postHeader(null, true);

        Page::openModule(My::name(), $head);

        echo Page::breadcrumb(
            [
                Html::escapeHTML(App::blog()->name())                   => '',
                __('My Metadata')                                       => dcCore::app()->admin->getPageURL(),
                Html::escapeHTML(dcCore::app()->admin->mymetaEntry->id) => dcCore::app()->admin->getPageURL() . '&m=view&id=' . dcCore::app()->admin->mymetaEntry->id,
                sprintf(__('Value "%s"'), Html::escapeHTML($value))     => '',
            ]
        );
        echo Notices::getNotices();

        // Form
        echo '<h4>' . sprintf(__('Entries having meta id "%s" set to "%s"'), Html::escapeHTML(dcCore::app()->admin->mymetaEntry->id), Html::escapeHTML($value)) . '</h4>';
        // Show posts
        if ($post_list) {
            $post_list->display(
                $page,
                $nb_per_page,
                '<form action="' . dcCore::app()->admin->getPageURL() . '" method="post" id="form-entries">' .

                '%s' .

                '<div class="two-cols">' .
                '<p class="col checkboxes-helpers"></p>' .

                '<p class="col right"><label for="action" class="classic">' . __('Selected entries action:') . '</label> ' .
                form::combo('action', dcCore::app()->admin->posts_actions_page->getCombo()) .
                '<input type="submit" value="' . __('ok') . '" /></p>' .
                My::parsedHiddenFields([
                    'post_type' => '',
                    'm'         => 'serie_posts',
                    'id'        => dcCore::app()->admin->mymetaEntry->id,
                ]) .
                '</div>' .
                '</form>'
            );
        }

        // Remove tag
        if (!$posts->isEmpty() && dcCore::app()->auth->check(dcCore::app()->auth->makePermissions([
            dcAuth::PERMISSION_CONTENT_ADMIN,
        ]), App::blog()->id())) {
            echo
            '<form id="tag_delete" action="' . $this_url . '" method="post">' .
            '<p><input type="submit" name="delete" value="' . __('Delete this tag') . '" />' .
            My::parsedHiddenFields() .
            '</p>' .
            '</form>';
        }
        if (!$posts->isEmpty()) {
            echo
            '<fieldset><legend>' . __('Change MyMeta value') . '</legend><form action="' . $this_url . '" method="post">' .
            '<p class="info">' . __('This will change the meta value for all entries having this value') . '</p>' .
            dcCore::app()->admin->mymetaEntry->postShowForm(dcCore::app()->admin->mymeta->dcmeta, null, Html::escapeHTML($value), true) .
            '<p><input type="submit" name="rename" value="' . __('save') . '" />' .
            My::parsedHiddenFields([
                'value' => Html::escapeHTML($value),
            ]) .
            '</p></form></fieldset>';
        }

        Page::closeModule();
    }
}
