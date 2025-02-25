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
use craft\queue\BaseJob;
use craft\services\Dashboard;
use craft\services\Plugins;
use craft\web\UrlManager;
use honchoagency\contentreminder\behaviors\EntryBehavior;
use honchoagency\contentreminder\models\Settings;
use honchoagency\contentreminder\services\NotificationService;
use honchoagency\contentreminder\widgets\ReviewDashboardWidget;
use yii\base\Event;

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
                        $alertHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 2L1 21h22L12 2zm0 3.99L19.53 19H4.47L12 5.99zM11 16h2v2h-2zm0-6h2v4h-2z" fill="#CF1124"/>
                        </svg>';

                        $numSections = count($warningSections);
                        $message = $numSections === 1
                            ? "1 section needs review soon"
                            : "$numSections sections need review soon";

                        $url = Craft::$app->getConfig()->getGeneral()->cpTrigger . '/content-reminder';
                        $alertHTML .= ' <strong>' . $message . '</strong>';
                        $alertHTML .= ' <a href="/' . $url . '" class="go">' . Craft::t('content-reminder', 'View sections') . ' →</a>';

                        $event->alerts[] = [
                            'content' => $alertHTML,
                            'showIcon' => false,
                        ];
                    }
                }
            );
        }

        // Schedule daily notification check
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function() {
                $this->scheduleNotificationCheck();
            }
        );

        $this->attachEventHandlers();

        // Any code that creates an element query or loads Twig should be deferred until
        // after Craft is fully initialized, to avoid conflicts with other plugins/modules
        Craft::$app->onInit(function() {
            // ...
        });
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

    private function attachEventHandlers(): void
    {
        // Register event handlers here ...
        // (see https://craftcms.com/docs/5.x/extend/events.html to get started)
    }

    private function scheduleNotificationCheck(): void
    {
        // For testing: run every 5 minutes instead of daily
        $delay = 30; // 5 minutes in seconds

        Craft::info(
            "Scheduling next content reminder check in {$delay} seconds",
            __METHOD__
        );

        Queue::push(new jobs\ContentReminderCheckJob(), null, null, $delay);

                // Schedule the job to run daily at midnight - TODO: Add this back in when finished testing
                // $now = new \DateTime();
                // $nextRun = new \DateTime('tomorrow midnight');
                // $delay = $nextRun->getTimestamp() - $now->getTimestamp();
        
                // Queue::push(new jobs\ContentReminderCheckJob(), null, null, $delay);
    }

    /**
     * @inheritdoc
     */
    public function install(): void
    {
        parent::install();

        // Run the install migration
        $migration = new \honchoagency\contentreminder\migrations\Install();
        $migration->up();
    }

    public function getConsoleControllerNamespace(): string
    {
        return 'honchoagency\contentreminder\console\controllers';
    }
}
