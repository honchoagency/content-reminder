<?php

namespace honchoagency\contentreminder\console\controllers;

use honchoagency\contentreminder\ContentReminder;
use craft\console\Controller;
use yii\console\ExitCode;

class TestController extends Controller
{
    public $defaultAction = 'notifications';

    public function actionNotifications(): int
    {
        $this->stdout("Testing content review notifications...\n");

        try {
            ContentReminder::getInstance()->notifications->sendPendingReviewNotifications();
            $this->stdout("Notifications sent successfully!\n", \yii\helpers\Console::FG_GREEN);
            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr("Error sending notifications: " . $e->getMessage() . "\n", \yii\helpers\Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    public function actionGetSlackWebhook(): int
    {
        $settings = ContentReminder::getInstance()->getSettings();
        if (!$settings->enableSlackNotifications || empty($settings->slackWebhookUrl)) {
            $this->stderr("Slack notifications are not enabled or webhook URL is not set.\n", \yii\helpers\Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout($settings->slackWebhookUrl . "\n");
        return ExitCode::OK;
    }
}
