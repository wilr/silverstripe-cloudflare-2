<?php

namespace SteadLane\Cloudflare;

use SteadLane\Cloudflare\Purge;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;

class CloudFlareElementalExtension extends Extension
{

    public function onAfterPublish()
    {
        $page = $this->owner->getPage();

        if (!($page instanceof SiteTree)) {
            return;
        }

        Purge::singleton()->quick('page', $page->ID);
    }
}
