<?php

namespace honchoagency\contentreminder\services;

use honchoagency\contentreminder\ContentReminder;
use honchoagency\contentreminder\models\ContentReminderSection;
use Craft;
use craft\base\Component;
use craft\mail\Message;
use DateTime;
use Swift_SmtpTransport;
use craft\helpers\UrlHelper;


/**
 * Notification service
 */
class NotificationService extends Component
{
    /**
     * Send Slack notification for sections that need review
     */
    private function sendSlackNotification(array $sections): void
    {
        $settings = ContentReminder::getInstance()->getSettings();

        // Check if Slack notifications are enabled and webhook URL is set
        if (!$settings->enableSlackNotifications || empty($settings->slackWebhookUrl)) {
            return;
        }

        // Build sections list
        $sectionsList = "";
        foreach ($sections as $section) {
            $reviewer = $section->getReviewer();

            $sectionsList .= "\n• *{$section->section->name}*" .
                "\n  Review Due: " . Craft::$app->formatter->asDate($section->nextReviewDate, 'medium') .
                ($section->lastReviewedAt ? "\n  Last Reviewed: " . Craft::$app->formatter->asDate($section->lastReviewedAt, 'medium') .
                " by {$reviewer}" : "\n  Never reviewed") . "\n";
        }


        // $baseUrl = getenv('PRIMARY_SITE_URL');
        $baseUrl = UrlHelper::cpUrl('content-reminder');
        $cpTrigger = Craft::$app->getConfig()->getGeneral()->cpTrigger;
        $dashboardUrl = rtrim($baseUrl, '/') . '/' . trim($cpTrigger, '/') . '/content-reminder';

        // Ensure URL starts with https://
        if (!str_starts_with($dashboardUrl, 'https://')) {
            $dashboardUrl = 'https://' . ltrim($dashboardUrl, '/');
        }

        // Prepare the message
        $message = [
            'text' => Craft::t('content-reminder',
                "{siteName}: Content Review Needed\n\n" .
                "The following sections need to be reviewed:\n" .
                "{sections}\n" .
                "\n<{url}|View Content Review Dashboard>",
                [
                    'siteName' => Craft::$app->sites->primarySite->name,
                    'sections' => $sectionsList,
                    'url' => $baseUrl
                ]
            )
        ];

        // Send the notification
        try {
            Craft::info(
                "Attempting to send Slack notification for multiple sections to webhook URL: {$settings->slackWebhookUrl}",
                __METHOD__
            );

            $client = Craft::createGuzzleClient();
            $response = $client->post($settings->slackWebhookUrl, [
                'json' => $message,
                'http_errors' => false
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string)$response->getBody();

            if ($statusCode !== 200) {
                throw new \Exception("Slack API returned status code {$statusCode}: {$body}");
            }

            Craft::info(
                "Successfully sent Slack notification for multiple sections",
                __METHOD__
            );
        } catch (\Throwable $e) {
            Craft::error(
                "Error sending Slack notification: " .
                $e->getMessage() . "\nStack trace: " . $e->getTraceAsString(),
                __METHOD__
            );
        }
    }

    /**
     * Send email notification for a section that needs review
     */
    public function sendSectionReviewNotification(ContentReminderSection $section): void
    {
        $settings = ContentReminder::getInstance()->getSettings();

        // Send email notifications if enabled
        if ($settings->enableEmailNotifications) {
            $this->sendEmailNotification($section);
        }

        // Send Slack notifications if enabled
        if ($settings->enableSlackNotifications) {
            $this->sendSlackNotification($section);
        }
    }

    /**
     * Send email notification for a section that needs review
     */
    private function sendEmailNotification(ContentReminderSection $section): void
    {
        $settings = ContentReminder::getInstance()->getSettings();

        // Get the section's content manager/owner
        $reviewer = $section->getReviewer();
        $recipients = [];

        // Add the last reviewer if available
        if ($reviewer) {
            $recipients[] = $reviewer->email;
        }

        // Add any additional configured recipients
        if (!empty($settings->additionalEmailRecipients)) {
            $additionalRecipients = array_map('trim', $settings->additionalEmailRecipients);
            $additionalRecipients = array_filter($additionalRecipients, function($email) {
                return filter_var($email, FILTER_VALIDATE_EMAIL);
            });
            $recipients = array_merge($recipients, $additionalRecipients);
        }

        // Remove duplicates and empty values
        $recipients = array_unique(array_filter($recipients));

        if (empty($recipients)) {
            return;
        }

        // Prepare email content
        $subject = Craft::t('content-reminder',
            '{section} needs content review',
            ['section' => $section->section->name]
        );

        $body = Craft::$app->getView()->renderTemplate(
            'content-reminder/_mail/section-review-notification',
            [
                'section' => $section,
                'cpUrl' => Craft::$app->getConfig()->getGeneral()->cpTrigger . '/content-reminder',
            ]
        );

        // Send the email
        $message = new Message();
        $message->setFrom(Craft::$app->mailer->from);
        $message->setTo($recipients);
        $message->setSubject($subject);
        $message->setHtmlBody($body);

        Craft::$app->getMailer()->send($message);
    }

    /**
     * Check for content that needs review and send notifications
     */
    public function sendPendingReviewNotifications(): void
    {
        $settings = ContentReminder::getInstance()->getSettings();

        // Check if any notifications are enabled
        if (!$settings->enableEmailNotifications && !$settings->enableSlackNotifications) {
            Craft::info('No notification methods are enabled.', __METHOD__);
            return;
        }

        $now = new DateTime();
        $warningDate = (new DateTime())->modify("+{$settings->warningThresholdDays} days");

        // Check sections
        $sections = (new \craft\db\Query())
            ->select(['id'])
            ->from('{{%ContentReminder_sections}}')
            ->where(['or',
                ['<=', 'nextReviewDate', $now->format('Y-m-d H:i:s')],
                ['and',
                    ['<=', 'nextReviewDate', $warningDate->format('Y-m-d H:i:s')],
                    ['>', 'nextReviewDate', $now->format('Y-m-d H:i:s')]
                ]
            ])
            ->all();

        $sectionsNeedingReview = [];
        foreach ($sections as $sectionData) {
            $section = ContentReminder::getInstance()->sections->getBySection($sectionData['id']);
            if ($section) {
                $sectionsNeedingReview[] = $section;

                // Send individual email notifications
                if ($settings->enableEmailNotifications) {
                    $this->sendEmailNotification($section);
                }
            }
        }

        // Send a single Slack notification for all sections
        if ($settings->enableSlackNotifications && !empty($sectionsNeedingReview)) {
            $this->sendSlackNotification($sectionsNeedingReview);
        }
    }
}
