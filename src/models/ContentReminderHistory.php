<?php

namespace honchoagency\contentreminder\models;

use Craft;
use craft\base\Model;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\Site;

/**
 * Content Review History model
 */
class ContentReminderHistory extends Model
{
    /**
     * @var int|null ID
     */
    public ?int $id = null;

    /**
     * @var int Entry ID
     */
    public int $entryId;

    /**
     * @var int Site ID
     */
    public int $siteId;

    /**
     * @var int|null User ID
     */
    public ?int $userId = null;

    /**
     * @var string|null Notes
     */
    public ?string $notes = null;

    /**
     * @var Entry|null
     */
    private ?Entry $_entry = null;

    /**
     * @var User|null
     */
    private ?User $_user = null;

    /**
     * @var Site|null
     */
    private ?Site $_site = null;

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['entryId', 'siteId'], 'required'];
        $rules[] = [['entryId', 'siteId', 'userId'], 'integer'];
        $rules[] = ['notes', 'string'];
        return $rules;
    }

    /**
     * Returns the associated entry
     */
    public function getEntry(): ?Entry
    {
        if (!isset($this->_entry)) {
            if (!$this->entryId) {
                return null;
            }
            $this->_entry = Craft::$app->getEntries()->getEntryById($this->entryId, $this->siteId);
        }
        return $this->_entry;
    }

    /**
     * Returns the user who performed the review
     */
    public function getUser(): ?User
    {
        if (!isset($this->_user)) {
            if (!$this->userId) {
                return null;
            }
            $this->_user = Craft::$app->getUsers()->getUserById($this->userId);
        }
        return $this->_user;
    }

    /**
     * Returns the site
     */
    public function getSite(): ?Site
    {
        if (!isset($this->_site)) {
            if (!$this->siteId) {
                return null;
            }
            $this->_site = Craft::$app->getSites()->getSiteById($this->siteId);
        }
        return $this->_site;
    }
}
