<?php

namespace honchoagency\contentreminder\controllers;

use honchoagency\contentreminder\ContentReminder;
use Craft;
use craft\web\Controller;
use yii\web\Response;
use DateTime;
use craft\helpers\StringHelper;

class SectionsController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    public function actionMarkReviewed(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $sectionId = Craft::$app->getRequest()->getRequiredBodyParam('sectionId');
        $currentUser = Craft::$app->getUser()->getIdentity();

        // Get the section's review period
        $section = ContentReminder::getInstance()->sections->getBySection($sectionId);
        if (!$section) {
            return $this->asJson([
                'success' => false,
                'error' => 'Section not found.'
            ]);
        }

        // Calculate next review date based on review period
        $nextReviewDate = (new DateTime())->modify("+{$section->reviewDays} days");

        // Update the review data
        $success = Craft::$app->getDb()->createCommand()
            ->update('{{%ContentReminder_sections}}', [
                'lastReviewedBy' => $currentUser->id,
                'lastReviewedAt' => (new DateTime())->format('Y-m-d H:i:s'),
                'nextReviewDate' => $nextReviewDate->format('Y-m-d H:i:s'),
            ], ['sectionId' => $sectionId])
            ->execute();

        // Also log this review in the history table
        if ($success) {
            Craft::$app->getDb()->createCommand()
                ->insert('{{%ContentReminder_history}}', [
                    'sectionId' => $sectionId,
                    'userId' => $currentUser->id,
                    'dateCreated' => (new DateTime())->format('Y-m-d H:i:s'),
                    'dateUpdated' => (new DateTime())->format('Y-m-d H:i:s'),
                    'uid' => StringHelper::UUID(),
                ])
                ->execute();
        }

        if ($success) {
            return $this->asJson(['success' => true]);
        }

        return $this->asJson([
            'success' => false,
            'error' => 'Could not mark section as reviewed.'
        ]);
    }

    public function actionUpdateReviewPeriod(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $sectionId = Craft::$app->getRequest()->getRequiredBodyParam('sectionId');
        $reviewDays = Craft::$app->getRequest()->getRequiredBodyParam('reviewDays');

        if (ContentReminder::getInstance()->sections->updateReviewPeriod($sectionId, $reviewDays)) {
            return $this->asJson(['success' => true]);
        }

        return $this->asJson(['success' => false, 'error' => 'Could not update review period.']);
    }
}
