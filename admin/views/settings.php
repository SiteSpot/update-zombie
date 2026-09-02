<?php
/**
 * Settings view.
 *
 * @package Update_Zombie
 *
 * @var array<string, mixed> $settings     Current settings.
 * @var true|WP_Error        $availability Whether AI analysis can run.
 */

defined( 'ABSPATH' ) || exit;

$uz_name       = Update_Zombie_Settings::OPTION;
$uz_schedules  = wp_get_schedules();
$uz_key_source = Update_Zombie_Credentials::key_source();
?>
<div class="wrap update-zombie-wrap">
	<h1><?php esc_html_e( 'Update Zombie', 'update-zombie' ); ?></h1>

	<?php Update_Zombie_Admin::render_tabs(); ?>

	<?php if ( is_wp_error( $availability ) ) : ?>
		<div class="notice notice-error">
			<p><strong><?php esc_html_e( 'Analysis cannot run yet.', 'update-zombie' ); ?></strong></p>
			<p><?php echo esc_html( $availability->get_error_message() ); ?></p>
		</div>
	<?php else : ?>
		<div class="notice notice-success inline">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: model identifier. */
						__( 'Connected to OpenRouter, using %s.', 'update-zombie' ),
						$settings['model_preference']
					)
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php settings_errors( $uz_name ); ?>

	<form method="post" action="options.php">
		<?php settings_fields( Update_Zombie_Admin::OPTION_GROUP ); ?>

		<h2><?php esc_html_e( 'Analysis engine', 'update-zombie' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'How to judge updates', 'update-zombie' ); ?></th>
				<td>
					<fieldset>
						<label class="uz-radio">
							<input type="radio" name="<?php echo esc_attr( $uz_name ); ?>[analysis_engine]" value="<?php echo esc_attr( Update_Zombie_Settings::ENGINE_AI ); ?>" <?php checked( $settings['analysis_engine'], Update_Zombie_Settings::ENGINE_AI ); ?>>
							<strong><?php esc_html_e( 'AI — read the code', 'update-zombie' ); ?></strong>
							<span class="description"><?php esc_html_e( 'Sends the filtered diff to a model, which judges whether a vulnerability is actually being closed and whether the release is any good. Needs an API key, costs a fraction of a cent per update, and the diff leaves your site.', 'update-zombie' ); ?></span>
						</label>

						<label class="uz-radio">
							<input type="radio" name="<?php echo esc_attr( $uz_name ); ?>[analysis_engine]" value="<?php echo esc_attr( Update_Zombie_Settings::ENGINE_SIGNALS ); ?>" <?php checked( $settings['analysis_engine'], Update_Zombie_Settings::ENGINE_SIGNALS ); ?>>
							<strong><?php esc_html_e( 'No AI — changelog and pattern scan', 'update-zombie' ); ?></strong>
							<span class="description"><?php esc_html_e( 'No API key, no cost, and nothing leaves your site. Reads the changelog for security language and scans the diff for matching code changes. It can tell you security checks were added; it cannot tell you whether they work. Every "What changed" fact is identical in both engines.', 'update-zombie' ); ?></span>
						</label>
					</fieldset>
				</td>
			</tr>

		</table>

		<h2><?php esc_html_e( 'AI provider', 'update-zombie' ); ?></h2>

		<p class="description">
			<?php esc_html_e( 'WordPress ships the AI Client API but does not register any provider or store any keys, so Update Zombie registers OpenRouter itself. Everything it registers is available to other plugins using the same core API.', 'update-zombie' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="uz-key"><?php esc_html_e( 'OpenRouter API key', 'update-zombie' ); ?></label>
				</th>
				<td>
					<?php if ( Update_Zombie_Credentials::SOURCE_CONSTANT === $uz_key_source ) : ?>
						<p>
							<strong><?php esc_html_e( 'Set in wp-config.php.', 'update-zombie' ); ?></strong>
							<code><?php echo esc_html( Update_Zombie_Credentials::masked_key() ); ?></code>
						</p>
						<p class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: PHP constant name. */
									__( 'The %s constant takes priority and keeps the key out of the database. Remove it from wp-config.php to enter a key here instead.', 'update-zombie' ),
									Update_Zombie_Credentials::CONSTANT
								)
							);
							?>
						</p>
					<?php else : ?>
						<input type="password" id="uz-key" name="<?php echo esc_attr( $uz_name ); ?>[openrouter_key]" value="" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( Update_Zombie_Credentials::SOURCE_OPTION === $uz_key_source ? Update_Zombie_Credentials::masked_key() : 'sk-or-v1-…' ); ?>">

						<?php if ( Update_Zombie_Credentials::SOURCE_OPTION === $uz_key_source ) : ?>
							<p class="description"><?php esc_html_e( 'A key is stored. Leave this blank to keep it, or paste a new one to replace it.', 'update-zombie' ); ?></p>
							<p>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $uz_name ); ?>[openrouter_key_clear]" value="1">
									<?php esc_html_e( 'Delete the stored key', 'update-zombie' ); ?>
								</label>
							</p>
						<?php endif; ?>

						<p class="description uz-warning">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: PHP constant name. */
									__( 'Stored in the database in plain text, so it appears in database backups. For a production site, define %s in wp-config.php instead — it overrides this field.', 'update-zombie' ),
									Update_Zombie_Credentials::CONSTANT
								)
							);
							?>
						</p>
						<p class="description">
							<a href="https://openrouter.ai/keys" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get a key from OpenRouter', 'update-zombie' ); ?></a>
						</p>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="uz-model"><?php esc_html_e( 'Model', 'update-zombie' ); ?></label>
				</th>
				<td>
					<input type="text" id="uz-model" name="<?php echo esc_attr( $uz_name ); ?>[model_preference]" value="<?php echo esc_attr( (string) $settings['model_preference'] ); ?>" class="regular-text" list="uz-model-list">
					<datalist id="uz-model-list">
						<?php foreach ( Update_Zombie_OpenRouter_Directory::known_models() as $uz_id => $uz_label ) : ?>
							<option value="<?php echo esc_attr( $uz_id ); ?>"><?php echo esc_html( $uz_label ); ?></option>
						<?php endforeach; ?>
					</datalist>
					<p class="description">
						<?php esc_html_e( 'Any OpenRouter model ID works, not just the suggestions. Reading diffs rewards a capable model with a large context window.', 'update-zombie' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="uz-timeout"><?php esc_html_e( 'Minimum request timeout', 'update-zombie' ); ?></label>
				</th>
				<td>
					<input type="number" min="30" max="900" id="uz-timeout" name="<?php echo esc_attr( $uz_name ); ?>[request_timeout]" value="<?php echo esc_attr( (string) $settings['request_timeout'] ); ?>" class="small-text">
					<span><?php esc_html_e( 'seconds', 'update-zombie' ); ?></span>
					<p class="description"><?php esc_html_e( 'A floor, not a ceiling. Big diffs automatically get longer — about a second per thousand tokens sent, up to 15 minutes — so you do not have to guess. Raise this only if small updates are timing out.', 'update-zombie' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'What Update Zombie does with its verdicts', 'update-zombie' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Mode', 'update-zombie' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Enforcement mode', 'update-zombie' ); ?></legend>

						<label class="uz-radio">
							<input type="radio" name="<?php echo esc_attr( $uz_name ); ?>[mode]" value="<?php echo esc_attr( Update_Zombie_Settings::MODE_ADVISORY ); ?>" <?php checked( $settings['mode'], Update_Zombie_Settings::MODE_ADVISORY ); ?>>
							<strong><?php esc_html_e( 'Advisory', 'update-zombie' ); ?></strong>
							<span class="description"><?php esc_html_e( 'Report on every update and change nothing. WordPress installs exactly what it would have installed anyway. Nothing can be held back, so you can never be stranded on a vulnerable version by a wrong verdict.', 'update-zombie' ); ?></span>
						</label>

						<label class="uz-radio">
							<input type="radio" name="<?php echo esc_attr( $uz_name ); ?>[mode]" value="<?php echo esc_attr( Update_Zombie_Settings::MODE_GUARDED ); ?>" <?php checked( $settings['mode'], Update_Zombie_Settings::MODE_GUARDED ); ?>>
							<strong><?php esc_html_e( 'Guarded', 'update-zombie' ); ?></strong>
							<span class="description"><?php esc_html_e( 'Install high-impact security fixes automatically. Hold back anything judged questionable or shit until you approve it. Lower-impact fixes and everything else follow your normal WordPress settings.', 'update-zombie' ); ?></span>
						</label>

						<label class="uz-radio">
							<input type="radio" name="<?php echo esc_attr( $uz_name ); ?>[mode]" value="<?php echo esc_attr( Update_Zombie_Settings::MODE_AUTOPILOT ); ?>" <?php checked( $settings['mode'], Update_Zombie_Settings::MODE_AUTOPILOT ); ?>>
							<strong><?php esc_html_e( 'Autopilot', 'update-zombie' ); ?></strong>
							<span class="description"><?php esc_html_e( 'Install high-impact security fixes and updates judged good. Hold back questionable and shit ones. The most hands-off setting, and the one that trusts the model most.', 'update-zombie' ); ?></span>
						</label>
					</fieldset>

					<p class="description uz-warning">
						<?php esc_html_e( 'Holding an update back does not stop you installing it by hand. Guarded and Autopilot only change what installs unattended.', 'update-zombie' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="uz-security-confidence"><?php esc_html_e( 'Auto-install threshold', 'update-zombie' ); ?></label>
				</th>
				<td>
					<input type="number" min="0" max="100" id="uz-security-confidence" name="<?php echo esc_attr( $uz_name ); ?>[security_confidence]" value="<?php echo esc_attr( (string) $settings['security_confidence'] ); ?>" class="small-text">
					<span>%</span>
					<p class="description"><?php esc_html_e( 'Only a high- or critical-impact security fix can install automatically, and only when the model is at least this confident about that same finding. Lower-impact or uncertain fixes wait for your normal WordPress policy.', 'update-zombie' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'What to watch', 'update-zombie' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Sources', 'update-zombie' ); ?></th>
				<td>
					<fieldset>
						<label><input type="checkbox" name="<?php echo esc_attr( $uz_name ); ?>[watch_plugins]" value="1" <?php checked( $settings['watch_plugins'] ); ?>> <?php esc_html_e( 'Plugin updates', 'update-zombie' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $uz_name ); ?>[watch_themes]" value="1" <?php checked( $settings['watch_themes'] ); ?>> <?php esc_html_e( 'Theme updates', 'update-zombie' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $uz_name ); ?>[watch_core]" value="1" <?php checked( $settings['watch_core'] ); ?>> <?php esc_html_e( 'WordPress core updates', 'update-zombie' ); ?></label>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Core diffs are large. Only wp-admin, wp-includes and the root PHP files are compared, and your wp-config.php is never read.', 'update-zombie' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Core major releases', 'update-zombie' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $uz_name ); ?>[core_majors]" value="1" <?php checked( $settings['core_majors'] ); ?>>
						<?php esc_html_e( 'Allow major core releases to install automatically', 'update-zombie' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Off by default. With this off, only point releases such as 7.1 to 7.1.1 can install unattended; a jump to 7.2 always waits for you, whatever the verdict says.', 'update-zombie' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="uz-interval"><?php esc_html_e( 'Check for new updates', 'update-zombie' ); ?></label>
				</th>
				<td>
					<select id="uz-interval" name="<?php echo esc_attr( $uz_name ); ?>[analysis_interval]">
						<?php foreach ( $uz_schedules as $uz_key => $uz_schedule ) : ?>
							<option value="<?php echo esc_attr( $uz_key ); ?>" <?php selected( $settings['analysis_interval'], $uz_key ); ?>>
								<?php echo esc_html( $uz_schedule['display'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Queued updates are analysed one at a time, five minutes apart, so a burst of updates does not hammer your provider.', 'update-zombie' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'How much to read', 'update-zombie' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="uz-budget"><?php esc_html_e( 'Diff budget', 'update-zombie' ); ?></label>
				</th>
				<td>
					<select id="uz-budget" name="<?php echo esc_attr( $uz_name ); ?>[diff_char_budget]">
						<?php
						$uz_budgets = Update_Zombie_Settings::budget_presets();
						$uz_current = (int) $settings['diff_char_budget'];

						// Keep a custom value set by a filter or an older version
						// selectable, so opening this screen never silently
						// changes it.
						if ( ! isset( $uz_budgets[ $uz_current ] ) ) {
							$uz_budgets[ $uz_current ] = sprintf(
								/* translators: %s: number of characters. */
								__( '%s characters (custom)', 'update-zombie' ),
								number_format_i18n( $uz_current )
							);
							ksort( $uz_budgets );
						}

						foreach ( $uz_budgets as $uz_value => $uz_label ) :
							?>
							<option value="<?php echo esc_attr( (string) $uz_value ); ?>" <?php selected( $uz_current, $uz_value ); ?>>
								<?php echo esc_html( $uz_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The most diff sent to the model for one update. Vendor code, minified assets, images and translations are stripped first, then PHP and JavaScript are prioritised, so the budget is spent on the files that matter.', 'update-zombie' ); ?></p>
					<p class="description uz-warning">
						<?php esc_html_e( 'Bigger is not better. On a real plugin update, a 1 MB prompt produced a fluent summary with no cited findings at all and cost thirteen times as much as the same update at 300 KB, which returned seven cited fixes. A model reading well is not the same as a model with a large context window. Raise this only if reports are routinely omitting files you care about.', 'update-zombie' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="uz-max-file"><?php esc_html_e( 'Skip files larger than', 'update-zombie' ); ?></label>
				</th>
				<td>
					<select id="uz-max-file" name="<?php echo esc_attr( $uz_name ); ?>[max_file_bytes]">
						<?php
						$uz_sizes        = Update_Zombie_Settings::file_size_presets();
						$uz_current_size = (int) $settings['max_file_bytes'];

						if ( ! isset( $uz_sizes[ $uz_current_size ] ) ) {
							$uz_sizes[ $uz_current_size ] = sprintf(
								/* translators: %s: formatted file size. */
								__( '%s (custom)', 'update-zombie' ),
								size_format( $uz_current_size )
							);
							ksort( $uz_sizes );
						}

						foreach ( $uz_sizes as $uz_value => $uz_label ) :
							?>
							<option value="<?php echo esc_attr( (string) $uz_value ); ?>" <?php selected( $uz_current_size, $uz_value ); ?>>
								<?php echo esc_html( $uz_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'A single file bigger than this is noted as changed but not read. Generated or concatenated files are usually the only ones this large.', 'update-zombie' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="uz-retention"><?php esc_html_e( 'Keep reports for', 'update-zombie' ); ?></label>
				</th>
				<td>
					<input type="number" min="1" max="3650" id="uz-retention" name="<?php echo esc_attr( $uz_name ); ?>[retention_days]" value="<?php echo esc_attr( (string) $settings['retention_days'] ); ?>" class="small-text">
					<span><?php esc_html_e( 'days', 'update-zombie' ); ?></span>
					<p class="description"><?php esc_html_e( 'Verdicts and the excerpts the model cited are kept. Full diffs are never stored.', 'update-zombie' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Notifications', 'update-zombie' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Email', 'update-zombie' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $uz_name ); ?>[notify_email]" value="1" <?php checked( $settings['notify_email'] ); ?>> <?php esc_html_e( 'Send email notifications', 'update-zombie' ); ?></label>
					<p>
						<input type="email" name="<?php echo esc_attr( $uz_name ); ?>[notify_email_address]" value="<?php echo esc_attr( (string) $settings['notify_email_address'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">
						<span class="description"><?php esc_html_e( 'Leave empty to use the site admin address.', 'update-zombie' ); ?></span>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Tell me when', 'update-zombie' ); ?></th>
				<td>
					<fieldset>
						<label><input type="checkbox" name="<?php echo esc_attr( $uz_name ); ?>[notify_on_security]" value="1" <?php checked( $settings['notify_on_security'] ); ?>> <?php esc_html_e( 'An update is a security fix, or one is installed automatically', 'update-zombie' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $uz_name ); ?>[notify_on_concerns]" value="1" <?php checked( $settings['notify_on_concerns'] ); ?>> <?php esc_html_e( 'An update raises concerns', 'update-zombie' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $uz_name ); ?>[notify_on_held]" value="1" <?php checked( $settings['notify_on_held'] ); ?>> <?php esc_html_e( 'An update is held back', 'update-zombie' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $uz_name ); ?>[notify_on_error]" value="1" <?php checked( $settings['notify_on_error'] ); ?>> <?php esc_html_e( 'An analysis fails', 'update-zombie' ); ?></label>
					</fieldset>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Webhook', 'update-zombie' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $uz_name ); ?>[webhook_enabled]" value="1" <?php checked( $settings['webhook_enabled'] ); ?>> <?php esc_html_e( 'POST each verdict to a URL', 'update-zombie' ); ?></label>
					<p>
						<input type="url" name="<?php echo esc_attr( $uz_name ); ?>[webhook_url]" value="<?php echo esc_attr( (string) $settings['webhook_url'] ); ?>" class="large-text" placeholder="https://hooks.example.com/…">
					</p>
					<p>
						<input type="text" name="<?php echo esc_attr( $uz_name ); ?>[webhook_secret]" value="<?php echo esc_attr( (string) $settings['webhook_secret'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Signing secret (optional)', 'update-zombie' ); ?>">
						<span class="description"><?php esc_html_e( 'If set, requests carry an X-Update-Zombie-Signature header: sha256= plus an HMAC of the body.', 'update-zombie' ); ?></span>
					</p>
					<p class="description uz-warning"><?php esc_html_e( 'The payload includes your site URL, which items are being updated, and the verdict text. Only point this at somewhere you control.', 'update-zombie' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
