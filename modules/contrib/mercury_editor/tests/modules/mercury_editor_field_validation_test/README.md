# Mercury Editor Field Validation Test

## Overview

This test module is designed to test field validation functionality within the Mercury Editor interface. It provides validation testing capabilities for components and node forms when using Mercury Editor.

## Purpose

The module tests the following validation scenarios:

1. **Component Field Validation**: Tests that required field validation works properly when creating or editing components within Mercury Editor
2. **Node Form Validation**: Tests that validation errors are properly displayed on Mercury Editor node forms
3. **Error Message Display**: Validates that error messages are styled correctly with proper background colors and visibility

## Features

### Form Alterations

- Adds example messenger messages (status, warning, error) to the Mercury Editor node form
- Demonstrates different message types without AJAX interactions

### Validation Testing

- Tests required field validation for components
- Validates error message styling and display
- Ensures validation errors prevent form submission until resolved

## Usage

This module is intended for automated testing purposes only and should not be enabled on production sites.

## Test Scenarios

1. **Component Creation Without Required Fields**: Creates a 2-column section component without filling in required fields and attempts to save
2. **Validation Error Display**: Verifies that validation errors appear with proper styling
3. **Error Message Styling**: Validates error messages have the correct background color (`rgb(247, 217, 217)`)
4. **Field Requirement Enforcement**: Ensures required fields prevent form submission

## Installation

This module should only be enabled during testing:

```bash
drush en mercury_editor_field_validation_test
```

After testing is complete, disable the module:

```bash
drush pmu mercury_editor_field_validation_test
```
