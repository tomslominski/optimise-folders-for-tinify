<?php
/**
 * Plugin Name: Optimise Folders for Tinify
 * Description: Compress JPG and PNG images inside external folders using the Tinify API.
 * Plugin URI: https://slomin.ski/
 * Author: Tom Slominski
 * Version: 0.0.1
 * Author URI: https://slomin.ski/
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

class OFT
{
	/**
	 * @var OFT|null Singleton class instance.
	 */
	private static $instance = null;

	/**
	 * Start plugin.
	 */
	private function __construct()
	{
		register_activation_hook(__FILE__, ['OFT', 'activation_hook']);
		register_deactivation_hook(__FILE__, ['OFT', 'deactivation_hook']);
		add_action('oft_optimize_images', [$this, 'optimise_images']);
		add_action('admin_init', [$this, 'admin_notice']);
	}

	/**
	 * @return OFT Plugin instance.
	 */
	public static function get_instance(): OFT
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Set up cron event on plugin activation.
	 */
	public static function activation_hook()
	{
		if (!wp_next_scheduled('oft_optimize_images')) {
			$date = (new DateTime())->setTime(3, 0);

			if ($date < new DateTime()) {
				$date->add(new DateInterval('P1D'));
			}

			wp_schedule_event($date->format('U'), 'daily', 'oft_optimize_images');
		}
	}

	/**
	 * Remove cron event on plugin activation.
	 */
	public static function deactivation_hook()
	{
		wp_unschedule_event(wp_next_scheduled('oft_optimize_images'), 'oft_optimize_images');
	}

	/**
	 * Check if the plugin can be run.
	 *
	 * @return bool|string Error code as string or true if plugin can be run.
	 */
	public function can_run_plugin()
	{
		global $tiny_plugin;

		if (!isset($tiny_plugin) || !function_exists('\Tinify\fromFile')) {
			return 'plugin_missing';
		} elseif (!$this->get_api_key()) {
			return 'api_key_missing';
		}

		return true;
	}

	/**
	 * @return string|null Tinify API key or null if not set.
	 */
	public function get_api_key(): ?string
	{
		if (defined('TINY_API_KEY')) {
			return TINY_API_KEY;
		} else {
			return get_option(Tiny_WP_Base::PREFIX . 'api_key', null);
		}
	}

	/**
	 * Display an admin notice if the plugin is not able to run.
	 */
	public function admin_notice()
	{
		if (true !== $this->can_run_plugin()) {
			add_action('admin_notices', function() {
				$screen = get_current_screen();

				if (current_user_can('install_plugins') && isset($screen->id) && 'plugins' === $screen->id) {
					$message = '';

					switch ($this->can_run_plugin()) {
						case 'plugin_missing':
							$message = sprintf(__('The "Compress JPEG & PNG images" plugin is missing. Please <a href="%s">install it</a> first before using this plugin.', 'optimise-folders-for-tinify'), admin_url('plugin-install.php?s=Compress+JPEG+%26+PNG+images&tab=search&type=term'));
							break;

						case 'api_key_missing':
							$message = sprintf(__('Please set up a Tinify API key in the <a href="%s">settings of the Compress JPEG & PNG images plugin</a>.', 'optimise-folders-for-tinify'), admin_url('options-general.php?page=tinify'));
							break;
					}

					if ($message) {
						echo '<div class="notice notice-error"><p>' . $message . '</p></div>';
					}
				}
			});
		}
	}

	/**
	 * Return all images from the filesystem, including ones which have
	 * been optimised previously.
	 *
	 * @return array Array of paths relative to wp-content.
	 */
	public function get_all_images(): array
	{
		$folders = apply_filters('oft\folders', [
			'fly-images' => self::path_join(WP_CONTENT_DIR, '/uploads/fly-images/'),
		]);
		$images = [];

		foreach ($folders as $folder) {
			$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder));

			foreach ($rii as $file) {
				if ($file->isFile() && in_array($file->getExtension(), ['jpg', 'jpeg', 'png'])) {
					$images[] = str_replace(WP_CONTENT_DIR, '', $file->getPathname());
				}
			}
		}

		return $images;
	}

	/**
	 * Return all previously optimised images.
	 *
	 * @return array Array of paths relative to wp-content.
	 */
	public function get_optimised_images(): array
	{
		return get_option('oft_optimised_images', []);
	}

	/**
	 * Get images which are yet to be optimised.
	 *
	 * @return array Array of paths relative to wp-content.
	 */
	public function get_images_to_optimise(): array
	{
		return array_diff($this->get_all_images(), $this->get_optimised_images());
	}

	/**
	 * Run the image optimisation process.
	 */
	public function optimise_images()
	{
		// Check if plugin can run
		if (true !== $this->can_run_plugin()) {
			error_log('[Optimise Folders for Tinify] The plugin is active, but cannot run. Check the Plugins page for more information.');
			return;
		}

		// Set and validate API key
		try {
			\Tinify\setKey($this->get_api_key());
			\Tinify\validate();
		} catch (\Tinify\Exception $e) {
			error_log('[Optimise Folders for Tinify] An API key validation error occurred while optimising: ' . $e->getMessage());
			return;
		}

		$images = $this->get_images_to_optimise();
		$optimised = [];

		foreach ($images as $image) {
			$file = self::path_join(WP_CONTENT_DIR, $image);

			try {
				// $source = \Tinify\fromFile($file);
				// $source->toFile($file);
				sleep(3);
				$optimised[] = $image;
			} catch (\Tinify\AccountException $e) {
				error_log('[Optimise Folders for Tinify] An API key error occurred while optimising: ' . $e->getMessage());
				break;
			} catch (Exception $e) {
				error_log('[Optimise Folders for Tinify] An error occurred while optimising: ' . $e->getMessage());
			}
		}

		update_option('oft_optimised_images', $this->get_optimised_images() + $optimised);
	}

	/**
	 * Join two paths together.
	 *
	 * @param string $path1
	 * @param string $path2
	 * @return string
	 */
	public static function path_join(string $path1, string $path2): string
	{
		return rtrim($path1, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path2, DIRECTORY_SEPARATOR);
	}
}

OFT::get_instance();
