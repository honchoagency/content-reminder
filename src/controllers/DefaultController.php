<?php

namespace honchoagency\contentreminder\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;
use honchoagency\contentreminder\widgets\ReviewDashboardWidget;

class DefaultController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    public function actionIndex(): Response
    {
        $widget = new ReviewDashboardWidget();
        $sections = $widget->getSectionsNeedingReview();

        return $this->renderTemplate('content-reminder/_dashboard', [
            'title' => Craft::t('content-reminder', 'Content Review Dashboard'),
            'sections' => $sections,
        ]);
    }
}
