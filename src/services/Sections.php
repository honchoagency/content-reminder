<?php

namespace honchoagency\contentreminder\services;

use honchoagency\contentreminder\models\ContentReminderSection;
use Craft;
use craft\base\Component;
use craft\events\SectionEvent;
use craft\services\Entries;
use DateTime;
use yii\base\Event;
use craft\helpers\StringHelper;

/**
 * Sections service
 */
class Sections extends Component
{
    /**
     * @event Event The event that is triggered before a section is marked as reviewed
     */
    public const EVENT_BEFORE_MARK_REVIEWED = 'beforeMarkReviewed';

    /**
     * @event Event The event that is triggered after a section is marked as reviewed
     */
    public const EVENT_AFTER_MARK_REVIEWED = 'afterMarkReviewed';

    /**
     * Returns a section review by its section ID
     */
    public function getBySection(int $sectionId): ?ContentReminderSection
    {
        $result = Craft::$app->getDb()->createCommand(
            <<<SQL
            SELECT [[id]], [[sectionId]], [[reviewDays]], [[lastReviewedBy]], [[lastReviewedAt]], [[nextReviewDate]]
            FROM {{%ContentReminder_sections}}
            WHERE [[sectionId]] = :sectionId
            SQL,
            ['sectionId' => $sectionId]
        )->queryOne();

        if ($result) {
            if ($result['lastReviewedAt']) {
                $result['lastReviewedAt'] = new DateTime($result['lastReviewedAt']);
            }
            if ($result['nextReviewDate']) {
                $result['nextReviewDate'] = new DateTime($result['nextReviewDate']);
            }
            return new ContentReminderSection($result);
        }

        return null;
    }

    /**
     * Marks a section as reviewed
     */
    public function markReviewed(int $sectionId): bool
    {
        $section = $this->getBySection($sectionId);
        if (!$section) {
            return false;
        }

        // Trigger before event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_MARK_REVIEWED)) {
            $this->trigger(self::EVENT_BEFORE_MARK_REVIEWED, new Event());
        }

        // Update review data
        $section->lastReviewedBy = Craft::$app->getUser()->getId();
        $section->lastReviewedAt = new DateTime();
        $section->updateNextReviewDate();

        // Save to database
        $success = Craft::$app->getDb()->createCommand()
            ->update(
                '{{%ContentReminder_sections}}',
                [
                    'lastReviewedBy' => $section->lastReviewedBy,
                    'lastReviewedAt' => $section->lastReviewedAt->format('Y-m-d H:i:s'),
                    'nextReviewDate' => $section->nextReviewDate->format('Y-m-d H:i:s'),
                ],
                ['sectionId' => $section->sectionId]
            )
            ->execute();

        // Trigger after event
        if ($success && $this->hasEventHandlers(self::EVENT_AFTER_MARK_REVIEWED)) {
            $this->trigger(self::EVENT_AFTER_MARK_REVIEWED, new Event());
        }

        return $success;
    }

    /**
     * Updates a section's review period
     */
    public function updateReviewPeriod(int $sectionId, int $reviewDays): bool
    {
        $section = $this->getBySection($sectionId);
        if (!$section) {
            return false;
        }

        $section->reviewDays = $reviewDays;
        $section->updateNextReviewDate();

        return (bool)Craft::$app->getDb()->createCommand()
            ->update(
                '{{%ContentReminder_sections}}',
                [
                    'reviewDays' => $section->reviewDays,
                    'nextReviewDate' => $section->nextReviewDate->format('Y-m-d H:i:s'),
                ],
                ['sectionId' => $section->sectionId]
            )
            ->execute();
    }

    /**
     * Initializes section review tracking
     */
    public function init(): void
    {
        parent::init();

        // Listen for when sections are saved
        Event::on(
            Entries::class,
            Entries::EVENT_AFTER_SAVE_SECTION,
            function(SectionEvent $event) {
                if ($event->isNew) {
                    // Get the default review period from settings
                    $settings = Craft::$app->getPlugins()->getPlugin('content-reminder')->getSettings();
                    $defaultReviewDays = $settings->defaultReviewDays ?? 30;

                    Craft::$app->getDb()->createCommand()
                        ->insert('{{%ContentReminder_sections}}', [
                            'sectionId' => $event->section->id,
                            'reviewDays' => $defaultReviewDays,
                            'nextReviewDate' => (new DateTime())->modify("+$defaultReviewDays days")->format('Y-m-d H:i:s'),
                            'dateCreated' => (new DateTime())->format('Y-m-d H:i:s'),
                            'dateUpdated' => (new DateTime())->format('Y-m-d H:i:s'),
                            'uid' => StringHelper::UUID(),
                        ])
                        ->execute();
                }
            }
        );

        // Listen for when sections are deleted
        Event::on(
            Entries::class,
            Entries::EVENT_AFTER_DELETE_SECTION,
            function(SectionEvent $event) {
                // Delete the corresponding content reminder section
                Craft::$app->getDb()->createCommand()
                    ->delete('{{%ContentReminder_sections}}', ['sectionId' => $event->section->id])
                    ->execute();
            }
        );
    }

    /**
     * Syncs content reminder sections with Craft sections
     */
    public function syncSections(): bool
    {
        try {
            $craftSections = Craft::$app->entries->getAllSections();
            $settings = Craft::$app->getPlugins()->getPlugin('content-reminder')->getSettings();
            $defaultReviewDays = $settings->defaultReviewDays ?? 30;
            
            // Get existing content reminder sections
            $existingSections = (new \craft\db\Query())
                ->select(['sectionId'])
                ->from('{{%ContentReminder_sections}}')
                ->column();

            // Add missing sections
            foreach ($craftSections as $section) {
                if (!in_array($section->id, $existingSections)) {
                    Craft::$app->getDb()->createCommand()
                        ->insert('{{%ContentReminder_sections}}', [
                            'sectionId' => $section->id,
                            'reviewDays' => $defaultReviewDays,
                            'nextReviewDate' => (new DateTime())->modify("+$defaultReviewDays days")->format('Y-m-d H:i:s'),
                            'dateCreated' => (new DateTime())->format('Y-m-d H:i:s'),
                            'dateUpdated' => (new DateTime())->format('Y-m-d H:i:s'),
                            'uid' => StringHelper::UUID(),
                        ])
                        ->execute();
                }
            }

            // Remove sections that no longer exist
            $craftSectionIds = array_map(function($section) {
                return $section->id;
            }, $craftSections);

            $sectionsToRemove = array_diff($existingSections, $craftSectionIds);
            if (!empty($sectionsToRemove)) {
                Craft::$app->getDb()->createCommand()
                    ->delete('{{%ContentReminder_sections}}', ['sectionId' => $sectionsToRemove])
                    ->execute();
            }

            return true;
        } catch (\Exception $e) {
            Craft::error('Error syncing content reminder sections: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }
}
