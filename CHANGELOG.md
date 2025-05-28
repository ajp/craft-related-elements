# Release Notes for Related Elements

## Unreleased

### Added

- "Show More" functionality to limit visible related elements with configurable initial limit.
- New `initialLimit` setting to control how many elements are shown initially (default: 10).
- New `showElementTypeLabel` setting to control whether element type labels (Entry, Category, Asset, Tag) are displayed next to each related element (default: true).
- Support for CKEditor field related entries.
- Custom icon and color support for entry types.

### Changed

- Related elements are now organized based on relationship direction.

## 1.1.4 - 2025-04-28

### Fixed

- Error handling for related elements without fieldLayout.

## 1.1.3 - 2025-04-09

### Added

- Improve error handling.

### Fixed

- Adjust element sidebar padding.
- Typo in settings instructions.

## 1.1.2 - 2025-01-13

### Fixed

- Prefer related elements from the current site.

## 1.1.1 - 2025-01-05

### Fixed

- Merge nested Neo & Matrix elements if multiple blocks are present.
- Recursively relate nested fields.
- Show related entries that don't exist in the primary site.

## 1.1.0 - 2024-10-06

### Added

- Support for nested elements in Matrix and Neo fields.

## 1.0.0 - 2024-09-17

### Added

- Initial release.
