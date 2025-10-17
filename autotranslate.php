<?php
/**
 * Plugin Name: ACF + Classic Editor Translator (OpenAI)
 * Description: Translate post content and ACF fields from the WP Admin with one click. Preserves HTML/shortcodes. Supports batch processing.
 * Version: 0.1.0
 * Author: client.studio
 */

if (!defined('ABSPATH')) exit;

class ACF_Classic_AI_Translator {
	const OPT_KEY = 'acai_translator_options';
	const PAGE_SLUG = 'acai-translator';

	public function __construct() {
		add_action('admin_menu', [$this, 'add_menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue']);
		add_action('wp_ajax_acai_translate_batch', [$this, 'ajax_translate_batch']);
		add_action('wp_ajax_acai_test_api', [$this, 'ajax_test_api']);
	}

	public function add_menu() {
		add_menu_page(
			'AI Translator', 'AI Translator', 'manage_options', self::PAGE_SLUG,
			[$this, 'render_page'], 'dashicons-translation', 81
		);
	}

	public function register_settings() {
		register_setting(self::OPT_KEY, self::OPT_KEY, [$this, 'sanitize_options']);
		add_settings_section('acai_main', 'OpenAI Settings', '__return_false', self::PAGE_SLUG);
		add_settings_field('openai_key', 'OpenAI API Key', [$this, 'field_openai_key'], self::PAGE_SLUG, 'acai_main');
		add_settings_field('model', 'Model', [$this, 'field_model'], self::PAGE_SLUG, 'acai_main');
		add_settings_field('source_lang', 'Current Site Language', [$this, 'field_source_lang'], self::PAGE_SLUG, 'acai_main');
		add_settings_field('target_lang', 'Target Language (Replace With)', [$this, 'field_target_lang'], self::PAGE_SLUG, 'acai_main');
	}

	public function sanitize_options($opts) {
		$opts = is_array($opts) ? $opts : [];
		$opts['openai_key'] = isset($opts['openai_key']) ? trim(sanitize_text_field($opts['openai_key'])) : '';
		$opts['model'] = isset($opts['model']) ? sanitize_text_field($opts['model']) : 'gpt-4o-mini';
		$opts['source_lang'] = isset($opts['source_lang']) ? sanitize_text_field($opts['source_lang']) : 'fi';
		$opts['target_lang'] = isset($opts['target_lang']) ? sanitize_text_field($opts['target_lang']) : 'en-US';
		return $opts;
	}

	private function get_options() {
		$defaults = ['openai_key' => '', 'model' => 'gpt-4o-mini', 'source_lang' => 'fi', 'target_lang' => 'en-US'];
		return wp_parse_args(get_option(self::OPT_KEY, []), $defaults);
	}

	public function field_openai_key() {
		$opts = $this->get_options();
		echo '<input type="password" name="' . esc_attr(self::OPT_KEY) . '[openai_key]" value="' . esc_attr($opts['openai_key']) . '" size="60" autocomplete="off" />';
		if (!$opts['openai_key']) echo '<p class="description">Store your key securely in the database (only admins can view/change).</p>';
	}

	public function field_model() {
		$opts = $this->get_options();
		echo '<input type="text" name="' . esc_attr(self::OPT_KEY) . '[model]" value="gpt-5-mini" readonly style="width:200px;" />';
		echo '<p class="description">Using gpt-5-mini .</p>';
	}

	public function field_source_lang() {
		$opts = $this->get_options();
		$langs = [
			'fi' => 'Finnish (Suomi)',
			'sv' => 'Swedish (Svenska)', 
			'en-US' => 'English (US)',
			'en-GB' => 'English (UK)',
			'de' => 'German (Deutsch)',
			'fr' => 'French (Français)',
			'es' => 'Spanish (Español)',
			'it' => 'Italian (Italiano)',
			'no' => 'Norwegian (Norsk)',
			'da' => 'Danish (Dansk)',
		];
		echo '<select name="' . esc_attr(self::OPT_KEY) . '[source_lang]">';
		foreach ($langs as $code => $label) {
			printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr($code), selected($opts['source_lang'], $code, false), esc_html($label));
		}
		echo '</select>';
		echo '<p class="description">The current language of your site content.</p>';
	}

	public function field_target_lang() {
		$opts = $this->get_options();
		$langs = [
			'en-US' => 'English (US)',
			'en-GB' => 'English (UK)',
			'fi' => 'Finnish (Suomi)',
			'sv' => 'Swedish (Svenska)',
			'de' => 'German (Deutsch)',
			'fr' => 'French (Français)',
			'es' => 'Spanish (Español)',
			'it' => 'Italian (Italiano)',
			'no' => 'Norwegian (Norsk)',
			'da' => 'Danish (Dansk)',
		];
		echo '<select name="' . esc_attr(self::OPT_KEY) . '[target_lang]">';
		foreach ($langs as $code => $label) {
			printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr($code), selected($opts['target_lang'], $code, false), esc_html($label));
		}
		echo '</select>';
		echo '<p class="description">The language to translate all content to (will replace original).</p>';
	}

	public function enqueue($hook) {
		if ($hook !== 'toplevel_page_' . self::PAGE_SLUG) return;
		wp_enqueue_style('acai-admin', plugin_dir_url(__FILE__) . 'data:text/css,', [], null); // no file; keep deps minimal
		wp_enqueue_script('acai-admin', plugin_dir_url(__FILE__) . 'data:text/js,', ['jquery'], null, true);
		$opts = $this->get_options();
		wp_add_inline_script('acai-admin', $this->inline_js());
		wp_localize_script('acai-admin', 'ACAI', [
			'ajax' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('acai_translate'),
			'postTypes' => $this->get_public_post_types(),
			'hasKey' => !empty($opts['openai_key']),
			'sourceLang' => $opts['source_lang'] ?? '',
			'targetLang' => $opts['target_lang'] ?? ''
		]);
	}

	private function inline_js() {
		return <<<JS
(function($){
	var statusDiv, spinner;
	function log(msg){
		$('#acai-log').append($('<div/>').text(msg));
		var pane = $('#acai-log-wrap'); pane.scrollTop(pane[0].scrollHeight);
	}
	$(document).on('click','#acai-test',function(e){
		e.preventDefault();
		var btn = $(this);
		var result = $('#acai-test-result');
		btn.prop('disabled', true).text('Testing...');
		result.html('<span class="spinner is-active" style="float:none;margin:0;"></span>');
		$.post(ACAI.ajax, {action:'acai_test_api', _ajax_nonce: ACAI.nonce}, function(resp){
			btn.prop('disabled', false).text('Test API Connection');
			if(resp && resp.success){
				result.html('<span style="color:#46b450;">✓ ' + resp.data + '</span>');
			} else {
				result.html('<span style="color:#dc3232;">✗ ' + (resp && resp.data ? resp.data : 'Unknown error') + '</span>');
			}
		}).fail(function(){ 
			btn.prop('disabled', false).text('Test API Connection');
			result.html('<span style="color:#dc3232;">✗ Network error</span>'); 
		});
	});
	function setStatus(msg, isRunning){
		if(!statusDiv){
			statusDiv = $('<div id="acai-status" style="padding:12px; margin-bottom:12px; border-radius:4px; font-weight:600;"></div>');
			$('#acai-log-wrap').before(statusDiv);
		}
		if(isRunning){
			statusDiv.html('<span class="spinner is-active" style="float:left; margin:0 8px 0 0;"></span>' + msg)
				.css({background:'#e8f4f8', border:'1px solid #00a0d2', color:'#00546a', display:'block'});
			$('#acai-run').prop('disabled', true).text('Running...');
		} else {
			statusDiv.html(msg).css({background:'#ecf7ed', border:'1px solid #46b450', color:'#0f5f0f', display:'block'});
			$('#acai-run').prop('disabled', false).text('Run');
			setTimeout(function(){ statusDiv.fadeOut(); }, 3000);
		}
	}
	function gatherForm(){
		return {
			post_types: $('#acai-post-types').val() || [],
			fields_regex: $('#acai-fields').val().trim(),
			overwrite: 1,
			simulate: $('#acai-simulate').is(':checked') ? 1 : 0,
			batch: parseInt($('#acai-batch').val(),10)||25,
			offset: parseInt($('#acai-offset').val(),10)||0
		};
	}
	$(document).on('click','#acai-run',function(e){
		e.preventDefault();
		if(!ACAI.hasKey){ alert('Please save your OpenAI key first.'); return; }
		if(!ACAI.sourceLang || !ACAI.targetLang){ alert('Please configure source and target languages in settings.'); return; }
		$('#acai-log').empty();
		var params = gatherForm();
		var running = true;
		var totalProcessed = 0;
		setStatus('Starting translation...', true);
		log('Starting batch translation...');
		log('');
		function runOnce(){
			if(!running) return;
			var batchNum = Math.floor(params.offset / params.batch) + 1;
			setStatus('Processing batch ' + batchNum + ' (offset: ' + params.offset + ')...', true);
			$.post(ACAI.ajax, Object.assign({action:'acai_translate_batch', _ajax_nonce: ACAI.nonce}, params), function(resp){
				if(!resp || !resp.success){ 
					log('❌ Error: ' + (resp && resp.data ? resp.data : 'Unknown error')); 
					setStatus('Error occurred. Check log.', false);
					running=false; 
					return; 
				}
				var d = resp.data; 
				if(d.logs){ 
					d.logs.forEach(function(l){ log(l); }); 
				}
				if(d.count !== undefined){
					totalProcessed += d.count;
				}
				if(d.more){ 
					params.offset += params.batch; 
					log('');
					runOnce(); 
				} else { 
					log('');
					if(totalProcessed > 0){
						log('✅ All done! Processed ' + totalProcessed + ' post' + (totalProcessed === 1 ? '' : 's') + '.');
					} else {
						log('✅ All done! No posts found.');
					}
					setStatus('✅ Translation complete!', false);
					running=false; 
				}
			}).fail(function(xhr, status, error){ 
				log('❌ Network error: ' + error); 
				setStatus('Network error occurred.', false);
				running=false; 
			});
		}
		runOnce();
	});
})(jQuery);
JS;
	}

	private function get_public_post_types() {
		$pts = get_post_types(['public' => true], 'objects');
		$out = [];
		foreach ($pts as $t) { $out[] = ['name' => $t->name, 'label' => $t->labels->singular_name ?: $t->label]; }
		return $out;
	}

	public function render_page() {
		if (!current_user_can('manage_options')) return;
		$opts = $this->get_options();
		?>
		<div class="wrap">
			<h1>ACF + Classic Editor Translator</h1>
			<form method="post" action="options.php" style="margin-bottom:24px;">
				<?php settings_fields(self::OPT_KEY); do_settings_sections(self::PAGE_SLUG); submit_button('Save Settings'); ?>
			</form>

			<h2>Test Connection</h2>
			<p>Test your API key and model before running translations:</p>
			<p>
				<button id="acai-test" class="button" <?php disabled(empty($opts['openai_key'])); ?>>Test API Connection</button>
				<span id="acai-test-result" style="margin-left:12px;"></span>
			</p>
			
			<h2>Run Translation</h2>
			<?php if ($opts['source_lang'] && $opts['target_lang']): ?>
				<p style="background:#fff3cd; padding:12px; border-left:4px solid #ffc107; margin-bottom:16px;">
					<strong>⚠️ Warning:</strong> This will translate from <strong><?php echo esc_html(strtoupper($opts['source_lang'])); ?></strong> to <strong><?php echo esc_html(strtoupper($opts['target_lang'])); ?></strong> and <strong>replace all original content</strong>. Make a backup first!
				</p>
			<?php endif; ?>
			<p>Select your content types and press Run. The tool will translate post content and ACF fields, preserving HTML/shortcodes.</p>
			<table class="form-table"><tbody>
				<tr>
					<th scope="row">Post Types</th>
					<td>
						<select id="acai-post-types" multiple size="6" style="min-width:260px;">
						<?php foreach ($this->get_public_post_types() as $pt): ?>
							<option value="<?php echo esc_attr($pt['name']); ?>"><?php echo esc_html($pt['label']); ?> (<?php echo esc_html($pt['name']); ?>)</option>
						<?php endforeach; ?>
						</select>
						<p class="description">Hold Ctrl/Cmd to select multiple.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">ACF Fields Filter</th>
					<td>
						<input id="acai-fields" type="text" placeholder="/.*/ (all fields)" style="width:360px;" />
						<p class="description">PHP regex to match field <em>names</em> (e.g. <code>/(title|lead|description)/i</code>). Leave blank to translate all text/WYSIWYG fields.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Options</th>
					<td>
						<label><input type="checkbox" id="acai-simulate" checked /> Dry run (no writes - test first!)</label>
					</td>
				</tr>
				<tr>
					<th scope="row">Batching</th>
					<td>
						<input id="acai-batch" type="number" value="25" min="1" max="200" style="width:100px;" /> posts per batch &nbsp;
						<input id="acai-offset" type="number" value="0" min="0" style="width:100px;" /> offset
						<p class="description">Process in batches to avoid timeouts. Start with offset 0.</p>
					</td>
				</tr>
			</tbody></table>
			<p>
				<button id="acai-run" class="button button-primary" <?php disabled(empty($opts['openai_key'])); ?>>Run</button>
			</p>
			<div id="acai-log-wrap" style="border:1px solid #ccd0d4; background:#fff; padding:12px; max-height:320px; overflow:auto;">
				<div id="acai-log" style="font-family:monospace; white-space:pre-wrap;"></div>
			</div>
		</div>
		<?php
	}

	public function ajax_test_api() {
		check_ajax_referer('acai_translate');
		if (!current_user_can('manage_options')) wp_send_json_error('Forbidden');
		
		$opts = $this->get_options();
		$key = $opts['openai_key'];
		$model = $opts['model'];
		
		if (!$key) {
			wp_send_json_error('No API key configured');
			return;
		}
		
		// Test with a simple translation
		$result = $this->ask_openai('Hello', 'en-US', 'en-US', false, $model, $key);
		
		if (!empty($result['error'])) {
			wp_send_json_error('API Error: ' . $result['error']);
		} elseif (!empty($result['content'])) {
			wp_send_json_success("API works! Model: {$model}");
		} else {
			wp_send_json_error('API returned empty response');
		}
	}

	public function ajax_translate_batch() {
		check_ajax_referer('acai_translate');
		if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

		$opts = $this->get_options();
		
		$params = [
			'post_types' => array_map('sanitize_text_field', (array)($_POST['post_types'] ?? [])),
			'from' => $opts['source_lang'] ?? 'fi',
			'to' => $opts['target_lang'] ?? 'en',
			'fields_regex' => sanitize_text_field($_POST['fields_regex'] ?? ''),
			'overwrite' => true, // Always overwrite
			'simulate' => !empty($_POST['simulate']),
			'batch' => max(1, min(200, intval($_POST['batch'] ?? 25))),
			'offset' => max(0, intval($_POST['offset'] ?? 0)),
		];

		list($logs, $more, $count) = $this->process_batch($params);
		wp_send_json_success(['logs' => $logs, 'more' => $more, 'count' => $count]);
	}

	private function process_batch($p) {
		$logs = [];
		$opts = $this->get_options();
		$key = $opts['openai_key'];
		$model = $opts['model'];
		if (!$key) return [["Missing OpenAI key."], false, 0];

		$post_types = $p['post_types'] ?: ['post'];
		$q = new WP_Query([
			'post_type' => $post_types,
			'posts_per_page' => $p['batch'],
			'offset' => $p['offset'],
			'post_status' => 'any',
			'fields' => 'ids',
		]);

		if (!$q->have_posts()) return [["No posts found for this page."], false, 0];

		$regex = $this->coerce_regex($p['fields_regex']);
		$count = 0;
		foreach ($q->posts as $post_id) {
			$post = get_post($post_id);
			$logs[] = "#{$post_id} — {$post->post_type} — {$post->post_title}";
			$count++;

			// 1) Post title
			$logs = array_merge($logs, $this->translate_post_title($post, $p['to'], $p['from'], $model, $key, $p['simulate'], $p['overwrite']));
			
			// 2) SEO meta fields
			$logs = array_merge($logs, $this->translate_seo_fields($post, $p['to'], $p['from'], $model, $key, $p['simulate'], $p['overwrite']));
			
			// 3) Classic Editor content
			$logs = array_merge($logs, $this->translate_post_content($post, $p['to'], $p['from'], $model, $key, $p['simulate'], $p['overwrite']));

			// 4) ACF fields
			if (function_exists('get_field_objects')) {
				$fields = get_field_objects($post_id) ?: [];
				$api_error = null;
				$this->translate_acf_tree($logs, $post_id, $fields, $p['to'], $p['from'], $model, $key, $regex, $p['simulate'], $p['overwrite'], [], '', $api_error);
				if ($api_error) {
					$logs[] = "  ❌ API Error: {$api_error}";
				}
			} else {
				$logs[] = 'ACF not active; skipping ACF fields.';
			}
		}

		$more = $q->found_posts > ($p['offset'] + $p['batch']);
		return [$logs, $more, $count];
	}

	private function translate_post_title($post, $to, $from, $model, $key, $simulate, $overwrite) {
		$logs = [];
		$title = $post->post_title;
		if (!is_string($title) || trim($title) === '') return $logs;
		
		if ($simulate) { 
			$logs[] = "  • Would translate post_title: \"{$title}\" → {$to}"; 
			$logs[] = "  • Would update slug from: \"{$post->post_name}\""; 
			return $logs; 
		}
		
		$result = $this->ask_openai($title, $to, $from, false, $model, $key);
		$translated = $result['content'] ?? '';
		$error = $result['error'] ?? null;
		
		// Check for API errors
		if ($error) {
			$logs[] = "  • ❌ post_title - API Error: {$error}";
			return $logs;
		}
		
		// Validate translation is not empty
		if (!is_string($translated) || trim($translated) === '') {
			$logs[] = "  • ⚠️ Skipped post_title - translation returned empty (original: {$title})";
			return $logs;
		}
		
		if ($overwrite) {
			// Generate new slug from translated title
			$new_slug = sanitize_title($translated);
			
			// Update both title and slug
			wp_update_post([
				'ID' => $post->ID,
				'post_title' => $translated,
				'post_name' => $new_slug
			]); 
			
			$logs[] = "  • Updated post_title: \"{$translated}\""; 
			$logs[] = "  • Updated slug: \"{$new_slug}\""; 
		}
		
		return $logs;
	}

	private function translate_seo_fields($post, $to, $from, $model, $key, $simulate, $overwrite) {
		$logs = [];
		$post_id = $post->ID;
		
		// Detect which SEO plugin is active
		$seo_fields = [];
		
		// Yoast SEO
		if (defined('WPSEO_VERSION')) {
			$yoast_title = get_post_meta($post_id, '_yoast_wpseo_title', true);
			$yoast_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
			
			if ($yoast_title) $seo_fields['_yoast_wpseo_title'] = $yoast_title;
			if ($yoast_desc) $seo_fields['_yoast_wpseo_metadesc'] = $yoast_desc;
		}
		
		// Rank Math
		if (defined('RANK_MATH_VERSION')) {
			$rm_title = get_post_meta($post_id, 'rank_math_title', true);
			$rm_desc = get_post_meta($post_id, 'rank_math_description', true);
			
			if ($rm_title) $seo_fields['rank_math_title'] = $rm_title;
			if ($rm_desc) $seo_fields['rank_math_description'] = $rm_desc;
		}
		
		// All in One SEO
		if (defined('AIOSEO_VERSION')) {
			$aioseo_title = get_post_meta($post_id, '_aioseo_title', true);
			$aioseo_desc = get_post_meta($post_id, '_aioseo_description', true);
			
			if ($aioseo_title) $seo_fields['_aioseo_title'] = $aioseo_title;
			if ($aioseo_desc) $seo_fields['_aioseo_description'] = $aioseo_desc;
		}
		
		if (empty($seo_fields)) {
			return $logs; // No SEO fields found
		}
		
		// Translate each SEO field
		foreach ($seo_fields as $meta_key => $value) {
			if (!is_string($value) || trim($value) === '') continue;
			
			$field_label = strpos($meta_key, 'title') !== false ? 'SEO Title' : 'SEO Description';
			
			if ($simulate) {
				$logs[] = "  • Would translate {$field_label}: \"{$value}\" → {$to}";
				continue;
			}
			
			$result = $this->ask_openai($value, $to, $from, false, $model, $key);
			$translated = $result['content'] ?? '';
			$error = $result['error'] ?? null;
			
			if ($error) {
				$logs[] = "  • ❌ {$field_label} - API Error: {$error}";
				continue;
			}
			
			if (!is_string($translated) || trim($translated) === '') {
				$logs[] = "  • ⚠️ Skipped {$field_label} - translation returned empty";
				continue;
			}
			
			if ($overwrite) {
				update_post_meta($post_id, $meta_key, $translated);
				$logs[] = "  • Updated {$field_label}: \"{$translated}\"";
			}
		}
		
		return $logs;
	}

	private function translate_post_content($post, $to, $from, $model, $key, $simulate, $overwrite) {
		$logs = [];
		$content = $post->post_content;
		if (!is_string($content) || trim($content) === '') return $logs;
		$meta_key = '_translated_content_' . $to;
		if (!$overwrite && get_post_meta($post->ID, $meta_key, true)) { $logs[] = "  • Content already translated ($meta_key)."; return $logs; }
		
		if ($simulate) { $logs[] = "  • Would translate post_content → {$to} (len " . strlen($content) . ")"; return $logs; }
		
		$result = $this->ask_openai($content, $to, $from, true, $model, $key);
		$translated = $result['content'] ?? '';
		$error = $result['error'] ?? null;
		
		// Check for API errors
		if ($error) {
			$logs[] = "  • ❌ post_content - API Error: {$error}";
			return $logs;
		}
		
		// Validate translation is not empty
		if (!is_string($translated) || trim($translated) === '') {
			$logs[] = "  • ⚠️ Skipped post_content - translation returned empty (original len: " . strlen($content) . ")";
			return $logs;
		}
		
		if ($overwrite) { wp_update_post(['ID'=>$post->ID,'post_content'=>$translated]); $logs[] = "  • Updated post_content (len: " . strlen($translated) . ")"; }
		else { update_post_meta($post->ID, $meta_key, $translated); $logs[] = "  • Saved translation in meta {$meta_key}."; }
		return $logs;
	}

	private function translate_acf_tree(&$logs, $post_id, $fields, $to, $from, $model, $key, $regex, $simulate, $overwrite, $path = [], $parent_selector = '', &$api_error = null) {
		foreach ($fields as $name => $field) {
			$field_key = $field['key'] ?? '';
			$this->translate_acf_node($logs, $post_id, $field, $to, $from, $model, $key, $regex, $simulate, $overwrite, array_merge($path, [$name]), $parent_selector, $api_error);
		}
	}

	private function translate_acf_node(&$logs, $post_id, $field, $to, $from, $model, $key, $regex, $simulate, $overwrite, $path, $parent_selector = '', &$api_error = null) {
		$type = $field['type'] ?? '';
		$name = $field['name'] ?? '';
		$value = $field['value'] ?? null;
		$path_str = implode(' > ', $path);

		$translate_types = ['text','textarea','wysiwyg','message'];

		// Handle nested structures (flexible content, repeaters, groups)
		if (in_array($type, ['repeater','group','flexible_content'])) {
			if (is_array($value)) {
				if ($type === 'flexible_content') {
					foreach ($value as $row_index => $row) {
						$layout = $row['acf_fc_layout'] ?? 'layout';
						// Build selector for this row: parent_selector + field_name + row_index
						$current_selector = $parent_selector ? $parent_selector . '_' . $name . '_' . $row_index : $name . '_' . $row_index;
						
						foreach ($row as $sub_name => $sub_value) {
							if ($sub_name === 'acf_fc_layout') continue;
							
							// Get the sub field object from the layout
							$sub_field = $this->get_flexible_sub_field($field, $layout, $sub_name);
							if (!$sub_field) continue;
							
							$sub_field['value'] = $sub_value;
							$sub_path = array_merge($path, [$row_index, $sub_name]);
							$this->translate_acf_node($logs, $post_id, $sub_field, $to, $from, $model, $key, $regex, $simulate, $overwrite, $sub_path, $current_selector, $api_error);
						}
					}
				} elseif ($type === 'group') {
					// Groups use field key, not row indexes
					$current_selector = $parent_selector ? $parent_selector . '_' . $name : ($field['key'] ?? $name);
					
					foreach ($value as $sub_name => $sub_value) {
						$sub_field = $this->get_sub_field_from_group($field, $sub_name);
						if (!$sub_field) continue;
						
						$sub_field['value'] = $sub_value;
						$sub_path = array_merge($path, [$sub_name]);
						$this->translate_acf_node($logs, $post_id, $sub_field, $to, $from, $model, $key, $regex, $simulate, $overwrite, $sub_path, $current_selector, $api_error);
					}
				} else { // repeater
					foreach ($value as $row_index => $row) {
						if (!is_array($row)) continue;
						$current_selector = $parent_selector ? $parent_selector . '_' . $name . '_' . $row_index : $name . '_' . $row_index;
						
						foreach ($row as $sub_name => $sub_value) {
							$sub_field = $this->get_sub_field_from_repeater($field, $sub_name);
							if (!$sub_field) continue;
							
							$sub_field['value'] = $sub_value;
							$sub_path = array_merge($path, [$row_index, $sub_name]);
							$this->translate_acf_node($logs, $post_id, $sub_field, $to, $from, $model, $key, $regex, $simulate, $overwrite, $sub_path, $current_selector, $api_error);
						}
					}
				}
			}
			return;
		}

		// Skip if field doesn't match regex filter
		if ($name && !preg_match($regex, $name)) { 
			return; // Silent skip for non-matching fields
		}
		
		// Only translate text-like fields
		if (!in_array($type, $translate_types, true)) { 
			return; // Silent skip for non-text fields
		}
		
		// Skip empty values
		if (!is_string($value) || trim($value) === '') return;

		// Check if already translated (non-overwrite mode)
		$meta_key = $name . '_' . $to;
		if (!$overwrite && get_post_meta($post_id, $meta_key, true)) { 
			$logs[] = "  • {$path_str}: already translated ({$meta_key})."; 
			return; 
		}

		if ($simulate) { 
			$logs[] = "  • Would translate {$path_str} → {$to} (len " . strlen($value) . ")"; 
			return; 
		}

		// Translate the content
		$result = $this->ask_openai($value, $to, $from, $type === 'wysiwyg', $model, $key);
		$translated = $result['content'] ?? '';
		$error = $result['error'] ?? null;
		
		// Check for API errors
		if ($error) {
			if (!$api_error) $api_error = $error; // Store first error
			$logs[] = "  • ❌ {$path_str} - API Error: {$error}";
			return;
		}
		
		// Validate translation is not empty
		if (!is_string($translated) || trim($translated) === '') {
			$logs[] = "  • ⚠️ Skipped {$path_str} - translation returned empty (original len: " . strlen($value) . ")";
			return;
		}

		// Save translation
		if ($overwrite) { 
			// Build the full ACF selector
			// For nested fields: parent_selector + _ + field_name
			// For top-level: use field key or field name
			if ($parent_selector) {
				// Nested field: use parent selector + field name
				$field_selector = $parent_selector . '_' . $name;
			} else {
				// Top-level field: use field key (preferred) or field name
				$field_selector = ($field['key'] ?? '') ?: $name;
			}
			
			$update_result = update_field($field_selector, $translated, $post_id);
			if ($update_result !== false) {
				$logs[] = "  • Updated {$path_str} (len: " . strlen($translated) . ")";
			} else {
				$logs[] = "  • Failed to update {$path_str} (selector: {$field_selector})";
			}
		} else { 
			update_post_meta($post_id, $meta_key, $translated); 
			$logs[] = "  • Saved {$path_str} to meta {$meta_key}"; 
		}
	}

	// Helper to get sub field from flexible content layout
	private function get_flexible_sub_field($flex_field, $layout_name, $sub_name) {
		if (!isset($flex_field['layouts'])) return null;
		foreach ($flex_field['layouts'] as $layout) {
			if ($layout['name'] === $layout_name && isset($layout['sub_fields'])) {
				foreach ($layout['sub_fields'] as $sub_field) {
					if ($sub_field['name'] === $sub_name) {
						return $sub_field;
					}
				}
			}
		}
		return null;
	}

	// Helper to get sub field from repeater
	private function get_sub_field_from_repeater($repeater_field, $sub_name) {
		if (!isset($repeater_field['sub_fields'])) return null;
		foreach ($repeater_field['sub_fields'] as $sub_field) {
			if ($sub_field['name'] === $sub_name) {
				return $sub_field;
			}
		}
		return null;
	}

	// Helper to get sub field from group
	private function get_sub_field_from_group($group_field, $sub_name) {
		if (!isset($group_field['sub_fields'])) return null;
		foreach ($group_field['sub_fields'] as $sub_field) {
			if ($sub_field['name'] === $sub_name) {
				return $sub_field;
			}
		}
		return null;
	}

	private function ask_openai($text, $to, $from, $html_safe, $model, $api_key) {
		// Language names for better prompt
		$lang_names = [
			'fi' => 'Finnish', 
			'sv' => 'Swedish', 
			'en-US' => 'American English',
			'en-GB' => 'British English',
			'de' => 'German',
			'fr' => 'French', 
			'es' => 'Spanish', 
			'it' => 'Italian', 
			'no' => 'Norwegian', 
			'da' => 'Danish'
		];
		$from_name = $lang_names[$from] ?? $from;
		$to_name = $lang_names[$to] ?? $to;
		
		$system_prompt = "You are a professional translator specializing in {$from_name} to {$to_name} translation. ";
		
		if ($html_safe) {
			$system_prompt .= "\n\nCRITICAL RULES FOR HTML CONTENT:\n";
			$system_prompt .= "1. PRESERVE ALL HTML STRUCTURE: Keep every <tag>, </tag>, attribute, class, id, and style exactly as provided\n";
			$system_prompt .= "2. ONLY translate visible text content between HTML tags\n";
			$system_prompt .= "3. DO NOT translate: HTML tag names, attributes, CSS classes, IDs, URLs, image paths, shortcodes like [gravityform id=\"8\"]\n";
			$system_prompt .= "4. Keep the exact same number of HTML elements, line breaks, and paragraph structure\n";
			$system_prompt .= "5. Return HTML with identical formatting and indentation as the input\n\n";
		}
		
		$system_prompt .= "Translate accurately while maintaining the original tone, style, and intent. ";
		$system_prompt .= "Do not add explanations, comments, or any text that wasn't in the original. ";
		$system_prompt .= "Do not summarize or omit any information. ";
		$system_prompt .= "If the content is already in {$to_name}, return it unchanged.\n\n";
		$system_prompt .= "Return ONLY the translated content with no additional commentary.";
		
		// Try gpt-4o-mini if gpt-5-mini doesn't work
		$payload = [
			'model' => $model ?: 'gpt-4o-mini',
			'messages' => [
				['role' => 'system', 'content' => $system_prompt],
				['role' => 'user', 'content' => (string)$text],
			],
		];
		
		$resp = wp_remote_post('https://api.openai.com/v1/chat/completions', [
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			],
			'body' => wp_json_encode($payload),
			'timeout' => 60,
		]);
		
		if (is_wp_error($resp)) {
			$error = 'Network error: ' . $resp->get_error_message();
			error_log('OpenAI API Error: ' . $error);
			return ['content' => '', 'error' => $error];
		}
		
		$code = wp_remote_retrieve_response_code($resp);
		$body = json_decode(wp_remote_retrieve_body($resp), true);
		
		if ($code !== 200) {
			$error_msg = $body['error']['message'] ?? wp_remote_retrieve_body($resp);
			$error = "API returned code {$code}: {$error_msg}";
			error_log('OpenAI API Error: ' . $error);
			return ['content' => '', 'error' => $error];
		}
		
		if (empty($body['choices'][0]['message']['content'])) {
			$error = 'API returned empty content';
			error_log('OpenAI API Error: ' . $error . '. Response: ' . print_r($body, true));
			return ['content' => '', 'error' => $error];
		}
		
		return ['content' => (string)$body['choices'][0]['message']['content'], 'error' => null];
	}

	private function coerce_regex($pattern) {
		$pattern = trim((string)$pattern);
		if ($pattern==='') return '/.*/';
		if (@preg_match($pattern, '') !== false) return $pattern;
		return '/' . preg_quote($pattern, '/') . '/i';
	}
}

new ACF_Classic_AI_Translator();
