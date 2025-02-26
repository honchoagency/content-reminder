<?php

namespace honchoagency\contentreminder;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\base\Widget;
use craft\elements\Entry;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\Queue;
use craft\services\Dashboard;
use craft\web\UrlManager;
use honchoagency\contentreminder\behaviors\EntryBehavior;
use honchoagency\contentreminder\models\Settings;
use honchoagency\contentreminder\services\NotificationService;
use honchoagency\contentreminder\widgets\ReviewDashboardWidget;
use yii\base\Event;
use craft\events\PluginEvent;
use craft\services\Plugins;

/**
 * content-reminder plugin
 *
 * @method static ContentReminder getInstance()
 * @method Settings getSettings()
 * @author Honcho Agency <dev@honcho.agency>
 * @copyright Honcho Agency
 * @license https://craftcms.github.io/license/ Craft License
 */
class ContentReminder extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();

        // Register console commands
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'honchoagency\contentreminder\console\controllers';
        } else {
            $this->controllerNamespace = 'honchoagency\contentreminder\controllers';
        }

        // Listen for plugin settings being saved
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_SAVE_PLUGIN_SETTINGS,
            function(PluginEvent $event) {
                if ($event->plugin === $this) {
                    $settings = $this->getSettings();
                    $sections = Craft::$app->entries->getAllSections();

                    foreach ($sections as $section) {
                        $reviewSection = $this->sections->getBySection($section->id);
                        if ($reviewSection) {
                            // Get section-specific review days or fall back to default
                            $reviewDays = isset($settings->sectionReviewDays[$section->handle]) && $settings->sectionReviewDays[$section->handle] !== '' 
                                ? (int)$settings->sectionReviewDays[$section->handle] 
                                : (int)$settings->defaultReviewDays;
                            
                            // Only update if the review days have changed
                            if ($reviewDays != $reviewSection->reviewDays) {
                                $this->sections->updateReviewPeriod($section->id, $reviewDays);
                            }
                        }
                    }
                }
            }
        );

        // Register the widget
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = ReviewDashboardWidget::class;
            }
        );

        // Register CP routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['content-reminder'] = 'content-reminder/default/index';
                $event->rules['content-reminder/settings'] = 'content-reminder/settings/index';
            }
        );

        // Attach the entry behavior
        Event::on(
            Entry::class,
            Entry::EVENT_DEFINE_BEHAVIORS,
            function(\yii\base\Event $event) {
                /** @var Entry $entry */
                $entry = $event->sender;
                $entry->attachBehavior('contentReminder', EntryBehavior::class);
            }
        );

        // Add CP alert for overdue sections
        if (Craft::$app->getRequest()->isCpRequest) {
            Event::on(
                \craft\helpers\Cp::class,
                \craft\helpers\Cp::EVENT_REGISTER_ALERTS,
                function(\craft\events\RegisterCpAlertsEvent $event) {
                    // Only show alerts if user has permission
                    if (!Craft::$app->getUser()->getIsAdmin() && !Craft::$app->getUser()->checkPermission('accessPlugin-content-reminder')) {
                        return;
                    }

                    // Get settings
                    $settings = $this->getSettings();
                    $warningThreshold = (int)$settings->warningThresholdDays;
                    
                    // Get sections needing review
                    $widget = new ReviewDashboardWidget();
                    $sections = $widget->getSectionsNeedingReview();
                    
                    // Check for sections within warning threshold
                    $warningDate = (new \DateTime())->modify("+{$warningThreshold} days");
                    $warningSections = array_filter($sections, function($section) use ($warningDate) {
                        return $section->nextReviewDate <= $warningDate;
                    });

                    if (!empty($warningSections)) {
                        $numSections = count($warningSections);
                        $message = $numSections === 1
                            ? "<strong>  Content Reminder:</strong> 1 section needs reviewing"
                            : "<strong>  Content Reminder:</strong>  " . $numSections . " sections need reviewing";

                        $url = Craft::$app->getConfig()->getGeneral()->cpTrigger . '/content-reminder';
                        $alertHTML = '<span data-icon="alert"></span>';
                        $alertHTML .= $message;
                        $alertHTML .= ' <a href="/' . $url . '" class="go">' . Craft::t('content-reminder', 'View sections') . ' →</a>';

                        $event->alerts[] = [
                            'content' => $alertHTML,
                            'showIcon' => false,
                        ];
                    }
                }
            );
        }

        // Schedule daily notification check if not already scheduled
        Craft::info('Checking if content reminder job needs scheduling...', __METHOD__);
        if (!$this->isJobScheduled()) {
            Craft::info('No existing job found, scheduling new one...', __METHOD__);
            $this->scheduleNotificationCheck();
        } else {
            Craft::info('Content reminder job already scheduled', __METHOD__);
        }
    }

    public static function config(): array
    {
        return [
            'components' => [
                'sections' => \honchoagency\contentreminder\services\Sections::class,
                'notifications' => NotificationService::class,
            ],
        ];
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('content-reminder', 'Content Reminder');
        $item['subnav'] = [
            'dashboard' => ['label' => Craft::t('content-reminder', 'Dashboard'), 'url' => 'content-reminder'],
            'settings' => ['label' => Craft::t('content-reminder', 'Settings'), 'url' => 'content-reminder/settings'],
        ];
        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        $allSections = Craft::$app->entries->getAllSections();

        return Craft::$app->view->renderTemplate('content-reminder/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'sections' => $allSections,
        ]);
    }

    private function isJobScheduled(): bool
    {
        $jobs = Craft::$app->queue->getJobInfo();
        
        foreach ($jobs as $job) {
            if ($job['description'] === Craft::t('content-reminder', 'Checking for content that needs review')) {
                return true;
            }
        }

        return false;
    }

    private function scheduleNotificationCheck(): void
    {
        // Schedule the job to run daily at midnight
        $now = new \DateTime();
        $nextRun = new \DateTime('tomorrow midnight');
        $delay = $nextRun->getTimestamp() - $now->getTimestamp();

        Craft::info(
            "Scheduling content reminder check job for: " . $nextRun->format('Y-m-d H:i:s') . 
            " (delay: {$delay} seconds)",
            __METHOD__
        );

        Queue::push(new jobs\ContentReminderCheckJob(), null, null, $delay);
    }

    /**
     * @inheritdoc
     */
    public function install(): void
    {
        parent::install();

        Craft::info('Installing Content Reminder plugin...', __METHOD__);

        // Run the install migration
        $migration = new \honchoagency\contentreminder\migrations\Install();
        $migration->up();

        // Schedule the initial notification check
        Craft::info('Scheduling initial content reminder check...', __METHOD__);
        $this->scheduleNotificationCheck();
    }

    public function getConsoleControllerNamespace(): string
    {
        return 'honchoagency\contentreminder\console\controllers';
    }
}
