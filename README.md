# php-marc-generator

A PHP 7 command-line script that reads journal and article metadata from a YAML file and produces valid MARC 21 XML (MARCXML) conforming to the Library of Congress schema.

## Features

- Generates serial/journal records (bibliographic level `s`)
- Generates individual volume records (bibliographic level `b`) linked to their parent journal via field 773
- Generates individual article records (bibliographic level `a`) linked to their parent journal via field 773
- Batch processing of multiple records in a single run
- YAML-driven input — no PHP code changes needed to add records
- RDA-compliant cataloguing fields (336/337/338, 040 $e rda)

## Requirements

- PHP 7.x or 8.x
- Composer

## Installation

```bash
composer install
```

## Usage

```bash
# Default paths (records.yaml → output.xml)
php marc_generator.php

# Custom paths
php marc_generator.php /path/to/input.yaml /path/to/output.xml
```

## File Structure

```
marc_generator.php   # Main script
records.yaml         # Input data
output.xml           # Generated MARCXML (created on run)
vendor/              # Composer dependencies
```

## Input Format

The YAML input file supports a `journals` list, a `volumes` list, an `articles` list, or any combination. See `records.yaml` for a full example with all available fields.

Key volume fields include `title`, `volume`, `issue_range`, `year`, `date_range`, `editors`, and a `host_journal` block that links back to the parent serial via MARC 773. The `issn` field accepts either a plain string or a list of `{value, type}` objects to record both print and online ISSNs as separate 022 fields.

Key article fields include `title`, `author`, `doi`, `abstract`, `subjects`, and a `host_journal` block that populates the MARC 773 field to link the article back to its parent serial.

## Output

Valid MARCXML wrapped in a `<collection>` element, conforming to:
`http://www.loc.gov/standards/marcxml/schema/MARC21slim.xsd`