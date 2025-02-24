<?php

namespace honchoagency\contentreminder\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;

class SettingsController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    public function actionIndex(): Response
    {
        return $this->renderTemplate('content-reminder/_settings', [
            'title' => Craft::t('content-reminder', 'Content Review Settings'),
            'plugin' => Craft::$app->plugins->getPlugin('content-reminder'),
            'settings' => Craft::$app->plugins->getPlugin('content-reminder')->getSettings(),
        ]);
    }
}
