# Style Options

The Style Options module provides configurable styles management for attaching
various style plugins to layouts, paragraphs, and (eventually) other Drupal
plugins.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/style_options).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/style_options).


## Table of contents

- Features
- Requirements
- Installation
- Configuration
- Maintainers


## Features

The module includes three style plugins:

- **CSS Class plugin:** Attach custom CSS classes to components
- **Background Color plugin:** Attach background colors to components
- **Background Image plugin:** Attach background images to components


## Requirements

This module requires no modules outside of Drupal core.


## Installation

Install as you would normally install a contributed Drupal module. For further
information, see
[Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).


## Configuration

### Migration from the Option Plugin module

If you're migrating from the
[Option Plugin](https://drupal.org/project/option_plugin) module, follow these steps:

1.  Backup your site
1.  Install this module
1.  Rename your YAML configuration files to `[module].style_options.yml`
    or `[theme].style_options.yml`
1.  Visit admin/config/style-options/migrate and press the button

### Customizing Style Options

To configure Style Options for your project, follow these steps:

1. Create configuration files in your module or theme directory with the
   naming convention `[module name].style_options.yml` or
   `[theme name].style_options.yml`.
1. Define the desired style options within these configuration files. Refer to
   `example.style_options.yml` for guidance on available configuration options
    and syntax.
1. Save the configuration files, and the defined styles will be applied to the
   corresponding components in your Drupal site.

### Using Style Options with Layouts

Ensure that your layouts reference the provided layout plugin or a custom class
that inherits from it. This allows you to apply the configured style options to
your layout components seamlessly.

### Using Style Options with Paragraph Types

Enable the "Style Options" paragraph behavior for each paragraph type where you
want the provided style options to appear. This allows you to apply customized
styles to individual paragraph components within your content.


## Maintainers

- Justin Toupin  - [justin2pin](https://www.drupal.org/u/justin2pin)
- John Ferris - [pixelwhip](https://www.drupal.org/u/pixelwhip)
