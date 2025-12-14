<?php

declare(strict_types=1);

namespace BbApp\ContentSource\BbPress;

use BbApp\ContentSource\WordPressBase\WordPressBaseContentSource;
use WP_Post, WP_REST_Server, WP_REST_Request, WP_REST_Response;
use UnexpectedValueException;

/**
 * bbPress content source implementation for forums, topics, and replies.
 */
class BbPressContentSource extends WordPressBaseContentSource
{
	public $id = 'bbpress';

	/**
	 * Initializes bbPress content source with entity types and capabilities.
	 */
	public function __construct()
	{
		require_once ABSPATH . 'wp-content/plugins/bbpress/bbpress.php';
		require_once ABSPATH . 'wp-content/plugins/bbpress/includes/forums/template.php';

		$this->capabilities = [
			'section' => ['view' => 'read_forum', 'post' => 'publish_topics'],
			'post' => ['view' => 'read_topic', 'edit' => 'edit_topic', 'comment' => 'publish_replies'],
			'comment' => ['edit' => 'edit_reply']
		];

		$this->entity_types = [
			'section' => bbp_get_forum_post_type(),
			'post' => bbp_get_topic_post_type(),
			'comment' => bbp_get_reply_post_type()
		];

		parent::__construct();
	}

	/**
	 * Extend topic schema with bbPress metadata.
	 */
	public function rest_topic_item_schema(array $schema): array
	{
		if (!is_user_logged_in() && bbp_allow_anonymous()) {
			$schema['properties']['meta']['guest_id'] = [
				'description' => __('Guest identity UUID for anonymous users.', 'bb-app'),
				'type' => 'string',
				'format' => 'uuid',
				'context' => ['edit']
			];
		}

		return $schema;
	}

	/**
	 * Extend reply schema with bbPress metadata.
	 */
	public function rest_reply_item_schema(array $schema): array
	{
		if (!is_user_logged_in() && bbp_allow_anonymous()) {
			$schema['properties']['meta']['guest_id'] = [
				'description' => __('Guest identity UUID for anonymous users.', 'bb-app'),
				'type' => 'string',
				'format' => 'uuid',
				'context' => ['edit']
			];
		}

		return $schema;
	}

	/**
	 * Validate requested bbPress IDs during REST dispatch and annotate rejects.
	 */
	public function rest_post_dispatch(
		$response,
		WP_REST_Server $server,
		WP_REST_Request $request
	): WP_REST_Response {
		$outer_request = $this->get_current_request();

		if (
			str_starts_with($outer_request->get_route(), '/batch/v1') &&
			!str_starts_with($request->get_route(), '/batch/v1')
		) {
			return $response;
		}

		global $wpdb;

		$forumIds = $this->parse_json_int_array($request->get_header('Bb-App-Expects-BbPress-Forum-Ids'));
		$topicIds = $this->parse_json_int_array($request->get_header('Bb-App-Expects-BbPress-Topic-Ids'));
		$replyIds = $this->parse_json_int_array($request->get_header('Bb-App-Expects-BbPress-Reply-Ids'));

		if (!empty($forumIds)) {
			$placeholders = $this->build_in_placeholders($forumIds);

			$sql = $wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p WHERE p.ID IN ({$placeholders}) AND p.post_type = 'forum' AND p.post_status = 'publish'",
				$forumIds
			);

			$found = array_map('intval', $wpdb->get_col($sql));
			$rejects = array_values(array_diff($forumIds, $found));

			if (!empty($rejects)) {
				$response->header('Bb-App-Rejects-BbPress-Forum-Ids', wp_json_encode($rejects));
			}
		}

		if (!empty($topicIds)) {
			$placeholders = $this->build_in_placeholders($topicIds);

			$sql = $wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p WHERE p.ID IN ({$placeholders}) AND p.post_type = 'topic' AND p.post_status = 'publish'",
				$topicIds
			);

			$found = array_map('intval', $wpdb->get_col($sql));
			$rejects = array_values(array_diff($topicIds, $found));

			if (!empty($rejects)) {
				$response->header('Bb-App-Rejects-BbPress-Topic-Ids', wp_json_encode($rejects));
			}
		}

		if (!empty($replyIds)) {
			$placeholders = $this->build_in_placeholders($replyIds);

			$sql = $wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p WHERE p.ID IN ({$placeholders}) AND p.post_type = 'reply' AND p.post_status = 'publish'",
				$replyIds
			);

			$found = array_map('intval', $wpdb->get_col($sql));
			$rejects = array_values(array_diff($replyIds, $found));

			if (!empty($rejects)) {
				$response->header('Bb-App-Rejects-BbPress-Reply-Ids', wp_json_encode($rejects));
			}
		}

		return $response;
	}

	/**
	 * Retrieves bbPress forum, topic, or reply by content type and ID.
	 */
	public function get_content(string $content_type, int $id)
	{
		switch ($content_type) {
			case 'section':
				return bbp_get_forum($id);
			case 'post':
				return bbp_get_topic($id);
			case 'comment':
				return bbp_get_reply($id);
			default:
				throw new UnexpectedValueException();
		}
	}

	/**
	 * Determines content type from bbPress post type.
	 */
	public function get_content_type($object): string
	{
		switch ($object->post_type) {
			case $this->get_entity_types('section'):
				return 'section';
			case $this->get_entity_types('post'):
				return 'post';
			case $this->get_entity_types('comment'):
				return 'comment';
			default:
				throw new UnexpectedValueException();
		}
	}

	/**
	 * Gets permalink for bbPress content by type and ID.
	 */
	public function get_link(string $content_type, int $id): string
	{
		switch ($content_type) {
			case 'section':
				return bbp_get_forum_permalink($id) ?: '';
			case 'post':
				return bbp_get_topic_permalink($id) ?: '';
			case 'comment':
				return bbp_get_reply_permalink($id) ?: '';
			default:
				throw new UnexpectedValueException();
		}
	}

	/**
	 * Resolves bbPress URL to content type and ID.
	 */
	public function resolve_incoming_url(string $url): ?array
	{
		if (!$this->callbacks->url_match_checker($url)) {
			return null;
		}

		$post_id = url_to_postid($url);

		if ($post_id > 0) {
			$post = get_post($post_id);

			if ($post instanceof WP_Post) {
				switch ($post->post_type) {
					case $this->get_entity_types('section'):
						return ['content_type' => 'section', 'id' => $post->ID];
					case $this->get_entity_types('post'):
						return ['content_type' => 'post', 'id' => $post->ID];
					case $this->get_entity_types('comment'):
						return ['content_type' => 'comment', 'id' => $post->ID];
				}
			}
		}

		return null;
	}

	/**
	 * Checks if user has permission for action on bbPress content.
	 */
	public function user_can(
		int $user_id,
		string $intent,
		string $content_type,
		int $content_id
	): bool {
		if ($content_type === 'section' && $intent === 'post') {
			if (
				bbp_is_forum_closed($content_id) ||
				bbp_current_user_can_publish_topics() === false
			) {
				return false;
			}
		}

		if ($content_type === 'post' && $intent === 'comment') {
			$topic = bbp_get_topic($content_id);

			if (empty($topic)) {
				return false;
			}

			if (
				bbp_is_topic_closed($content_id) ||
				bbp_current_user_can_publish_replies() === false
			) {
				return false;
			}
		}

		if (
			$user_id === 0 &&
			(($content_type === 'section' && $intent === 'post') || ($content_type === 'post' && $intent === 'comment')) &&
			bbp_allow_anonymous()
		) {
			return true;
		}

		if ($content_type === 'section' && $intent === 'view') {
			if (bbp_is_forum_hidden($content_id) || bbp_is_forum_private($content_id)) {
				return user_can($user_id, 'read_forum', $content_id);
			}

			return true;
		}

		if ($intent === 'view') {
			if ($content_type === 'post' && $user_id === 0) {
				$topic = bbp_get_topic($content_id);
				return $topic->post_status === "publish";
			}

			if ($content_type === 'comment') {
				$reply = bbp_get_reply($content_id);
				return $reply->post_status === "publish";
			}
		}

		return parent::user_can($user_id, $intent, $content_type, $content_id);
	}

	/**
	 * Gets configured root forum ID from WordPress options.
	 */
	public function get_root_section_id(): int
	{
		return (int) get_option('bb_app_bbpress_root_section_id', '0');
	}

	/**
	 * Gets parent ID of the configured root forum.
	 */
	public function get_root_parent_id(): int
	{
		$root_section_id = $this->get_root_section_id();

		if (empty($root_section_id)) {
			return -1;
		}

		$root_parent_id = get_post_field('post_parent', $root_section_id);

		if (empty($root_parent_id) || is_wp_error($root_parent_id)) {
			return -1;
		}

		return (int) $root_parent_id;
	}

	public function register(): void
	{
		parent::register();

		add_filter('rest_topic_item_schema', [$this, 'rest_topic_item_schema']);
		add_filter('rest_reply_item_schema', [$this, 'rest_reply_item_schema']);
		add_filter('rest_prepare_forum', [$this, 'rest_prepare_post'], 10, 3);
		add_filter('rest_prepare_topic', [$this, 'rest_prepare_post'], 10, 3);
		add_filter('rest_prepare_reply', [$this, 'rest_prepare_post'], 10, 3);
		add_filter('rest_post_dispatch', [$this, 'rest_post_dispatch'], 20, 3);
	}
}
