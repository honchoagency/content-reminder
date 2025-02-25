<?php

namespace honchoagency\contentreminder\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table;
use craft\helpers\StringHelper;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%ContentReminder_sections}}')) {
            $this->createTable('{{%ContentReminder_sections}}', [
                'id' => $this->primaryKey(),
                'sectionId' => $this->integer()->notNull(),
                'reviewDays' => $this->integer()->notNull()->defaultValue(30),
                'lastReviewedBy' => $this->integer(),
                'lastReviewedAt' => $this->dateTime(),
                'nextReviewDate' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(
                null,
                '{{%ContentReminder_sections}}',
                ['sectionId'],
                true
            );

            $this->addForeignKey(
                null,
                '{{%ContentReminder_sections}}',
                'sectionId',
                Table::SECTIONS,
                'id',
                'CASCADE',
                'CASCADE'
            );

            $this->addForeignKey(
                null,
                '{{%ContentReminder_sections}}',
                'lastReviewedBy',
                Table::USERS,
                'id',
                'SET NULL',
                'CASCADE'
            );
        }

        // Always try to populate sections, even if table existed
        try {
            $sections = Craft::$app->entries->getAllSections();
            echo "\nFound " . count($sections) . " sections to process.\n";

            foreach ($sections as $section) {
                try {
                    // Check if section already has a review entry
                    $exists = (new \craft\db\Query())
                        ->from('{{%ContentReminder_sections}}')
                        ->where(['sectionId' => $section->id])
                        ->exists();

                    if (!$exists) {
                        $this->insert('{{%ContentReminder_sections}}', [
                            'sectionId' => $section->id,
                            'reviewDays' => 30,
                            'nextReviewDate' => (new \DateTime())->modify('+30 days')->format('Y-m-d H:i:s'),
                            'dateCreated' => (new \DateTime())->format('Y-m-d H:i:s'),
                            'dateUpdated' => (new \DateTime())->format('Y-m-d H:i:s'),
                            'uid' => StringHelper::UUID(),
                        ]);
                        echo "Added review entry for section: {$section->name}\n";
                    } else {
                        echo "Section already has review entry: {$section->name}\n";
                    }
                } catch (\Exception $e) {
                    echo "Error adding section {$section->name}: {$e->getMessage()}\n";
                }
            }
        } catch (\Exception $e) {
            echo "Error getting sections: {$e->getMessage()}\n";
            // Log the full error for debugging
            Craft::error('Error in content-reminder plugin installation: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%ContentReminder_sections}}');

        return true;
    }
}
