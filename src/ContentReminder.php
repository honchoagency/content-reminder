<?php

namespace honchoagency\contentreminder;

use honchoagency\contentreminder\behaviors\EntryBehavior;
use Craft;
use honchoagency\contentreminder\models\Settings;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use yii\base\Event;
use craft\base\Widget;
use craft\events\RegisterComponentTypesEvent;
use honchoagency\contentreminder\widgets\ReminderDashboardWidget;
use craft\services\Dashboard;
use honchoagency\contentreminder\services\NotificationService;
use craft\helpers\Queue;
use craft\queue\BaseJob;
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

        // Register the widget
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = ReminderDashboardWidget::class;
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

                    // Get overdue sections
                    $widget = new ReminderDashboardWidget();
                    $sections = $widget->getSectionsNeedingReview();
                    $overdueSections = array_filter($sections, function($section) {
                        return $section->nextReviewDate <= new \DateTime();
                    });

                    if (!empty($overdueSections)) {
                        $alertHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 2L1 21h22L12 2zm0 3.99L19.53 19H4.47L12 5.99zM11 16h2v2h-2zm0-6h2v4h-2z" fill="#CF1124"/>
                        </svg>';

                        $numSections = count($overdueSections);
                        $message = $numSections === 1
                            ? "1 section is due a content reminder"
                            : "$numSections sections is due a content reminder";

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
        // Create a job to check for content that needs review
        $job = new class extends BaseJob {
            public function execute($queue): void
            {
                ContentReminder::getInstance()->notifications->sendPendingReviewNotifications();
            }

            public function getDescription(): string
            {
                return Craft::t('content-reminder', 'Checking for content that needs review');
            }
        };

        // Schedule the job to run daily at midnight
        $now = new \DateTime();
        $nextRun = new \DateTime('tomorrow midnight');
        $delay = $nextRun->getTimestamp() - $now->getTimestamp();

        Queue::push($job, null, null, $delay);
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
