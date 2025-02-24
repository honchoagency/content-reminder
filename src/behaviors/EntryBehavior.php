<?php

namespace honchoagency\contentreminder\behaviors;

use honchoagency\contentreminder\ContentReminder;
use Craft;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\helpers\Html;
use DateTime;
use yii\base\Behavior;

/**
 * Entry behavior
 */
class EntryBehavior extends Behavior
{
    /**
     * @inheritdoc
     */
    public function events(): array
    {
        return [
            Entry::EVENT_DEFINE_SIDEBAR_HTML => [$this, 'onDefineEntryMetaHtml'],
            Entry::EVENT_DEFINE_META_FIELDS_HTML => [$this, 'onDefineEntryMetaFieldsHtml'],
        ];
    }

    /**
     * Adds the review date to the entry sidebar
     */
    public function onDefineEntryMetaHtml(DefineHtmlEvent $event): void
    {
        /** @var Entry $entry */
        $entry = $this->owner;

        // Only show for saved entries
        if (!$entry->id) {
            return;
        }

        $reviewSection = ContentReminder::getInstance()->sections->getBySection($entry->getSection()->id);
        if (!$reviewSection) {
            return;
        }

        // Add warning banner if needed
        if ($reviewSection->getNeedsReview()) {
            $event->html .= Html::tag('div',
                Craft::t('content-reminder', 'This section needs to be reviewed.'),
                ['class' => 'warning with-icon']
            );
        } elseif ($reviewSection->getIsApproachingReview()) {
            $event->html .= Html::tag('div',
                Craft::t('content-reminder', 'This section will need review soon.'),
                ['class' => 'notice with-icon']
            );
        }
    }

    /**
     * Adds the review date field to the entry sidebar
     */
    public function onDefineEntryMetaFieldsHtml(DefineHtmlEvent $event): void
    {
        /** @var Entry $entry */
        $entry = $this->owner;

        // Only show for saved entries
        if (!$entry->id) {
            return;
        }

        // Get the section's review information
        $reviewSection = ContentReminder::getInstance()->sections->getBySection($entry->getSection()->id);

        $event->html .= Craft::$app->getView()->renderTemplate('content-reminder/_includes/entry-meta.twig', [
            'reviewSection' => $reviewSection,
            'entry' => $entry,
        ]);
    }
}
