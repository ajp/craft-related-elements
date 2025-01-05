<?php

namespace mindseekermedia\craftrelatedelements;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\fields\Matrix;
use mindseekermedia\craftrelatedelements\models\Settings;
use yii\base\Event;

/**
 * Related Elements plugin
 *
 * @method static RelatedElements getInstance()
 * @author Mindseeker Media <dev@mindseeker.media>
 * @copyright Mindseeker Media
 * @license MIT
 */
class RelatedElements extends Plugin
{
    private static ?RelatedElements $plugin;
    /**
     * @var null|Settings
     */
    public static ?Settings $settings = null;
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();

        self::$plugin = $this;
        /** @var Settings $settings */
        $settings = self::$plugin->getSettings();
        self::$settings = $settings;

        Craft::$app->onInit(fn() => $this->attachEventHandlers());
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            Element::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            fn(DefineHtmlEvent $event) => $event->html .=
                ($event->sender instanceof Entry ||
                    $event->sender instanceof Category ||
                    $event->sender instanceof Asset)
                    ? $this->renderTemplate($event->sender)
                    : ''
        );
    }

    private function renderTemplate(Entry|Category|Asset $entry): string
    {
        $relatedTypes = [
            'Entry' => Entry::class,
            'Category' => Category::class,
            'Asset' => Asset::class,
        ];

        $relatedElements = [];
        $nestedRelatedElements = [];
        $hasResults = false;
        $enableNestedElements = self::$settings->enableNestedElements;

        foreach ($relatedTypes as $type => $class) {
            $relatedElements[$type] = $class::find()
                ->relatedTo($entry)
                ->status(null)
                ->orderBy('title')
                ->siteId('*')
                ->unique()
                ->all();

            if (!empty($relatedElements[$type])) {
                $hasResults = true;
            }
        }

        if ($enableNestedElements) {
            $this->findNestedElements($entry->getFieldLayout()->getCustomFields(), $entry, $nestedRelatedElements, $hasResults, $relatedTypes);
        }

        return Craft::$app->getView()->renderTemplate(
            'related-elements/_element-sidebar',
            [
                'hasResults' => $hasResults,
                'relatedElements' => $relatedElements,
                'nestedRelatedElements' => $nestedRelatedElements,
            ]
        );
    }

    private function findNestedElements(array $fields, Element $element, array &$nestedRelatedElements, bool &$hasResults, array $relatedTypes, string $fieldPath = ''): void
    {
        foreach ($fields as $field) {
            $isMatrixField = $field instanceof Matrix;
            $isNeoField = class_exists('\benf\neo\Field') && get_class($field) === \benf\neo\Field::class;

            if ($isMatrixField || $isNeoField) {
                $blocks = $element->getFieldValue($field->handle);
                $fieldName = $fieldPath ? $fieldPath . ' → ' . $field->name : $field->name;

                if (!isset($nestedRelatedElements[$fieldName])) {
                    $nestedRelatedElements[$fieldName] = [];
                }

                foreach ($blocks->all() as $block) {
                    foreach ($relatedTypes as $type => $class) {
                        $newElements = $class::find()
                            ->relatedTo($block)
                            ->status(null)
                            ->orderBy('title')
                            ->siteId('*')
                            ->unique()
                            ->all();

                        if (!empty($newElements)) {
                            if (!isset($nestedRelatedElements[$fieldName][$type])) {
                                $nestedRelatedElements[$fieldName][$type] = [];
                            }

                            foreach ($newElements as $newElement) {
                                $exists = false;
                                foreach ($nestedRelatedElements[$fieldName][$type] as $existingElement) {
                                    if ($existingElement->id === $newElement->id) {
                                        $exists = true;
                                        break;
                                    }
                                }

                                if (!$exists) {
                                    $nestedRelatedElements[$fieldName][$type][] = $newElement;
                                    $hasResults = true;
                                }
                            }
                        }
                    }

                    // Recursively check for nested Matrix/Neo fields within this block
                    $blockFields = $block->getFieldLayout()->getCustomFields();
                    if (!empty($blockFields)) {
                        $this->findNestedElements(
                            $blockFields,
                            $block,
                            $nestedRelatedElements,
                            $hasResults,
                            $relatedTypes,
                            $fieldName
                        );
                    }
                }
            }
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return \Craft::$app->getView()->renderTemplate(
            'related-elements/settings',
            [ 'settings' => $this->getSettings() ]
        );
    }
}
