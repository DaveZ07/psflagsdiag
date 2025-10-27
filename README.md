# PS Flags Diag - Product Flags Diagnostic Module for PrestaShop

## Overview

**PS Flags Diag** is a comprehensive diagnostic tool for PrestaShop 1.7.8+ that analyzes and reports on product flags throughout your store. It scans dynamic flags from the presenter layer (core + modules + hooks), identifies potential flag classes from theme/module CSS/TPL files, and provides detailed insights into how flags are implemented and used in your shop.

## Features

- **Dynamic Flag Analysis**: Scans actual products and collects flags returned by PrestaShop's ProductPresenter
- **Core Flag Diagnostics**: Analyzes logical flags (new, on_sale, online_only, pack, etc.) with product counts
- **Theme & Module Scanning**: Discovers flag-related CSS classes and their styling properties
- **Template Analysis**: Extracts flag keys and classes from Smarty templates
- **Multi-shop Support**: Scan specific shops in multistore environments
- **Availability Overview**: Quick summary of stock availability across products
- **Customizable Scanning**: Configurable directory paths, CSS patterns, and scan limits

## Requirements

- **PrestaShop**: 1.7.8.0 or higher
- **PHP**: 5.6 or higher (7.1+ recommended)
- **MySQL**: 5.6 or higher

## Installation

1. Download or clone this repository
2. Place the `psflagsdiag` folder in your PrestaShop `/modules/` directory
3. Log in to your PrestaShop back office
4. Navigate to **Modules** → **Module Manager**
5. Search for "Product Flags Diagnostic"
6. Click **Install**

The module will automatically create a tab under **Catalog** → **Flags Diagnostic** in your admin menu.

## Usage

### Accessing the Diagnostic Tool

After installation:
1. Go to **Catalog** → **Flags Diagnostic** in your admin panel
2. Configure scan parameters using the form
3. Click **Scan** to run the analysis

### Scan Configuration Options

| Option | Description | Default |
|--------|-------------|---------|
| **Scan directory** | Path to scan for CSS/TPL files | Current theme directory |
| **Include modules** | Include module directory in scan | Unchecked |
| **Only active** | Scan only active products | Unchecked |
| **Limit** | Maximum number of products to scan (0 = all) | 0 |
| **CSS pattern** | Regex pattern for flag class detection | `(?:\.product-flag(?:--[a-z0-9\-]+)?|\.(?:new|on-?sale|online-?only|pack))` |
| **Shop** | Select specific shop for multistore | Current context |

### Understanding the Results

The diagnostic tool provides six main sections:

#### 1. Dynamic Flags from Presenter
Shows flags actually returned by PrestaShop's ProductPresenter for real products in your database.

- **Key**: The flag key as it appears in `$product.flags.{key}`
- **Count**: Number of products with this flag
- **Sample product IDs**: Example products displaying this flag

#### 2. Core Logical Flags
Diagnostic overview of PrestaShop's standard flag logic with product counts.

| Flag | Logic |
|------|-------|
| `new` | Products added within `PS_NB_DAYS_NEW` days |
| `on_sale` | Products with `on_sale = 1` field |
| `on_sale_specific_price` | Products with active specific price reductions |
| `online_only` | Products with `online_only = 1` field |
| `pack` | Products configured as packs |
| `out_of_stock` | Products with `quantity <= 0` |
| `in_stock` | Products with `quantity > 0` |
| `oos_backorder_enabled` | Out-of-stock products allowing backorders |

#### 3. Theme Badge Classes (CSS)
CSS classes discovered in your theme matching the flag pattern.

- **Class**: The CSS class name
- **Color**: Text color property
- **Background**: Background color property
- **Border**: Border color property
- **Files**: Files where this class is defined

#### 4. Template Scan (TPL)
Keys and classes extracted from Smarty templates in the scanned directory.

- **Key (from tpl)**: Flag keys referenced as `$product.flags.{key}`
- **Class (from tpl)**: CSS classes used in templates matching the pattern

#### 5. Modules Template Scan
Same as above but for modules (when "Include modules" is enabled).

## Module Logic & Architecture

### Core Components

#### 1. Main Module Class (`psflagsdiag.php`)

**Purpose**: Bootstrap the module and manage installation/uninstallation.

**Key Methods**:
- `__construct()`: Initializes module metadata and compatibility settings
- `install()`: Registers the module and creates admin tab
- `uninstall()`: Removes admin tab and unregisters module
- `installTab()`: Creates "Flags Diagnostic" menu item under Catalog
- `uninstallTab()`: Removes the admin tab

**Logic Flow**:
```
Module Installation
    ↓
Create Tab (AdminPsFlagsDiag)
    ↓
Add to Catalog Menu
    ↓
Set Active & Link to Module
```

#### 2. Admin Controller (`AdminPsFlagsDiagController.php`)

**Purpose**: Handle all diagnostic scanning, analysis, and reporting.

**Architecture**:

```
initContent()
    ↓
    ├─→ Validate & sanitize scan directory
    ├─→ Parse form parameters
    ├─→ Set shop context (multistore)
    ├─→ Collect data from multiple sources:
    │       ├─→ getCoreFlagsSummary()
    │       ├─→ getAvailabilitySummary()
    │       ├─→ collectFlagsFromPresenter()
    │       ├─→ scanForFlagClasses()
    │       └─→ scanTplForFlagsAndClasses()
    └─→ Render HTML report
```

### Key Methods Explained

#### `hasValidShopContext()`
**Purpose**: Checks if a valid shop context exists (required for multistore).

**Logic**:
```php
Returns true if: $this->context->shop exists AND shop ID > 0
Otherwise: false
```

#### `ensureFrontLikeContext()`
**Purpose**: Ensures all required context objects (shop, language, currency, link) are available for ProductPresenter.

**Logic**:
1. Check for valid shop context
2. If missing, attempt to determine shop from:
   - Current shop context
   - Default shop configuration
   - First available shop
3. Initialize Link object if missing
4. Set default language if missing
5. Set default currency if missing
6. Return prepared context or null

#### `collectFlagsFromPresenter($onlyActive, $batchSize, $maxProducts)`
**Purpose**: Scans actual products and collects flags returned by PrestaShop's ProductPresenter.

**Logic**:
```
1. Ensure valid front-like context
2. Initialize ProductPresenter with required dependencies:
   - ImageRetriever
   - Link
   - PriceFormatter
   - ProductColorsRetriever
   - Translator
3. Load products in batches (configurable size)
4. For each product:
   - Call presenter->present()
   - Extract flags from presented data
   - Track flag occurrence count
   - Store sample product IDs (max 10 per flag)
5. Continue until:
   - No more products
   - Max limit reached
6. Return statistics array
```

**Data Structure**:
```php
[
    'stats' => [
        'flag_key' => [
            'count' => 42,
            'sample_ids' => [123, 456, 789, ...]
        ],
        ...
    ],
    'total_scanned' => 1500
]
```

#### `getCoreFlagsSummary()`
**Purpose**: Executes SQL queries to count products matching core flag logic.

**Logic**:
- **new**: `DATEDIFF(NOW(), date_add) <= PS_NB_DAYS_NEW`
- **on_sale**: `on_sale = 1`
- **on_sale_specific_price**: Active specific_price with reduction > 0
- **online_only**: `online_only = 1`
- **pack**: Products in ps_pack table
- **out_of_stock**: `stock_available.quantity <= 0`
- **in_stock**: `stock_available.quantity > 0`
- **oos_backorder_enabled**: Complex logic checking:
  - `quantity <= 0`
  - AND (`out_of_stock IN (1,2)` OR (`out_of_stock = 0` AND `PS_ORDER_OUT_OF_STOCK = 1`))

#### `scanForFlagClasses($dir, $pattern)`
**Purpose**: Scans CSS/SCSS files for flag-related classes and extracts color properties.

**Logic**:
```
1. Gather all .css, .scss, .sass files from directory (excluding node_modules)
2. For each file:
   - Search for classes matching regex pattern
   - Extract CSS block for matched class
   - Parse color, background, border properties
   - Track which files contain each class
3. Return aggregated class data with styling info
```

#### `scanTplForFlagsAndClasses($dir, $pattern)`
**Purpose**: Scans Smarty templates for flag references and CSS classes.

**Logic**:
```
1. Gather all .tpl, .html, .smarty files
2. For each file:
   - Search for: $product.flags.{key}
   - Search for: {foreach} loops over $product.flags
   - Search for: class="" attributes containing flag patterns
3. Extract unique:
   - Flag keys (e.g., "new", "on_sale")
   - CSS classes (e.g., "product-flag--new")
4. Return sorted arrays of keys, classes, and file paths
```

#### `gatherFiles($root, $exts)`
**Purpose**: Recursively collects files with specific extensions from a directory tree.

**Logic**:
```
1. Use RecursiveDirectoryIterator
2. Skip dot files and node_modules directories
3. Filter by file extension (case-insensitive)
4. Return array of full file paths
```

#### `extractFlagClassesAndColors($files, $baseDir, $pattern)`
**Purpose**: Parses CSS files to extract class definitions and color properties.

**Logic**:
```
1. For each CSS file:
   - Match classes using regex pattern
   - For each matched class:
     - Find CSS block (up to 600 chars)
     - Extract 'color:', 'background:', 'border:' properties
     - Store relative file path
2. Aggregate data per class:
   - class name
   - files containing definition
   - color values (text, background, border)
3. Return sorted array of class objects
```

### Data Flow Diagram

```
User Request
    ↓
Form Submission (scan parameters)
    ↓
AdminPsFlagsDiagController::initContent()
    ↓
    ├─→ [Database] → getCoreFlagsSummary()
    │                 → SQL queries on product, specific_price, stock_available
    │                 → Returns flag counts
    │
    ├─→ [Database] → getAvailabilitySummary()
    │                 → SQL queries on stock_available
    │                 → Returns in/out of stock counts
    │
    ├─→ [Database + Presenter] → collectFlagsFromPresenter()
    │                              ↓
    │                         Load Products in batches
    │                              ↓
    │                         ProductPresenter::present()
    │                              ↓
    │                         Extract $product['flags']
    │                              ↓
    │                         Aggregate statistics
    │
    ├─→ [Filesystem] → scanForFlagClasses()
    │                   ↓
    │              Gather CSS files
    │                   ↓
    │              extractFlagClassesAndColors()
    │                   ↓
    │              Parse CSS, extract colors
    │
    └─→ [Filesystem] → scanTplForFlagsAndClasses()
                        ↓
                   Gather TPL files
                        ↓
                   Parse templates
                        ↓
                   Extract flag keys & classes
    ↓
Generate HTML Report
    ↓
Display in Back Office
```

### Security Considerations

1. **Directory Validation**: Scan directory must be within shop root and actually exist
2. **SQL Injection Protection**: Uses Db::getValue() and escapes values
3. **XSS Prevention**: All output is sanitized with htmlspecialchars() or Tools::safeOutput()
4. **File Access**: Only reads files, never writes or executes
5. **Context Validation**: Ensures valid shop context before database operations

### Performance Optimization

1. **Batch Processing**: Products loaded in configurable batches (default 200)
2. **Scan Limits**: Optional maximum product limit to prevent timeouts
3. **Selective Scanning**: Options to exclude modules or inactive products
4. **Efficient Queries**: Direct SQL counts instead of loading full objects
5. **Caching Prevention**: Presenter throws handled gracefully to skip problematic products

## Troubleshooting

### No Presenter Flags Shown

**Cause**: Invalid or missing shop context in multistore setup.

**Solution**: Use the "Shop" dropdown to select a specific shop, or switch to single-shop context in PrestaShop preferences.

### Scan Times Out

**Cause**: Too many products or files to scan.

**Solutions**:
- Set a scan limit (e.g., 500 products)
- Uncheck "Include modules" for faster scans
- Scan only active products

### No CSS Classes Found

**Cause**: Pattern doesn't match your theme's class naming convention.

**Solution**: Adjust the "CSS pattern" field to match your theme's classes. Examples:
- Default: `(?:\.product-flag(?:--[a-z0-9\-]+)?|\.(?:new|on-?sale|online-?only|pack))`
- Custom: `\.badge-[a-z]+` for Bootstrap-based themes

### Directory Not Scanned

**Cause**: Directory path is outside shop root or doesn't exist.

**Solution**: Ensure the path is absolute and within your PrestaShop installation root.

## Use Cases

### Theme Development
Identify all flag classes used in templates and ensure corresponding CSS exists.

### Module Compatibility
Check if third-party modules add custom flags to products.

### Performance Auditing
Determine how many products have specific flags before implementing custom logic.

### Migration Planning
Document current flag implementation before upgrading PrestaShop or changing themes.

### Debugging
Verify which flags are actually returned by the presenter vs. expected flags.

## Technical Details

### Database Tables Used
- `ps_product` - Product data (on_sale, online_only, date_add)
- `ps_specific_price` - Price rules and reductions
- `ps_stock_available` - Stock quantities
- `ps_pack` - Pack product relationships
- `ps_configuration` - System settings (PS_NB_DAYS_NEW, PS_ORDER_OUT_OF_STOCK, etc.)

### PrestaShop Classes Used
- `ModuleAdminController` - Base admin controller
- `ProductPresenter` - Core presenter for product data
- `ImageRetriever` - Product image handling
- `PriceFormatter` - Price formatting
- `ProductColorsRetriever` - Color variations
- `Shop` - Multistore management
- `Language` - Internationalization
- `Currency` - Price conversion
- `Db` - Database access

## Customization

### Adding Custom Flag Detection

Edit `getCoreFlagsSummary()` in `AdminPsFlagsDiagController.php`:

```php
$customCnt = (int)$db->getValue("
    SELECT COUNT(*) FROM {$prefix}product p 
    WHERE your_custom_condition
");

$flags[] = [
    'key' => 'custom_flag',
    'label' => 'My Custom Flag',
    'hint' => 'Description of logic',
    'count' => $customCnt,
    'source' => 'custom_field or calculation'
];
```

### Modifying CSS Pattern

The default pattern matches:
- `.product-flag` (and modifiers like `.product-flag--new`)
- `.new`, `.on-sale`, `.online-only`, `.pack`

Customize via the form field or modify the default in the controller.

## License

This module is provided "as-is" without warranty. Use at your own risk.

## Author

PS Flags Diag Module
- **Author**: davez.ovh
- **Website**: [https://davez.ovh](https://davez.ovh


## Version History

### 1.3.9 (Current)
- Multi-shop support with shop selector
- Module template scanning
- Enhanced CSS pattern matching
- Improved context handling
- Batch processing optimization

## Support

For issues or questions:
1. Check PrestaShop logs in `/var/logs/`
2. Verify PHP and PrestaShop version compatibility
3. Ensure file permissions are correct (644 for files, 755 for directories)
4. Review debug snapshot in the diagnostic output

## Contributing

Contributions are welcome! Please ensure:
- Code follows PrestaShop coding standards
- Security best practices are maintained
- Changes are tested on PrestaShop 1.7.8+

---

**Note**: This is a diagnostic tool intended for developers and advanced administrators. Use in production environments with appropriate caution.
