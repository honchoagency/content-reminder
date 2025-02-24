<?php

namespace honchoagency\contentreminder\console\controllers;

use honchoagency\contentreminder\ContentReminder;
use yii\console\Controller;
use yii\console\ExitCode;
use Craft;

class NotificationsController extends Controller
{
    public function actionTest(): int
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
}
