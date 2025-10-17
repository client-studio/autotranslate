# ACF + Classic Editor Translator (OpenAI)

A WordPress plugin that translates post content and ACF fields using OpenAI's GPT models. Perfect for migrating and translating entire websites from one language to another. Could be easily modified to also bulk review all content per post type. 

## Overview

This plugin provides a powerful admin interface to translate all content on your WordPress site - including post titles, content, ACF fields, and SEO metadata - with a single click. It's designed for bulk translation of existing sites, especially when setting up new multilingual sites based on existing content.

## Use Case

Sometimes for new sites, we just need to use some "force" to get the translations done quickly and efficiently. This plugin can be used to **review or translate all content on a new site from existing content**, making it ideal for:

- Creating a new language version of an existing site
- Bulk translating legacy content
- Migrating sites to different language markets
- Quick translation reviews and content audits

## Features

- ✅ **Batch Processing**: Translate hundreds of posts with automatic batching
- 🎯 **Smart Translation**: Preserves HTML, shortcodes, and formatting
- 🔧 **ACF Support**: Deep translation of ACF fields including repeaters, flexible content, and groups
- 🔍 **SEO Integration**: Translates meta titles and descriptions from Yoast SEO, Rank Math, and All in One SEO
- 🎨 **Regex Filtering**: Target specific ACF fields using regex patterns
- 🔒 **Safe Testing**: Dry-run mode to preview changes before committing
- ⚡ **No Timeout Issues**: Chunked batch processing prevents timeouts
- 📝 **Real-time Logging**: Watch the translation progress in real-time

## Supported Content

- Post titles and slugs
- Post content (Classic Editor)
- ACF text fields
- ACF textarea fields
- ACF WYSIWYG fields
- ACF repeater fields (nested)
- ACF flexible content (nested)
- ACF group fields (nested)
- SEO meta titles (Yoast, Rank Math, All in One SEO)
- SEO meta descriptions (Yoast, Rank Math, All in One SEO)

## Requirements

- WordPress 5.0+
- PHP 7.4+
- OpenAI API key (GPT-4 or GPT-4o-mini recommended)
- Advanced Custom Fields (for ACF translation)

## Installation

1. Clone or download this repository
2. Upload to your WordPress `wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Navigate to **AI Translator** in the WordPress admin menu

## Configuration

1. **OpenAI API Key**: Enter your OpenAI API key in the settings
2. **Model**: The plugin uses `gpt-4o-mini` by default (cost-effective and fast)
3. **Source Language**: Select the current language of your site content
4. **Target Language**: Select the language you want to translate to

### Supported Languages

- Finnish (Suomi)
- Swedish (Svenska)
- English (US)
- English (UK)
- German (Deutsch)
- French (Français)
- Spanish (Español)
- Italian (Italiano)
- Norwegian (Norsk)
- Danish (Dansk)

## Usage

### Basic Translation

1. Go to **AI Translator** in the WordPress admin
2. Click **Test API Connection** to verify your API key
3. Select the post types you want to translate
4. Enable **Dry run** for the first test
5. Click **Run** and watch the live log
6. Once satisfied, disable dry run and run for real

### Advanced Options

**ACF Fields Filter**  
Use regex patterns to target specific ACF fields:
- `/.*/` - Translate all fields (default)
- `/(title|description)/i` - Only translate fields with "title" or "description" in the name
- `/^content_/` - Only fields starting with "content_"

**Batching**  
- **Posts per batch**: Number of posts to process at once (default: 25)
- **Offset**: Start from a specific post number (useful for resuming)

### Translation Modes

**Overwrite Mode (Default)**  
- Replaces original content with translations
- Updates post titles, slugs, and content directly
- ⚠️ **Make a backup first!**

**Dry Run Mode**  
- Previews what would be translated
- No changes are written to the database
- Perfect for testing

## How It Works

1. The plugin queries posts based on your selected post types
2. For each post, it translates:
   - Post title → generates new slug automatically
   - Post content (preserving HTML/shortcodes)
   - All ACF fields matching your regex filter
   - SEO meta fields (if SEO plugin detected)
3. Translations are sent to OpenAI with context-aware prompts
4. HTML structure and formatting are preserved
5. Results are logged in real-time
6. Progress is batched to avoid timeouts

## API Costs

Using `gpt-4o-mini` (default):
- Input: ~$0.15 per 1M tokens
- Output: ~$0.60 per 1M tokens

Example: Translating 100 posts with ~500 words each typically costs under $2.

## Troubleshooting

**API Connection Failed**  
- Verify your OpenAI API key is correct
- Check your API key has sufficient credits
- Ensure your server can reach api.openai.com

**Translations Look Wrong**  
- Try using `gpt-4` instead of `gpt-4o-mini` (better quality, higher cost)
- Check your source/target language settings
- Review the ACF field filter regex

**Timeouts**  
- Reduce the batch size (try 10-15 posts)
- Increase your PHP `max_execution_time`
- Process in smaller chunks using the offset parameter

**Empty Translations**  
- Check the live log for API errors
- Verify the original content isn't empty
- Test with dry run first

## Security

- API keys are stored securely in the WordPress database
- Only administrators can access the plugin
- All AJAX requests are nonce-protected
- Input is sanitized and validated

## Development

Built with ❤️ by [client.studio](https://client.studio)

### Contributing

Pull requests are welcome! For major changes, please open an issue first.

## License

This plugin is provided as-is without warranty. Use at your own risk. Always backup your database before running bulk translations.

## Changelog

### 0.1.0
- Initial release
- Batch translation support
- ACF nested field support
- SEO plugin integration
- Real-time logging
- Dry run mode

---

**⚠️ Important**: This plugin **replaces** original content with translations. Always make a complete backup of your database before running translations!

