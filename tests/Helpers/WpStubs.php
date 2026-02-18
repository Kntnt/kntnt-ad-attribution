<?php
/**
 * WordPress stubs for unit testing.
 *
 * Defines constants, classes, and globals that plugin code references at
 * include time, allowing autoloaded classes to be parsed without a running
 * WordPress installation.
 *
 * This file is loaded via Composer autoload-dev "files" so it runs before
 * any test or plugin code.
 *
 * @package Tests
 * @since   1.0.0
 */

declare(strict_types=1);

// ─── WordPress constants ───

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

// ─── WP_List_Table stub ───

if (!class_exists('WP_List_Table')) {

    /**
     * Minimal WP_List_Table stub for autoloading.
     *
     * Provides just enough surface area so that Url_List_Table and
     * Campaign_List_Table can be parsed and instantiated in tests.
     */
    class WP_List_Table {

        /** @var array<string,mixed> */
        public array $items = [];

        /** @var array{total_items:int,total_pages:int,per_page:int} */
        protected array $_pagination_args = [];

        /** @var array{singular:string,plural:string,ajax:bool} */
        protected array $_args = [];

        /**
         * @param array<string,mixed> $args Table arguments.
         */
        public function __construct(array $args = []) {
            $this->_args = wp_parse_args($args, [
                'singular' => '',
                'plural' => '',
                'ajax' => false,
            ]);
        }

        /** @return array<string,string> */
        public function get_columns(): array {
            return [];
        }

        /** @return array<string,array{0:string,1:bool}> */
        protected function get_sortable_columns(): array {
            return [];
        }

        /** @return array<string,string> */
        protected function get_bulk_actions(): array {
            return [];
        }

        /** @var array */
        public array $_column_headers = [];

        /** @return void */
        public function prepare_items(): void {}

        /** @return void */
        public function display(): void {}

        /** @return int */
        public function get_pagenum(): int {
            return 1;
        }

        /**
         * @param array<string,string> $actions Row actions.
         * @param bool                 $always_visible Always visible flag.
         *
         * @return string
         */
        protected function row_actions(array $actions, bool $always_visible = false): string {
            $parts = [];
            foreach ($actions as $action => $link) {
                $parts[] = "<span class='{$action}'>{$link}</span>";
            }
            return '<div class="row-actions">' . implode(' | ', $parts) . '</div>';
        }

        /**
         * @param string $text  Button text.
         * @param string $input_id Input ID.
         *
         * @return void
         */
        public function search_box(string $text, string $input_id): void {}

        /** @return void */
        public function display_rows(): void {}

        /** @return void */
        public function views(): void {}

        /**
         * @param string $key  Pagination argument key.
         * @param mixed  $value Value (optional).
         *
         * @return mixed
         */
        protected function set_pagination_args(array $args): void {
            $this->_pagination_args = $args;
        }

        /**
         * @param string $key Pagination argument key.
         *
         * @return mixed
         */
        protected function get_pagination_arg(string $key): mixed {
            return $this->_pagination_args[$key] ?? null;
        }

        /**
         * @param object|array<string,mixed> $item Item data.
         * @param string                     $column_name Column name.
         *
         * @return string
         */
        protected function column_default($item, $column_name): string {
            return '';
        }

        /**
         * @param object|array<string,mixed> $item Item data.
         *
         * @return string
         */
        protected function column_cb($item): string {
            return '';
        }

        /**
         * @return string
         */
        protected function get_default_primary_column_name(): string {
            return '';
        }

        /**
         * @param object|array<string,mixed> $item        Item data.
         * @param string                     $column_name Column name.
         * @param string                     $primary     Primary column name.
         *
         * @return string
         */
        protected function handle_row_actions($item, $column_name, $primary): string {
            return '';
        }

        /**
         * @return int[]
         */
        protected function get_items_per_page(string $option, int $default = 20): int {
            return $default;
        }

        /**
         * @return string[]
         */
        protected function get_views(): array {
            return [];
        }

        /** @return void */
        protected function display_tablenav(string $which): void {}

        /**
         * @param string $which Top or bottom.
         *
         * @return void
         */
        protected function extra_tablenav(string $which): void {}

        /** @return string|false */
        public function current_action(): string|false {
            return false;
        }
    }
}

// ─── WP_Post stub ───

if (!class_exists('WP_Post')) {

    /**
     * Minimal WP_Post stub.
     */
    class WP_Post {

        /** @var int */
        public int $ID = 0;

        /** @var string */
        public string $post_title = '';

        /** @var string */
        public string $post_type = 'post';

        /** @var string */
        public string $post_status = 'publish';

        /** @var string */
        public string $post_name = '';

        /** @var string */
        public string $post_content = '';

        /** @var string */
        public string $post_date = '';

        /** @var string */
        public string $post_modified = '';

        /** @var int */
        public int $post_author = 0;

        /** @var int */
        public int $post_parent = 0;
    }
}

// ─── WP_REST_Request stub ───

if (!class_exists('WP_REST_Request')) {

    /**
     * Minimal WP_REST_Request stub.
     */
    class WP_REST_Request {

        /** @var array<string,mixed> */
        private array $params = [];

        /** @var string */
        private string $method;

        /** @var string */
        private string $route;

        /**
         * @param string $method HTTP method.
         * @param string $route  Route path.
         */
        public function __construct(string $method = 'GET', string $route = '') {
            $this->method = $method;
            $this->route = $route;
        }

        /**
         * @param string $key Parameter key.
         *
         * @return mixed
         */
        public function get_param(string $key): mixed {
            return $this->params[$key] ?? null;
        }

        /**
         * @param string $key   Parameter key.
         * @param mixed  $value Parameter value.
         *
         * @return void
         */
        public function set_param(string $key, mixed $value): void {
            $this->params[$key] = $value;
        }

        /**
         * @return array<string,mixed>
         */
        public function get_params(): array {
            return $this->params;
        }

        /**
         * @return string
         */
        public function get_method(): string {
            return $this->method;
        }

        /**
         * @return string
         */
        public function get_route(): string {
            return $this->route;
        }
    }
}

// ─── WP_REST_Response stub ───

if (!class_exists('WP_REST_Response')) {

    /**
     * Minimal WP_REST_Response stub.
     */
    class WP_REST_Response {

        /** @var mixed */
        public mixed $data;

        /** @var int */
        public int $status;

        /**
         * @param mixed $data   Response data.
         * @param int   $status HTTP status code.
         */
        public function __construct(mixed $data = null, int $status = 200) {
            $this->data = $data;
            $this->status = $status;
        }

        /**
         * @return mixed
         */
        public function get_data(): mixed {
            return $this->data;
        }

        /**
         * @return int
         */
        public function get_status(): int {
            return $this->status;
        }
    }
}

// ─── WP_REST_Server constants stub ───

if (!class_exists('WP_REST_Server')) {

    /**
     * Minimal WP_REST_Server stub for HTTP method constants.
     */
    class WP_REST_Server {
        public const READABLE = 'GET';
        public const CREATABLE = 'POST';
        public const EDITABLE = 'POST, PUT, PATCH';
        public const DELETABLE = 'DELETE';
        public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
    }
}

// ─── WP_Query stub ───

if (!class_exists('WP_Query')) {

    /**
     * Minimal WP_Query stub.
     */
    class WP_Query {

        /** @var WP_Post[] */
        public array $posts = [];

        /** @var int */
        public int $found_posts = 0;

        /**
         * @param array<string,mixed> $args Query arguments.
         */
        public function __construct(array $args = []) {}
    }
}

// ─── WordPress helper function stubs ───

if (!function_exists('wp_parse_args')) {
    /**
     * @param array<string,mixed>|string $args     Arguments.
     * @param array<string,mixed>        $defaults Defaults.
     *
     * @return array<string,mixed>
     */
    function wp_parse_args(array|string $args, array $defaults = []): array {
        if (is_string($args)) {
            parse_str($args, $args);
        }
        return array_merge($defaults, $args);
    }
}
