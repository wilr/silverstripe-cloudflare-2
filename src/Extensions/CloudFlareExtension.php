<?php

namespace SteadLane\Cloudflare;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Security\Permission;
use SteadLane\Cloudflare\Messages\Notifications;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * SiteTree extension for Cloudflare cache purging on page publish/unpublish
 *
 * @property SiteTree $owner
 */
class CloudFlareExtension extends Extension
{
    /**
     * Get the page URL for Cloudflare purging
     *
     * @return string|null
     */
    protected function getPageUrl()
    {
        $pageUrl = ltrim(DataObject::get_by_id(SiteTree::class, $this->owner->ID)->AbsoluteLink(), "/");
        
        if (class_exists('SilverStripe\Subsites\Model\Subsite')) {
            \SilverStripe\Subsites\Model\Subsite::disable_subsite_filter();
            $pageUrl = ltrim(
                \SilverStripe\Subsites\Model\Subsite::get_from_all_subsites(SiteTree::class)
                    ?->byID($this->owner->ID)
                    ?->AbsoluteLink()
                    ?? '/',
                    '/'
            );
            $pageUrl = str_replace('http://', 'https://', $pageUrl);
        }
        
        // This will safely remove "stage" params, but keep any others. It doesn't matter where in the string "stage=" exists
        $pageUrl = preg_replace('/([?&])stage=[^&]+(&|$)/', '$1', $pageUrl);

        // Trim any trailing "?" or "&".
        $pageUrl = rtrim($pageUrl, '&');
        $pageUrl = rtrim($pageUrl, '?');

        return $pageUrl ?: null;
    }

    /**
     * Extension Hook - purge cache after publishing
     *
     * @param SiteTree $original
     */
    public function onAfterPublish(&$original)
    {
        // If page was just created or no credentials, bail out
        if (!CloudFlare::singleton()->hasCFCredentials() || !$original || !strlen($original->URLSegment)) {
            return;
        }

        $purger = Purge::create();
        $shouldPurgeRelations = Purge::singleton()->getShouldPurgeRelations();

        // If critical elements changed (URL, title, menu), purge everything
        if (
            $this->owner->URLSegment != $original->URLSegment || 
            $this->owner->MenuTitle != $original->MenuTitle || 
            $this->owner->Title != $original->Title
        ) {
            if (CloudFlare::config()->purge_all !== true) {
                QueuedJobService::singleton()->queueJob(
                    Injector::inst()->create(PurgePagesJob::class)
                );

                Notifications::handleMessage(
                    _t(
                        "CloudFlare.SuccessCriticalElementChanged",
                        "A critical element has changed in this page (url, menu label, or page title) as a result; everything was purged"
                    )
                );
            } else {
                $purger
                    ->setSuccessMessage(
                        _t(
                            "CloudFlare.SuccessCriticalElementChanged",
                            "A critical element has changed in this page (url, menu label, or page title) as a result; everything was purged"
                        )
                    )
                    ->setPurgeEverything(true)
                    ->purge();
            }
            return;
        }

        // Build list of URLs to purge
        $urls = [];
        $pageUrl = $this->getPageUrl();
        if ($pageUrl) {
            $urls[] = $pageUrl;
        }

        // If configured, also purge all child pages
        if ($shouldPurgeRelations) {
            $top = $this->getTopLevelParent();
            if ($top && $this->owner->URLSegment != $top->URLSegment) {
                $this->getChildrenRecursive($top->ID, $urls);
            }
        }

        // Remove empty URLs
        $urls = array_filter($urls);

        if (empty($urls)) {
            return;
        }

        if (count($urls) === 1) {
            $purger
                ->reset()
                ->setSuccessMessage(
                    _t(
                        "CloudFlare.SuccessPurgeCurrentPage",
                        "Successfully purged cache for the current page"
                    )
                )
                ->setFailureMessage(
                    _t(
                        "CloudFlare.FailurePurgeCurrentPage",
                        "An error occurred while attempting to purge cache for the current page"
                    )
                )
                ->pushFile($urls[0])
                ->purge();
        } else {
            $purger
                ->reset()
                ->setSuccessMessage(
                    _t(
                        "CloudFlare.SuccessPurgeMultiple",
                        "Cache has been purged for {file_count} files"
                    )
                )
                ->setFailureMessage(
                    _t(
                        "CloudFlare.FailurePurgeMultiple",
                        "An error occurred while attempting to purge cache for multiple pages"
                    )
                )
                ->pushFile($urls)
                ->purge();
        }
    }

    /**
     * If something gets unpublished we purge EVERYTHING just to be safe (ie nav menus etc)
     */
    public function onAfterUnpublish()
    {
        if (!CloudFlare::singleton()->hasCFCredentials()) {
            return;
        }

        if (CloudFlare::config()->purge_all !== true) {
            QueuedJobService::singleton()->queueJob(
                Injector::inst()->create(PurgePagesJob::class)
            );

            Notifications::handleMessage("Purge all pages queued successfully. This erases the page from navigation menus.");
        } else {
            Purge::create()
                ->setPurgeEverything(true)
                ->setSuccessMessage(
                    _t(
                        "CloudFlare.UnpublishAllCachePurged",
                        "All cache has been purged as a result of unpublishing a page."
                    )
                )
                ->setFailureMessage(
                    _t(
                        "CloudFlare.FailureAllCachePurged",
                        "We encountered an error when attempting to purge all cache, consider doing this manually."
                    )
                )
                ->purge();
        }
    }

    /**
     * Check if the current page or given page ID is a parent
     *
     * @param int|null $id SiteTree.ID
     * @return bool
     */
    public function isParent($id = null)
    {
        return $this->getChildren($id)->count() > 0;
    }

    /**
     * Get immediate children of a page
     *
     * @param int|null $parentID SiteTree.ParentID
     * @return \SilverStripe\ORM\DataList
     */
    public function getChildren($parentID = null)
    {
        $id = ($parentID === null) ? $this->owner->ID : $parentID;
        return SiteTree::get()->filter("ParentID", $id);
    }

    /**
     * Traverse through the SiteTree hierarchy to find the top level parent
     *
     * @return SiteTree
     */
    public function getTopLevelParent()
    {
        $obj = $this->owner;

        while ((int)$obj->ParentID) {
            $obj = SiteTree::get()->filter("ID", $obj->ParentID)->first();
        }

        return $obj;
    }

    /**
     * Recursively fetch all children of the given page ID
     *
     * @param int|null $parentID
     * @param array<string> $output Reference to array of URLs
     * @return void
     */
    public function getChildrenRecursive($parentID, &$output)
    {
        $id = ($parentID === null) ? $this->owner->ID : $parentID;

        if (!is_array($output)) {
            $output = [];
        }

        $children = $this->getChildren($id);

        foreach ($children as $child) {
            if ($this->isParent($child->ID)) {
                $this->getChildrenRecursive($child->ID, $output);
            }

            $childUrl = ltrim(DataObject::get_by_id(SiteTree::class, $child->ID)->AbsoluteLink(), "/");
            if ($childUrl) {
                $output[] = $childUrl;
            }
        }
    }

    /**
     * Add a "purge page" CMS action
     *
     * @param  FieldList $actions
     * @return void
     */
    public function updateCMSActions(FieldList $actions)
    {
        if (!CloudFlare::singleton()->hasCFCredentials()) {
            return;
        }

        $actions->addFieldsToTab(
            'ActionMenus.MoreOptions',
            [
                FormAction::create(
                    'purgesinglepageAction',
                    _t(
                        'CloudFlare.ActionMenuPurge',
                        'Purge in Cloudflare'
                    )
                )->addExtraClass('btn-secondary'),
                FormAction::create(
                    'purgeallpagesAction',
                    _t(
                        'CloudFlare.ActionMenuPurgeAllPages',
                        'Purge all pages in Cloudflare'
                    )
                )->addExtraClass('btn-secondary'),
            ]
        );
    }
}
