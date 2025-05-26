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
use craft\fields\BaseRelationField;
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

        $outgoingRelatedElements = [
            'Entry' => [],
            'Category' => [],
            'Asset' => []
        ];
        $incomingRelatedElements = [
            'Entry' => [],
            'Category' => [],
            'Asset' => []
        ];
        $nestedRelatedElements = [];
        $hasResults = false;
        $enableNestedElements = self::$settings->enableNestedElements;
        $currentSiteId = $element->siteId;
        $currentSiteHandle = Craft::$app->getSites()->getSiteById($currentSiteId)->handle;

        // Find outgoing relationships (elements this entry references)
        $this->findOutgoingRelationships($element, $relatedTypes, $outgoingRelatedElements, $hasResults, $currentSiteHandle);

        // Find incoming relationships (elements that reference this entry)
        $this->findIncomingRelationships($element, $relatedTypes, $incomingRelatedElements, $hasResults, $currentSiteHandle);

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

        // Determine element type for display text
        $elementType = 'element';
        if ($element instanceof Entry) {
            $elementType = 'entry';
        } elseif ($element instanceof Category) {
            $elementType = 'category';
        } elseif ($element instanceof Asset) {
            $elementType = 'asset';
        }

        return Craft::$app->getView()->renderTemplate(
            'related-elements/_element-sidebar',
            [
                'hasResults' => $hasResults,
                'outgoingRelatedElements' => $outgoingRelatedElements,
                'incomingRelatedElements' => $incomingRelatedElements,
                'nestedRelatedElements' => $nestedRelatedElements,
                'initialLimit' => self::$settings->initialLimit,
                'elementType' => $elementType,
            ]
        );
    }

    private function findOutgoingRelationships(Element $element, array $relatedTypes, array &$outgoingRelatedElements, bool &$hasResults, string $currentSiteHandle): void
    {
        try {
            $fieldLayout = $element->getFieldLayout();
            if (!$fieldLayout) {
                return;
            }

            $fields = $fieldLayout->getCustomFields();

            foreach ($fields as $field) {
                if (!$field || !$field->handle) {
                    continue;
                }

                // Check if this is a relational field by checking if it's an instance of BaseRelationField
                $isRelationalField = $field instanceof BaseRelationField;

                if (!$isRelationalField) {
                    continue;
                }

                try {
                    $fieldValue = $element->getFieldValue($field->handle);

                    if (!$fieldValue) {
                        continue;
                    }

                    // Handle different field value types
                    $relatedElements = [];

                    if (is_iterable($fieldValue)) {
                        foreach ($fieldValue as $relatedElement) {
                            if ($relatedElement instanceof Element) {
                                $relatedElements[] = $relatedElement;
                            }
                        }
                    } elseif ($fieldValue instanceof Element) {
                        $relatedElements[] = $fieldValue;
                    }

                    // Categorize the related elements by type
                    foreach ($relatedElements as $relatedElement) {
                        foreach ($relatedTypes as $type => $class) {
                            if ($relatedElement instanceof $class) {
                                try {
                                    if ($relatedElement->getFieldLayout() !== null) {
                                        if (!isset($outgoingRelatedElements[$type])) {
                                            $outgoingRelatedElements[$type] = [];
                                        }

                                        // Check if element already exists to avoid duplicates
                                        $exists = false;
                                        foreach ($outgoingRelatedElements[$type] as $existingElement) {
                                            if ($existingElement->id === $relatedElement->id) {
                                                $exists = true;
                                                break;
                                            }
                                        }

                                        if (!$exists) {
                                            $outgoingRelatedElements[$type][] = $relatedElement;
                                            $hasResults = true;
                                        }
                                    }
                                } catch (\Throwable $e) {
                                    Craft::error("Error checking field layout for outgoing element {$relatedElement->id}: " . $e->getMessage(), __METHOD__);
                                }
                                break;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Craft::warning("Error processing field {$field->handle} for outgoing relationships: " . $e->getMessage(), __METHOD__);
                    continue;
                }
            }
        } catch (\Throwable $e) {
            Craft::error("Error finding outgoing relationships: " . $e->getMessage(), __METHOD__);
        }
    }

        private function findIncomingRelationships(Element $element, array $relatedTypes, array &$incomingRelatedElements, bool &$hasResults, string $currentSiteHandle): void
    {
        try {
            // Use Craft's relatedTo with targetElement to find elements that reference this element
            foreach ($relatedTypes as $type => $class) {
                $elements = $class::find()
                    ->relatedTo([
                        'targetElement' => $element,
                        'field' => null
                    ])
                    ->status(null)
                    ->site('*')
                    ->unique()
                    ->preferSites([$currentSiteHandle])
                    ->orderBy('title')
                    ->all();

                $filteredElements = array_filter($elements, function($el) use ($element) {
                    try {
                        // Don't include the element itself and ensure it has a field layout
                        return $el->id !== $element->id && $el->getFieldLayout() !== null;
                    } catch (\Throwable $e) {
                        Craft::error("Error checking field layout for incoming element {$el->id}: " . $e->getMessage(), __METHOD__);
                        return false;
                    }
                });

                // Verify these are actually incoming relationships by checking their field values
                foreach ($filteredElements as $candidateElement) {
                    try {
                        $fieldLayout = $candidateElement->getFieldLayout();
                        if (!$fieldLayout) {
                            continue;
                        }

                        $fields = $fieldLayout->getCustomFields();
                        $actuallyReferences = false;

                        foreach ($fields as $field) {
                            if (!$field || !$field->handle || !($field instanceof BaseRelationField)) {
                                continue;
                            }

                            try {
                                $fieldValue = $candidateElement->getFieldValue($field->handle);

                                if (!$fieldValue) {
                                    continue;
                                }

                                // Check if this field contains the current element
                                $relatedElements = [];

                                if (is_iterable($fieldValue)) {
                                    foreach ($fieldValue as $relatedElement) {
                                        if ($relatedElement instanceof Element) {
                                            $relatedElements[] = $relatedElement;
                                        }
                                    }
                                } elseif ($fieldValue instanceof Element) {
                                    $relatedElements[] = $fieldValue;
                                }

                                // Check if the current element is in this field's values
                                foreach ($relatedElements as $relatedElement) {
                                    if ($relatedElement->id === $element->id) {
                                        $actuallyReferences = true;
                                        break 2; // Break out of both loops
                                    }
                                }
                            } catch (\Throwable $e) {
                                Craft::warning("Error processing field {$field->handle} for incoming verification: " . $e->getMessage(), __METHOD__);
                                continue;
                            }
                        }

                        // Only add if it actually references the current element
                        if ($actuallyReferences) {
                            // Check if element already exists to avoid duplicates
                            $exists = false;
                            foreach ($incomingRelatedElements[$type] as $existingElement) {
                                if ($existingElement->id === $candidateElement->id) {
                                    $exists = true;
                                    break;
                                }
                            }

                            if (!$exists) {
                                $incomingRelatedElements[$type][] = $candidateElement;
                                $hasResults = true;
                            }
                        }
                    } catch (\Throwable $e) {
                        Craft::warning("Error verifying incoming relationship for element {$candidateElement->id}: " . $e->getMessage(), __METHOD__);
                        continue;
                    }
                }
            }
        } catch (\Throwable $e) {
            Craft::error("Error finding incoming relationships: " . $e->getMessage(), __METHOD__);
        }
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
