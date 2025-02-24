<?php

namespace honchoagency\contentreminder\widgets;

use honchoagency\contentreminder\ContentReminder;
use honchoagency\contentreminder\models\ContentReminderSection;
use Craft;
use craft\base\Widget;
use DateTime;

class ReviewDashboardWidget extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('content-reminder', 'Content Review Dashboard');
    }

    public static function iconPath(): ?string
    {
        return Craft::getAlias('@app/icons/check.svg');
    }

    public function getBodyHtml(): ?string
    {
        $sections = $this->getSectionsNeedingReview();

        return Craft::$app->getView()->renderTemplate('content-reminder/_widgets/review-dashboard', [
            'sections' => $sections,
            'widget' => $this,
        ]);
    }

    public function getSectionsNeedingReview(): array
    {
        $settings = ContentReminder::getInstance()->getSettings();
        $warningThreshold = $settings->warningThresholdDays;

        $now = (new DateTime())->format('Y-m-d H:i:s');
        $warningDate = (new DateTime())->modify("+$warningThreshold days")->format('Y-m-d H:i:s');

        $results = (new \craft\db\Query())
            ->select([
                'id',
                'sectionId',
                'reviewDays',
                'lastReviewedBy',
                'lastReviewedAt',
                'nextReviewDate'
            ])
            ->from('{{%ContentReminder_sections}}')
            ->where(['or',
                ['<=', 'nextReviewDate', $now], // Overdue or due today
                ['and',
                    ['<=', 'nextReviewDate', $warningDate], // Within warning threshold
                    ['>', 'nextReviewDate', $now] // But not overdue
                ]
            ])
            ->orderBy(['nextReviewDate' => SORT_ASC])
            ->all();

        // Convert to models and parse dates
        return array_map(function($result) {
            if ($result['lastReviewedAt']) {
                $result['lastReviewedAt'] = new DateTime($result['lastReviewedAt']);
            }
            if ($result['nextReviewDate']) {
                $result['nextReviewDate'] = new DateTime($result['nextReviewDate']);
            }
            return new ContentReminderSection($result);
        }, $results);
    }
}
