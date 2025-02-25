<?php
namespace honchoagency\contentreminder\jobs;

use Craft;
use craft\queue\BaseJob;
use honchoagency\contentreminder\ContentReminder;

class ContentReminderCheckJob extends BaseJob
{
    public function execute($queue): void
    {
        try {
            Craft::info('Starting content reminder check job', __METHOD__);
            
            ContentReminder::getInstance()->notifications->sendPendingReviewNotifications();
            
            Craft::info('Completed content reminder check job', __METHOD__);
            $this->setProgress($queue, 1);
        } catch (\Throwable $e) {
            Craft::error('Error executing ContentReminderCheckJob: ' . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }

    protected function defaultDescription(): string
    {
        return Craft::t('content-reminder', 'Checking for content that needs review');
    }
} 