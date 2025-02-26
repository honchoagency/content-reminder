<?php
namespace honchoagency\contentreminder\jobs;

use Craft;
use craft\queue\BaseJob;
use honchoagency\contentreminder\ContentReminder;

class ContentReminderCheckJob extends BaseJob
{
    public bool $wasExecuted = false;

    public function execute($queue): void
    {
        if ($this->wasExecuted) {
            Craft::info('Job was already executed, skipping.', __METHOD__);
            return;
        }

        try {
            Craft::info('Starting content reminder check job', __METHOD__);
            
            // Send notifications
            ContentReminder::getInstance()->notifications->sendPendingReviewNotifications();
            
            // Mark as executed to prevent re-runs
            $this->wasExecuted = true;
            
            // Don't schedule another job here - let the plugin init() handle scheduling
            
            Craft::info('Completed content reminder check job', __METHOD__);
            $this->setProgress($queue, 1);
        } catch (\Throwable $e) {
            Craft::error('Error executing ContentReminderCheckJob: ' . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }

    protected function defaultDescription(): string
    {
        return 'Checking for content that needs review';
    }
}