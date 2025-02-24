# Content Review Plugin for Craft CMS

This plugin helps you manage content review cycles in Craft CMS. It allows you to set review periods for different sections and sends notifications when content needs to be reviewed.

## Features

- Set review periods for different content sections
- Automatic notifications when content needs review
- Email notifications to content owners and stakeholders
- Slack notifications for team collaboration
- Dashboard widget showing content requiring review
- Control panel section for managing review cycles

## Requirements

- Craft CMS 4.0.0 or later
- PHP 8.0.2 or later

## Installation

You can install this plugin from the Plugin Store or with Composer.

### From the Plugin Store

Go to the Plugin Store in your project's Control Panel and search for "Content Review". Then click "Install".

### With Composer

Open your terminal and run the following commands:

```bash
# Go to the project directory
cd /path/to/your-project

# Tell Composer to load the plugin
composer require honchoagency/content-reminder

# Tell Craft to install the plugin
./craft plugin/install content-reminder
```

## Configuration

1. Go to Settings → Content Review in the Control Panel
2. Configure the default review period
3. Set up email and Slack notifications
4. Configure section-specific review periods if needed

## Usage

1. Visit the Content Review dashboard in the Control Panel
2. View sections that need review
3. Mark sections as reviewed when content has been checked
4. Monitor notifications for upcoming and overdue reviews

## Support

If you have any issues or feature requests, please create an issue in the [GitHub repository](https://github.com/honchoagency/content-reminder/issues).

## License

This plugin is licensed under the MIT License. See [LICENSE.md](LICENSE.md) for details.
