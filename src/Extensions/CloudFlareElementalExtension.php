<?php

namespace SteadLane\Cloudflare;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;
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
        $page = $this->owner->getPage();

        if (!($page instanceof SiteTree)) {
            return;
        }

        Purge::singleton()->quick('page', $page->ID);
    }
}
