<?php
namespace BlockPlus\View\Helper;

use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Zend\Navigation\Navigation;
use Zend\View\Helper\AbstractHelper;

/**
 * View helper to get metadata about the current page.
 */
class PageMetadata extends AbstractHelper
{
    /**
     * Get metadata of the current page.
     *
     * @param string $metadata
     * @param SitePageRepresentation $page
     * @return \Omeka\Api\Representation\SitePageBlockRepresentation|mixed|false
     * False means that the current page does not have a page block metadata.
     */
    public function __invoke($metadata = null, SitePageRepresentation $page = null)
    {
        $view = $this->getView();

        /**
         * @var \Omeka\Api\Representation\SitePageRepresentation $page
         */
        if (!$page) {
            if (empty($view->page)) {
                $pageSlug = $view->params()->fromRoute('page-slug');
                if (empty($pageSlug)) {
                    return false;
                }
                try {
                    // Api doesn't allow to search pages by slug.
                    $site = $this->currentSite();
                    $page = $view->api()->read('site_pages', ['site' => $site->id(), 'slug' => $pageSlug])->getContent();
                } catch (NotFoundException $e) {
                    return false;
                }
            } else {
                $page = $view->page;
            }
        }

        $block = null;
        foreach ($page->blocks() as $block) {
            // TODO A page can belong to multiple types?
            if ($block->layout() === 'pageMetadata') {
                break;
            }
        }
        if (!$block) {
            return false;
        }

        switch ($metadata) {
            case 'page':
                return $block->page();
            case 'title':
                return $block->page()->title();
            case 'slug':
                return $block->page()->slug();

            case 'type':
            case 'credits':
            case 'summary':
            case 'tags':
                return $block->dataValue($metadata);

            case 'type_label':
                $type = $block->dataValue('type');
                $pageTypes = $view->siteSetting('blockplus_page_types', []);
                return isset($pageTypes[$type])
                    ? $pageTypes[$type]
                    : null;;

            case 'featured':
                return (bool) $block->dataValue('featured');
            case 'cover':
                $asset = $block->dataValue('cover');
                return $asset
                    ? $view->api()->searchOne('assets', ['id' => $asset])->getContent()
                    : null;

            case 'attachments':
                return $block->attachments();

            case 'root':
                $parents = $this->parentPages($page);
                return empty($parents) ? $page : array_pop($parents);
            case 'parent':
                $parents = $this->parentPages($page);
                return empty($parents) ? null : reset($parents);
            case 'parents':
                return $this->parentPages($page);

            case is_null($metadata):
                return $block;

            case 'params':
            case 'params_raw':
                return $block->dataValue('params', '');
            case 'params_json':
                return @json_decode($block->dataValue('params', ''));
            case 'params_key_value':
            default:
                $params = array_filter(array_map('trim', explode("\n", $block->dataValue('params', ''))));
                $list = [];
                foreach ($params as $keyValue) {
                    list($key, $value) = array_map('trim', explode('=', $keyValue, 2));
                    if ($key !== '') {
                        $list[$key] = $value;
                    }
                }
                if ($metadata === 'params_key_value') {
                    return $list;
                }
                return isset($list[$metadata])
                    ? $list[$metadata]
                    : null;
        }
    }

    protected function sitePages(SiteRepresentation $site)
    {
        static $pagesBySite;

        $siteId = $site->id();
        if (!isset($pagesBySite[$siteId])) {
            $pagesBySite[$siteId] = [];
            foreach ($site->pages() as $sitePage) {
                $pagesBySite[$siteId][$sitePage->id()] = $sitePage;
            }
        }

        return $pagesBySite[$siteId];
    }

    /**
     * Get the parent pages of a page,
     *
     * @todo Improve the process to get the parent pages.
     *
     * @param SitePageRepresentation $page
     * @return SitePageRepresentation[]
     */
    protected function parentPages(SitePageRepresentation $page)
    {
        $site = $page->site();
        $sitePages = $this->sitePages($site);
        $navigation = $site->navigation();
        $pages = [];
        $pageId = $page->id();
        while (true) {
            $pageData = $this->findPageInNavigation($pageId, $navigation);
            if (!$pageData || empty($pageData['parent_id'])) {
                return $pages;
            }
            $pages[] = $sitePages[$pageData['parent_id']];
            $pageId = $pageData['parent_id'];
        }
        return $pages;
    }

    /**
     * Find data about a page from the navigation.
     *
     * FIXME Use nav container, not the static site navigation (even if it should be the same because no page are private).
     * See \Omeka\Site\BlockLayout\TableOfContents::render()
     *
     * @param int $pageId
     * @param array $navItems
     * @param int $parentPageId
     * @return array
     */
    protected function findPageInNavigation($pageId, $navItems, $parentPageId = null)
    {
        $siblings = [];
        foreach ($navItems as $navItem) {
            if ($navItem['type'] === 'page') {
                $navItemData = $navItem['data'];
                $navItemId = $navItemData['id'];
                $siblings[] = $navItemId;
            }
        }

        $childLinks = [];
        foreach ($navItems as $navItem) {
            if ($navItem['type'] === 'page') {
                $navItemData = $navItem['data'];
                $navItemId = $navItemData['id'];

                if ($navItemId === $pageId) {
                    return [
                        'id' => $pageId,
                        'parent_id' => $parentPageId,
                        'siblings' => $siblings,
                    ];
                }

                if (array_key_exists('links', $navItem)) {
                    $childLinks[$navItemId] = $navItem['links'];
                }
            }
        }

        foreach ($childLinks as $parentPageId => $links) {
            $childLinkResult = $this->findPageInNavigation($pageId, $links, $parentPageId);
            if ($childLinkResult) {
                return $childLinkResult;
            }
        }

        return null;
    }

    /**
     * @return \Omeka\Api\Representation\SiteRepresentation
     */
    protected function currentSite()
    {
        $view = $this->getView();
        return isset($view->site)
            ? $view->site
            : $view->getHelperPluginManager()->get('Zend\View\Helper\ViewModel')->getRoot()->getVariable('site');
    }
}