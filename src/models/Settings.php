<?php

namespace honchoagency\contentreminder\models;

use Craft;
use craft\base\Model;

/**
 * Content Review Settings
 */
class Settings extends Model
{
    /**
     * @var int Default number of days between reviews
     */
    public int $defaultReviewDays = 30;

    /**
     * @var array Section-specific review periods (in days)
     */
    public array $sectionReviewDays = [];

    /**
     * @var int Number of days before review date to start showing warnings
     */
    public int $warningThresholdDays = 7;

    /**
     * @var bool Whether to show CMS notifications
     */
    public bool $enableCmsNotifications = true;

    /**
     * @var bool Whether to send email notifications
     */
    public bool $enableEmailNotifications = false;

    /**
     * @var bool Whether to send Slack notifications
     */
    public bool $enableSlackNotifications = false;

    /**
     * @var string Slack webhook URL for notifications
     */
    public string $slackWebhookUrl = '';

    /**
     * @var array Email addresses to notify (in addition to content authors)
     */
    public array $additionalEmailRecipients = [];

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['defaultReviewDays', 'warningThresholdDays'], 'required'],
            [['defaultReviewDays', 'warningThresholdDays'], 'integer', 'min' => 1],
            [['enableCmsNotifications', 'enableEmailNotifications', 'enableSlackNotifications'], 'boolean'],
            ['slackWebhookUrl', 'url', 'when' => function($model) {
                return $model->enableSlackNotifications;
            }],
            ['additionalEmailRecipients', 'each', 'rule' => ['email']],
            ['sectionReviewDays', 'safe'],
        ];
    }
}
