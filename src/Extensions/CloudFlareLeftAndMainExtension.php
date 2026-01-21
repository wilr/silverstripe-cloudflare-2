<?php

namespace SteadLane\Cloudflare;

use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Permission;
use SteadLane\Cloudflare\Messages\Notifications;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * LeftAndMain extension for Cloudflare CMS actions
 */
class CloudFlareLeftAndMainExtension extends Extension
{
    /**
     * @var array<string>
     */
    private static $allowed_actions = [
        'purgesinglepage',
        'purgeallpagesAction'
    ];

    /**
     * Purge a single page in Cloudflare
     *
     * @param array<string, mixed> $request The SiteTree data requested to be purged
     * @return void
     */
    public function purgesinglepageAction($request)
    {
        if (!Permission::check('CF_PURGE_PAGE')) {
            return;
        }

        if (empty($request) || empty($request['ID'])) {
            return;
        }

        Purge::singleton()->quick('page', $request['ID']);
    }

    /**
     * Queue a job to purge all pages in Cloudflare
     *
     * @param array<string, mixed> $request
     * @return void
     */
    public function purgeallpagesAction($request)
    {
        if (!Permission::check('CF_PURGE_PAGE')) {
            return;
        }

        QueuedJobService::singleton()->queueJob(
            Injector::inst()->create(PurgePagesJob::class)
        );

        Notifications::handleMessage("Purge all pages queued successfully");
    }
}
