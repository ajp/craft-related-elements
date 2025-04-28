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

    private function renderTemplate(Element $element): string
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
        $currentSiteId = $element->siteId;
        $currentSiteHandle = Craft::$app->getSites()->getSiteById($currentSiteId)->handle;

        foreach ($relatedTypes as $type => $class) {
            $elements = $class::find()
                ->relatedTo($element)
                ->status(null)
                ->site('*')
                ->unique()
                ->preferSites([$currentSiteHandle])
                ->orderBy('title')
                ->all();

            $relatedElements[$type] = array_filter($elements, function($el) {
                try {
                    return $el->getFieldLayout() !== null;
                } catch (\Throwable $e) {
                    Craft::error("Error checking field layout for element {$el->id}: " . $e->getMessage(), __METHOD__);
                    return false;
                }
            });

            if (!empty($relatedElements[$type])) {
                $hasResults = true;
            }
        }

        if ($enableNestedElements) {
            $fieldLayout = $element->getFieldLayout();
            $this->findNestedElements(
                $fieldLayout ? $fieldLayout->getCustomFields() : [],
                $element,
                $nestedRelatedElements,
                $hasResults,
                $relatedTypes
            );
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
        if (!$element || !$element->siteId) {
            return;
        }

        try {
            $currentSiteId = $element->siteId;
            $currentSite = Craft::$app->getSites()->getSiteById($currentSiteId);

            if (!$currentSite) {
                return;
            }

            $currentSiteHandle = $currentSite->handle;

            foreach ($fields as $field) {
                if (!$field || !$field->handle) {
                    continue;
                }

                $isMatrixField = $field instanceof Matrix;
                $isNeoField = class_exists('\benf\neo\Field') && get_class($field) === \benf\neo\Field::class;

                if ($isMatrixField || $isNeoField) {
                    try {
                        $blocks = $element->getFieldValue($field->handle);

                        if (!$blocks) {
                            continue;
                        }

                        $fieldName = $fieldPath ? $fieldPath . ' → ' . $field->name : $field->name;

                        if (!isset($nestedRelatedElements[$fieldName])) {
                            $nestedRelatedElements[$fieldName] = [];
                        }

                        foreach ($blocks->all() as $block) {
                            if (!$block) {
                                continue;
                            }

                            try {
                                $fieldLayout = $block->getFieldLayout();
                                if (!$fieldLayout) {
                                    continue;
                                }

                                // For Neo blocks, ensure they have a valid type
                                if ($isNeoField && $block instanceof \benf\neo\elements\Block) {
                                    if (!$block->getType()) {
                                        continue;
                                    }
                                }

                                foreach ($relatedTypes as $type => $class) {
                                    $newElements = $class::find()
                                        ->relatedTo($block)
                                        ->status(null)
                                        ->site('*')
                                        ->unique()
                                        ->preferSites([$currentSiteHandle])
                                        ->orderBy('title')
                                        ->all();

                                    $filteredElements = array_filter($newElements, function($el) {
                                        try {
                                            return $el->getFieldLayout() !== null;
                                        } catch (\Throwable $e) {
                                            Craft::error("Error checking nested element layout {$el->id}: " . $e->getMessage(), __METHOD__);
                                            return false;
                                        }
                                    });

                                    if (!empty($filteredElements)) {
                                        if (!isset($nestedRelatedElements[$fieldName][$type])) {
                                            $nestedRelatedElements[$fieldName][$type] = [];
                                        }

                                        foreach ($filteredElements as $newElement) {
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
                                $blockFields = $fieldLayout->getCustomFields();
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
                            } catch (\Throwable $e) {
                                // Log the error but continue processing other blocks
                                Craft::warning('Error processing block in Related Elements plugin: ' . $e->getMessage(), __METHOD__);
                                continue;
                            }
                        }
                    } catch (\Throwable $e) {
                        // Log the error but continue processing other fields
                        Craft::warning('Error processing field in Related Elements plugin: ' . $e->getMessage(), __METHOD__);
                        continue;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Log the error but don't throw it
            Craft::error('Error in Related Elements plugin: ' . $e->getMessage(), __METHOD__);
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
