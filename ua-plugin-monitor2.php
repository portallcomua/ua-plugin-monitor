
<?php
/**
 * Plugin Name: UA Plugin Monitor
 * Description: Central monitoring of your WordPress plugins across multiple websites.
 * Version: 1.0.0
 * Author: UA Tools
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

final class UA_Plugin_Monitor {

    const VERSION = '1.0.0';
    const OPTION = 'uatm_settings';
    const REPORTS = 'uatm_reports';
    const API = 'uatm/v1';
    const CRON = 'uatm_daily_report';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('rest_api_init', [$this, 'routes']);
        add_action(self::CRON, [$this, 'send_report']);

        add_action('admin_post_uatm_test', [$this, 'test_report']);
        add_action('admin_post_uatm_new_key', [$this, 'new_key']);
        add_action('admin_post_uatm_clear', [$this, 'clear_reports']);
        add_action('admin_post_uatm_export', [$this, 'export_csv']);
    }

    public static function activate() {
        $s = get_option(self::OPTION, []);

        $s = wp_parse_args(is_array($s) ? $s : [], [
            'mode' => 'central',
            'api_key' => wp_generate_password(48, false, false),
            'endpoint' => '',
            'consent' => 0,
            'tracked' => '',
        ]);

        update_option(self::OPTION, $s, false);

        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 300, 'daily', self::CRON);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON);
    }

    private function settings() {
        return wp_parse_args(get_option(self::OPTION, []), [
            'mode' => 'central',
            'api_key' => '',
            'endpoint' => '',
            'consent' => 0,
            'tracked' => '',
        ]);
    }

    public function menu() {
        add_menu_page(
            'UA Plugin Monitor',
            'UA Plugin Monitor',
            'manage_options',
            'ua-plugin-monitor',
            [$this, 'page'],
            'dashicons-chart-area',
            59
        );
    }

    public function register_settings() {
        register_setting(
            'uatm_group',
            self::OPTION,
            [$this, 'sanitize']
        );
    }

    public function sanitize($input) {
        $old = $this->settings();

        $s = [
            'mode' => (
                isset($input['mode']) &&
                $input['mode'] === 'agent'
            ) ? 'agent' : 'central',

            'api_key' => $old['api_key'],
            'endpoint' => esc_url_raw($input['endpoint'] ?? ''),
            'consent' => !empty($input['consent']) ? 1 : 0,
            'tracked' => sanitize_textarea_field($input['tracked'] ?? ''),
        ];

        if (!empty($input['agent_key'])) {
            $s['api_key'] = sanitize_text_field(
                trim($input['agent_key'])
            );
        }

        return $s;
    }

    public function routes() {
        register_rest_route(self::API, '/report', [
            'methods' => 'POST',
            'callback' => [$this, 'receive'],
            'permission_callback' => '__return_true',
        ]);
    }

    private function valid_key($provided) {
        $expected = $this->settings()['api_key'];

        return (
            $expected !== '' &&
            $provided !== '' &&
            hash_equals($expected, $provided)
        );
    }

    public function receive(WP_REST_Request $request) {

        $s = $this->settings();

        if ($s['mode'] !== 'central') {
            return new WP_REST_Response([
                'error' => 'Not a central server'
            ], 403);
        }

        if (!$this->valid_key(
            (string) $request->get_header('x-uatm-key')
        )) {
            return new WP_REST_Response([
                'error' => 'Unauthorized'
            ], 401);
        }

        $data = $request->get_json_params();

        if (
            !is_array($data) ||
            empty($data['site_hash']) ||
            empty($data['site_url'])
        ) {
            return new WP_REST_Response([
                'error' => 'Invalid report'
            ], 400);
        }

        $plugins = [];

        if (!empty($data['plugins']) && is_array($data['plugins'])) {
            foreach (array_slice($data['plugins'], 0, 200) as $p) {
                if (!is_array($p)) continue;

                $plugins[] = [
                    'name' => sanitize_text_field($p['name'] ?? ''),
                    'slug' => sanitize_text_field($p['slug'] ?? ''),
                    'version' => sanitize_text_field($p['version'] ?? ''),
                    'active' => !empty($p['active']) ? 1 : 0,
                ];
            }
        }

        $reports = get_option(self::REPORTS, []);

        if (!is_array($reports)) $reports = [];

        $hash = sanitize_text_field($data['site_hash']);

        $reports[$hash] = [
            'site_url' => esc_url_raw($data['site_url']),
            'site_name' => sanitize_text_field($data['site_name'] ?? ''),
            'wp_version' => sanitize_text_field($data['wp_version'] ?? ''),
            'php_version' => sanitize_text_field($data['php_version'] ?? ''),
            'wc_version' => sanitize_text_field($data['wc_version'] ?? ''),
            'plugins' => $plugins,
            'reported_at' => time(),
        ];

        update_option(self::REPORTS, $reports, false);

        return new WP_REST_Response([
            'ok' => true,
            'received' => count($plugins)
        ], 200);
    }

    private function get_tracked_plugins() {

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $s = $this->settings();

        $wanted = array_filter(array_map(
            'trim',
            preg_split('/[\r\n,]+/', $s['tracked'])
        ));

        $all = get_plugins();
        $active = (array) get_option('active_plugins', []);

        $result = [];

        foreach ($all as $file => $data) {

            $slug = dirname($file);

            if ($slug === '.') {
                $slug = basename($file, '.php');
            }

            if (
                !in_array($slug, $wanted, true) &&
                !in_array($file, $wanted, true)
            ) {
                continue;
            }

            $result[] = [
                'name' => sanitize_text_field($data['Name'] ?? $slug),
                'slug' => sanitize_text_field($slug),
                'version' => sanitize_text_field($data['Version'] ?? ''),
                'active' => in_array($file, $active, true),
            ];
        }

        return $result;
    }

    private function build_report() {

        $plugins = $this->get_tracked_plugins();

        return [
            'site_url' => home_url('/'),
            'site_name' => get_bloginfo('name'),
            'site_hash' => hash(
                'sha256',
                untrailingslashit(home_url('/'))
            ),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'wc_version' => defined('WC_VERSION') ? WC_VERSION : '',
            'plugins' => $plugins,
        ];
    }

    public function send_report() {

        $s = $this->settings();

        if (
            $s['mode'] !== 'agent' ||
            empty($s['consent']) ||
            empty($s['endpoint']) ||
            empty($s['api_key'])
        ) {
            return;
        }

        $url = trailingslashit($s['endpoint']) .
            'wp-json/' . self::API . '/report';

        $response = wp_remote_post($url, [
            'timeout' => 20,
            'redirection' => 2,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-UATM-Key' => $s['api_key'],
            ],
            'body' => wp_json_encode($this->build_report()),
        ]);

        if (is_wp_error($response)) {
            update_option(
                'uatm_last_error',
                sanitize_text_field($response->get_error_message())
            );
            return;
        }

        $code = wp_remote_retrieve_response_code($response);

        update_option('uatm_last_sync', time());

        update_option(
            'uatm_last_error',
            ($code >= 200 && $code < 300) ? '' : 'HTTP ' . $code
        );
    }

    public function test_report() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        check_admin_referer('uatm_test');

        $this->send_report();

        wp_safe_redirect(admin_url(
            'admin.php?page=ua-plugin-monitor&tested=1'
        ));
        exit;
    }

    public function new_key() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        check_admin_referer('uatm_new_key');

        $s = $this->settings();

        $s['api_key'] = wp_generate_password(48, false, false);

        update_option(self::OPTION, $s, false);

        wp_safe_redirect(admin_url(
            'admin.php?page=ua-plugin-monitor&newkey=1'
        ));
        exit;
    }

    public function clear_reports() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        check_admin_referer('uatm_clear');

        delete_option(self::REPORTS);

        wp_safe_redirect(admin_url(
            'admin.php?page=ua-plugin-monitor&cleared=1'
        ));
        exit;
    }

    public function export_csv() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        check_admin_referer('uatm_export');

        $reports = get_option(self::REPORTS, []);

        nocache_headers();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=ua-plugin-monitor.csv');

        $out = fopen('php://output', 'w');

        // UTF-8 BOM for Excel
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, [
            'Site', 'URL', 'WordPress', 'PHP',
            'WooCommerce', 'Plugin', 'Version',
            'Active', 'Last report'
        ], ';');

        foreach ((array) $reports as $r) {

            $plugins = !empty($r['plugins']) ? $r['plugins'] : [[]];

            foreach ($plugins as $p) {
                fputcsv($out, [
                    $r['site_name'] ?? '',
                    $r['site_url'] ?? '',
                    $r['wp_version'] ?? '',
                    $r['php_version'] ?? '',
                    $r['wc_version'] ?? '',
                    $p['name'] ?? '',
                    $p['version'] ?? '',
                    !empty($p['active']) ? 'Active' : 'Inactive',
                    !empty($r['reported_at'])
                        ? wp_date('Y-m-d H:i:s', $r['reported_at'])
                        : ''
                ], ';');
            }
        }

        fclose($out);
        exit;
    }

    public function page() {

        if (!current_user_can('manage_options')) return;

        $s = $this->settings();

        $reports = get_option(self::REPORTS, []);

        if (!is_array($reports)) $reports = [];

        $last_sync = (int) get_option('uatm_last_sync', 0);
        $error = get_option('uatm_last_error', '');

        ?>
        <div class="wrap">

        <h1>UA Plugin Monitor 1.0.0</h1>

        <?php if (isset($_GET['tested'])): ?>
            <div class="notice notice-info"><p>Тестову спробу виконано. Перевірте статус синхронізації.</p></div>
        <?php endif; ?>

        <?php if (isset($_GET['newkey'])): ?>
            <div class="notice notice-success"><p>Новий ключ створено. Оновіть ключ на всіх агентських сайтах.</p></div>
        <?php endif; ?>

        <?php if (isset($_GET['cleared'])): ?>
            <div class="notice notice-success"><p>Звіти видалено.</p></div>
        <?php endif; ?>

        <form method="post" action="options.php">

            <?php settings_fields('uatm_group'); ?>

            <table class="form-table">

                <tr>
                    <th>Режим</th>
                    <td>
                        <select name="<?php echo esc_attr(self::OPTION); ?>[mode]">
                            <option value="central" <?php selected($s['mode'], 'central'); ?>>
                                Центральна панель
                            </option>
                            <option value="agent" <?php selected($s['mode'], 'agent'); ?>>
                                Агент на сайті клієнта
                            </option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th>URL центрального сайту</th>
                    <td>
                        <input class="regular-text"
                            type="url"
                            name="<?php echo esc_attr(self::OPTION); ?>[endpoint]"
                            value="<?php echo esc_attr($s['endpoint']); ?>"
                            placeholder="https://uaserver.pp.ua">
                    </td>
                </tr>

                <tr>
                    <th>Ключ центрального сервера</th>
                    <td>
                        <?php if ($s['mode'] === 'central'): ?>

                            <input class="large-text code"
                                readonly
                                value="<?php echo esc_attr($s['api_key']); ?>"
                                id="uatm-key">

                            <button type="button" class="button"
                                onclick="var e=document.getElementById('uatm-key');e.select();document.execCommand('copy');">
                                Скопіювати ключ
                            </button>

                            <p>
                                <a class="button"
                                    href="<?php echo esc_url(wp_nonce_url(
                                        admin_url('admin-post.php?action=uatm_new_key'),
                                        'uatm_new_key'
                                    )); ?>"
                                    onclick="return confirm('Старий ключ перестане працювати. Продовжити?')">
                                    Створити новий ключ
                                </a>
                            </p>

                        <?php else: ?>

                            <input class="regular-text"
                                type="password"
                                autocomplete="new-password"
                                name="<?php echo esc_attr(self::OPTION); ?>[agent_key]"
                                placeholder="Вставте ключ центрального сервера">

                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th>Slug твоїх плагінів</th>
                    <td>
                        <textarea class="large-text code"
                            rows="6"
                            name="<?php echo esc_attr(self::OPTION); ?>[tracked]"><?php echo esc_textarea($s['tracked']); ?></textarea>

                        <p class="description">
                            Один slug на рядок. Наприклад:
                            woo-quick-add-pro
                            cms-site-checker
                        </p>
                    </td>
                </tr>

                <?php if ($s['mode'] === 'agent'): ?>

                <tr>
                    <th>Згода адміністратора</th>
                    <td>
                        <label>
                            <input type="checkbox"
                                name="<?php echo esc_attr(self::OPTION); ?>[consent]"
                                value="1"
                                <?php checked($s['consent'], 1); ?>>
                            Дозволяю передавати технічну інформацію про сайт та зазначені плагіни на центральний сервер.
                        </label>
                    </td>
                </tr>

                <?php endif; ?>

            </table>

            <?php submit_button('Зберегти налаштування'); ?>

        </form>

        <?php if ($s['mode'] === 'central'): ?>

            <hr>

            <h2>Центральна панель</h2>

            <p><strong>API:</strong>
                <code><?php echo esc_html(rest_url(self::API . '/report')); ?></code>
            </p>

            <h2>Підключені сайти: <?php echo count($reports); ?></h2>

            <p>
                <a class="button"
                    href="<?php echo esc_url(wp_nonce_url(
                        admin_url('admin-post.php?action=uatm_export'),
                        'uatm_export'
                    )); ?>">
                    Експорт CSV
                </a>

                <a class="button"
                    href="<?php echo esc_url(wp_nonce_url(
                        admin_url('admin-post.php?action=uatm_clear'),
                        'uatm_clear'
                    )); ?>"
                    onclick="return confirm('Видалити всі звіти?')">
                    Видалити всі звіти
                </a>
            </p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Сайт</th>
                        <th>WordPress</th>
                        <th>PHP</th>
                        <th>WooCommerce</th>
                        <th>Встановлені плагіни</th>
                        <th>Останній звіт</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($reports)): ?>
                    <tr>
                        <td colspan="6">Поки немає підключених сайтів.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($reports as $r): ?>

                    <tr>
                        <td>
                            <a href="<?php echo esc_url($r['site_url']); ?>"
                                target="_blank" rel="noopener">
                                <?php echo esc_html($r['site_name']); ?>
                            </a>
                            <br>
                            <small><?php echo esc_html($r['site_url']); ?></small>
                        </td>

                        <td><?php echo esc_html($r['wp_version']); ?></td>
                        <td><?php echo esc_html($r['php_version']); ?></td>
                        <td><?php echo esc_html($r['wc_version'] ?: '—'); ?></td>

                        <td>
                            <?php foreach ((array) $r['plugins'] as $p): ?>
                                <div>
                                    <strong><?php echo esc_html($p['name']); ?></strong>
                                    <?php echo esc_html($p['version']); ?>
                                    —
                                    <?php echo !empty($p['active']) ? 'Активний' : 'Неактивний'; ?>
                                </div>
                            <?php endforeach; ?>
                        </td>

                        <td>
                            <?php echo esc_html(
                                wp_date('d.m.Y H:i', (int) $r['reported_at'])
                            ); ?>
                        </td>
                    </tr>

                <?php endforeach; ?>

                </tbody>
            </table>

        <?php else: ?>

            <hr>

            <h2>Статус агента</h2>

            <p>
                Остання синхронізація:
                <?php echo $last_sync
                    ? esc_html(wp_date('d.m.Y H:i', $last_sync))
                    : 'Ще не виконувалась'; ?>
            </p>

            <p>
                Статус:
                <?php echo $error
                    ? '<span style="color:red">' . esc_html($error) . '</span>'
                    : 'Помилок не зафіксовано'; ?>
            </p>

            <p>
                <a class="button button-primary"
                    href="<?php echo esc_url(wp_nonce_url(
                        admin_url('admin-post.php?action=uatm_test'),
                        'uatm_test'
                    )); ?>">
                    Надіслати тестовий звіт
                </a>
            </p>

        <?php endif; ?>

        <hr>

        <p>
            UA Tools Monitor <?php echo esc_html(self::VERSION); ?>.
            Передаються тільки зазначені плагіни та технічні версії середовища.
            Замовлення, паролі, користувачі та платіжні дані не збираються.
        </p>

        </div>
        <?php
    }
}

register_activation_hook(__FILE__, [
    'UA_Plugin_Monitor',
    'activate'
]);

register_deactivation_hook(__FILE__, [
    'UA_Plugin_Monitor',
    'deactivate'
]);

new UA_Plugin_Monitor();
