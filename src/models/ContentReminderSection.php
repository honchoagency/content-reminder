<?php

namespace honchoagency\contentreminder\models;

use Craft;
use craft\base\Model;
use craft\models\Section;
use craft\elements\User;
use DateTime;

/**
 * Content Review Section model
 */
class ContentReminderSection extends Model
{
    /**
     * @var int|null ID
     */
    public ?int $id = null;

    /**
     * @var int Section ID
     */
    public int $sectionId;

    /**
     * @var int Number of days between reviews
     */
    public int $reviewDays = 30;

    /**
     * @var int|null Last reviewed by user ID
     */
    public ?int $lastReviewedBy = null;

    /**
     * @var DateTime|null Last reviewed at
     */
    public ?DateTime $lastReviewedAt = null;

    /**
     * @var DateTime Next review date
     */
    public DateTime $nextReviewDate;

    /**
     * @var Section|null
     */
    private ?Section $_section = null;

    /**
     * @var User|null
     */
    private ?User $_reviewer = null;

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['sectionId', 'reviewDays'], 'required'];
        $rules[] = [['sectionId', 'reviewDays', 'lastReviewedBy'], 'integer'];
        $rules[] = ['reviewDays', 'integer', 'min' => 1];
        $rules[] = [['lastReviewedAt', 'nextReviewDate'], 'datetime', 'skipOnEmpty' => true];
        return $rules;
    }

    /**
     * Returns the associated section
     */
    public function getSection(): ?Section
    {
        if (!isset($this->_section)) {
            if (!$this->sectionId) {
                return null;
            }
            $this->_section = Craft::$app->entries->getSectionById($this->sectionId);
        }
        return $this->_section;
    }

    /**
     * Returns the reviewer
     */
    public function getReviewer(): ?User
    {
        if (!isset($this->_reviewer)) {
            if (!$this->lastReviewedBy) {
                return null;
            }
            $this->_reviewer = Craft::$app->getUsers()->getUserById($this->lastReviewedBy);
        }
        return $this->_reviewer;
    }

    /**
     * Returns whether the section needs review
     */
    public function getNeedsReview(): bool
    {
        return $this->nextReviewDate <= new DateTime();
    }

    /**
     * Returns whether the section is approaching review date
     */
    public function getIsApproachingReview(): bool
    {
        $settings = Craft::$app->getPlugins()->getPlugin('content-reminder')->getSettings();
        $warningDays = $settings->warningThresholdDays;
        $warningDate = (new DateTime())->modify("+$warningDays days");

        return $this->nextReviewDate <= $warningDate && $this->nextReviewDate > new DateTime();
    }

    /**
     * Updates the next review date based on reviewDays
     */
    public function updateNextReviewDate(): void
    {
        $this->nextReviewDate = (new DateTime())->modify("+{$this->reviewDays} days");
    }
}
