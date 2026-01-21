<?php

namespace SteadLane\Cloudflare;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;
use SilverStripe\Security\Permission;
use SteadLane\Cloudflare\Purge;

/**
 * Elemental extension for Cloudflare cache purging
 */
class CloudFlareElementalExtension extends Extension
{
    /**
     * Purge Cloudflare cache after publishing an elemental block
     *
     * @return void
     */
    public function onAfterPublish()
    {
        if (!Permission::check('CF_PURGE_PAGE')) {
            return;
        }

        $page = $this->owner->getPage();

        if (!($page instanceof SiteTree)) {
            return;
        }

        Purge::singleton()->quick('page', $page->ID);
    }
}
