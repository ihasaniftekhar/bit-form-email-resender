<?php
/**
 * Plugin Name: Bit Form Email Resender
 * Description: Safely resend configured Bit Form email notifications for stored submissions. Email-only; no submission or integration replay.
 * Version: 0.4.2
 * Requires PHP: 8.0
 * Requires at least: 6.5
 * Author: Ihasan Iftekhar Labib
 * Author URI: https://github.com/ihasaniftekhar
 * License: GPL-2.0-or-later
 * Text Domain: bit-form-email-resender
 */

if (!defined('ABSPATH')) {
    exit;
}

final class BF_Email_Resender {
    const VERSION = '0.4.2';
    const HISTORY_OPTION = 'bf_email_resender_history_v1';
    const MAX_BATCH = 5;
    const PAGE_SLUG = 'bf-email-resender';
    private static $page_hook = ''; 

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 30);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('wp_ajax_bf_email_resender_send', [__CLASS__, 'ajax_send']);
        add_action('wp_ajax_bf_email_resender_preview', [__CLASS__, 'ajax_preview']);
        add_action('wp_ajax_bf_email_resender_test_mail', [__CLASS__, 'ajax_test_mail']);
        add_action('wp_ajax_bf_email_resender_pdf_self_test', [__CLASS__, 'ajax_pdf_self_test']);
        add_action('admin_notices', [__CLASS__, 'version_notice']);
    }

    private static function available(): bool {
        return defined('BITFORMS_VERSION')
            && version_compare(BITFORMS_VERSION, '3.4.0', '==')
            && class_exists('\\BitApps\\BitForm\\Services\\Notification\\MailNotifier')
            && class_exists('\\BitApps\\BitForm\\Services\\Admin\\AdminFormManager')
            && class_exists('\\BitApps\\BitForm\\Workflow\\WorkFlow');
    }

    private static function allowed(): bool {
        // Sending email as the site is a high-trust operation: only administrators by default.
        return current_user_can('manage_options');
    }

    public static function version_notice(): void {
        if (!self::allowed() || !isset($_GET['page']) || self::PAGE_SLUG !== $_GET['page']) {
            return;
        }
        if (!self::available()) {
            echo '<div class="notice notice-error"><p>Bit Form Email Resender requires active Bit Form Free 3.4.0. Other versions have not been verified; sending is disabled.</p></div>';
        }
    }

    public static function register_menu(): void {
        self::$page_hook = add_submenu_page(
            'bitform',
            'Resend Bit Form Emails',
            'Resend Emails',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render']
        );
    }

    public static function enqueue(string $hook): void {
        // The WordPress page hook can differ when Bit Form builds its submenu dynamically.
        // Match the actual registered hook and the page slug, rather than one hard-coded hook.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($hook !== self::$page_hook && $page !== self::PAGE_SLUG) {
            return;
        }
        $base = plugin_dir_url(__FILE__);
        wp_enqueue_style('bf-email-resender', $base . 'assets/admin.css', [], self::VERSION);
        wp_enqueue_script('bf-email-resender', $base . 'assets/admin.js', [], self::VERSION, true);
        wp_localize_script('bf-email-resender', 'bfEmailResender', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('bf_email_resender_send'),
            'maxBatch'=> self::MAX_BATCH,
            'previewMax' => 25,
        ]);
    }

    public static function render(): void {
        if (!self::allowed()) {
            wp_die('Insufficient permissions.');
        }
        global $wpdb;
        $forms_table = $wpdb->prefix . 'bitforms_form';
        $entries_table = $wpdb->prefix . 'bitforms_form_entries';
        $forms = $wpdb->get_results("SELECT id, form_name FROM `{$forms_table}` WHERE status <> 2 ORDER BY id DESC LIMIT 500");
        $form_id = isset($_GET['bf_form_id']) ? absint($_GET['bf_form_id']) : 0;
        $page = isset($_GET['bf_page']) ? max(1, absint($_GET['bf_page'])) : 1;
        $known = array_map(static function($f) { return (int) $f->id; }, (array) $forms);
        if (!in_array($form_id, $known, true)) {
            $form_id = $known[0] ?? 0;
        }
        $per_page = 25;
        $rows = [];
        $total = 0;
        $templates = [];
        $summaries = [];
        if ($form_id && self::available()) {
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$entries_table}` WHERE form_id = %d AND status <> 2", $form_id
            ));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, created_at, status FROM `{$entries_table}` WHERE form_id = %d AND status <> 2 ORDER BY id DESC LIMIT %d OFFSET %d",
                $form_id, $per_page, ($page - 1) * $per_page
            ));
            $templates = self::templates($form_id);
            $summaries = self::entry_summaries($form_id, array_map(static function($r) { return (int) $r->id; }, (array) $rows));
        }
        $base_url = admin_url('admin.php?page=' . self::PAGE_SLUG);
        $history = (array) get_option(self::HISTORY_OPTION, []);
        ?>
        <div class="wrap bfres-wrap">
          <h1>Bit Form · Resend Emails</h1>
          <p>Regenerate notifications using <strong>current enabled templates</strong> and the saved submission data. This does not resubmit the form or trigger other integrations.</p>
          <div class="notice notice-info inline"><p><strong>Important:</strong> Current notification settings and current workflow conditions are used. This is not a byte-for-byte replay of the original message. SMTP acceptance does not guarantee inbox delivery.</p></div>
          <section class="bfres-diagnostics" aria-label="Email transport diagnostic">
            <h2>Step 1: Test WordPress / Post SMTP delivery</h2>
            <p>Send one plain test message through <code>wp_mail()</code> to check that your mail transport works independently of Bit Form notifications. This sends an actual email and should appear in Post SMTP → Email Log.</p>
            <label for="bfres-test-address">Test recipient</label>
            <input type="email" id="bfres-test-address" placeholder="you@example.com" autocomplete="email" />
            <button type="button" id="bfres-test-mail" class="button">Send mail transport test</button>
            <p id="bfres-test-result" role="status" aria-live="polite"></p>
            <hr />
            <h3>PDF renderer diagnostics</h3>
            <p>Run a blank, temporary PDF test through Bit Form Pro / mPDF. It does not use or email submission data. The generated test file is removed immediately.</p>
            <button type="button" id="bfres-pdf-self-test" class="button">Test Bit Form Pro PDF generator</button>
            <p id="bfres-pdf-test-result" role="status" aria-live="polite"></p>
          </section>
          <p class="description">Step 2: Review a submission and click <strong>Confirm &amp; send emails</strong>. A successful recipient preview does not send any email.</p>
          <form method="get" class="bfres-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
            <label for="bfres-form">Form</label>
            <select name="bf_form_id" id="bfres-form">
            <?php foreach ((array) $forms as $f): ?>
              <option value="<?php echo esc_attr($f->id); ?>" <?php selected($form_id, (int) $f->id); ?>><?php echo esc_html($f->form_name . ' (#' . $f->id . ')'); ?></option>
            <?php endforeach; ?>
            </select>
            <button class="button">View submissions</button>
          </form>
          <?php if (self::available() && $form_id): ?>
          <div class="bfres-actions">
            <label for="bfres-template">Notification</label>
            <select id="bfres-template"><option value="0">All eligible email notifications (recommended)</option>
            <?php foreach ($templates as $t): ?>
               <option value="<?php echo esc_attr((int) $t->id); ?>"><?php echo esc_html(($t->title ?: 'Email notification') . ' (#' . (int) $t->id . ')'); ?></option>
            <?php endforeach; ?>
            </select>
            <button type="button" id="bfres-bulk" class="button button-primary">Review &amp; resend selected</button>
            <span id="bfres-progress" aria-live="polite"></span>
          </div>
          <?php if (!$templates): ?>
          <div class="notice notice-warning inline"><p>No enabled email notification templates were found for this form. Configure an enabled notification in Bit Form first.</p></div>
          <?php endif; ?>
          <section class="bfres-preview" id="bfres-preview" hidden aria-label="Review resend recipients">
            <h2>Review recipients and PDF attachments</h2>
            <div id="bfres-preview-summary" aria-live="polite"></div>
            <p class="description">PDF templates can be selected for each email notification below. If a PDF is missing from the default selections, check its box before sending. Original recipients are calculated from the currently configured Bit Form notification(s) and the saved entry values. They may differ from the actual historical recipients if the form settings have since changed.</p>
            <div class="bfres-mode"><label><input type="radio" name="bfres-recipient-mode" value="original" checked> Use original recipients (default)</label>
            <label><input type="radio" name="bfres-recipient-mode" value="custom"> Replace with different recipients</label></div>
            <div class="bfres-custom" id="bfres-custom" hidden>
              <p><label for="bfres-to">To <span class="required">*</span></label><textarea id="bfres-to" rows="2" placeholder="name@example.com, another@example.com"></textarea></p>
              <p><label for="bfres-cc">CC (optional)</label><input type="text" id="bfres-cc" placeholder="cc@example.com"></p>
              <p><label for="bfres-bcc">BCC (optional)</label><input type="text" id="bfres-bcc" placeholder="bcc@example.com"></p>
              <p class="description"><strong>Privacy:</strong> In custom mode, only the addresses written here receive these emails. Original To/CC/BCC and conditional routing are replaced. Separate addresses using commas or new lines.</p>
            </div>
            <div class="bfres-preview-actions"><button type="button" id="bfres-confirm" class="button button-primary">Confirm &amp; send emails</button><button type="button" id="bfres-cancel" class="button">Cancel</button></div>
          </section>
          <table class="wp-list-table widefat fixed striped" id="bfres-entries" data-form-id="<?php echo esc_attr($form_id); ?>">
            <thead><tr><td class="check-column"><input type="checkbox" id="bfres-all" aria-label="Select all visible submissions"></td><th>Entry ID</th><th>Contact</th><th>Submitted</th><th>Notification status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ((array) $rows as $row): ?>
            <tr>
              <th class="check-column"><input type="checkbox" class="bfres-check" value="<?php echo esc_attr($row->id); ?>" aria-label="Select entry <?php echo esc_attr($row->id); ?>"></th>
              <td><strong>#<?php echo esc_html($row->id); ?></strong></td>
              <td><?php echo esc_html(implode(' · ', $summaries[(int) $row->id] ?? [])); ?></td>
              <td><?php echo esc_html($row->created_at); ?></td>
              <td><span class="bfres-result" data-id="<?php echo esc_attr($row->id); ?>">Not resent here</span></td>
              <td><button type="button" class="button bfres-one" data-id="<?php echo esc_attr($row->id); ?>">Review &amp; resend</button></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6">No eligible submissions were found.</td></tr><?php endif; ?>
            </tbody>
          </table>
          <?php if ($total > $per_page): ?>
          <div class="tablenav"><div class="tablenav-pages">
            <?php echo wp_kses_post(paginate_links([
                'base' => add_query_arg(['bf_form_id' => $form_id, 'bf_page' => '%#%'], $base_url),
                'format' => '', 'current' => $page, 'total' => (int) ceil($total / $per_page),
            ])); ?>
          </div></div>
          <?php endif; ?>
          <?php else: ?><p><?php echo $form_id ? 'Bit Form Free 3.4.0 is not active or compatible.' : 'No forms found. Create a form in Bit Form first.'; ?></p><?php endif; ?>
          <h2>Recent resend activity</h2>
          <table class="widefat striped"><thead><tr><th>Time</th><th>Form / entry</th><th>Emails accepted / attempted</th><th>Result</th><th>By</th></tr></thead><tbody>
          <?php foreach (array_slice(array_reverse($history), 0, 30) as $h): ?>
             <tr><td><?php echo esc_html($h['time'] ?? ''); ?></td><td><?php echo esc_html(($h['form'] ?? '') . ' / #' . ($h['entry'] ?? '')); ?></td><td><?php echo esc_html(($h['accepted'] ?? 0) . ' / ' . ($h['attempted'] ?? 0)); ?></td><td><?php echo esc_html($h['status'] ?? ''); ?></td><td><?php echo esc_html($h['user'] ?? ''); ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$history): ?><tr><td colspan="5">No resend attempts yet.</td></tr><?php endif; ?>
          </tbody></table>
        </div>
        <?php
    }

    /** Show only form fields labeled as contact name/email. Never expose arbitrary entry data in the listing. */
    private static function entry_summaries(int $form_id, array $entry_ids): array {
        if (!$entry_ids) { return []; }
        $manager = new \BitApps\BitForm\Services\Admin\AdminFormManager($form_id);
        if (!$manager->isExist()) { return []; }
        $content = $manager->getFormContent();
        $field_keys = [];
        foreach ((array) ($content->fields ?? []) as $key => $fld) {
            $type = strtolower((string) ($fld->typ ?? ''));
            $label = strtolower(wp_strip_all_tags((string) ($fld->lbl ?? '')));
            if ($type === 'email' || (in_array($type, ['text', 'name'], true) && preg_match('/\b(name|e-?mail)\b/i', $label))) {
                $field_keys[] = (string) $key;
            }
        }
        $field_keys = array_slice($field_keys, 0, 4);
        if (!$field_keys) { return []; }
        global $wpdb;
        $entries_placeholders = implode(',', array_fill(0, count($entry_ids), '%d'));
        $fields_placeholders = implode(',', array_fill(0, count($field_keys), '%s'));
        $sql = "SELECT bitforms_form_entry_id, meta_key, meta_value FROM `{$wpdb->prefix}bitforms_form_entrymeta` "
            . "WHERE bitforms_form_entry_id IN ({$entries_placeholders}) AND meta_key IN ({$fields_placeholders})";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($entry_ids, $field_keys)));
        $summaries = [];
        foreach ((array) $rows as $row) {
            $id = (int) $row->bitforms_form_entry_id;
            $val = trim((string) $row->meta_value);
            if ($val === '' || strlen($val) > 200 || str_starts_with($val, '{') || str_starts_with($val, '[')) {
                continue;
            }
            $summaries[$id][] = wp_html_excerpt($val, 85, '');
            $summaries[$id] = array_slice($summaries[$id], 0, 2);
        }
        return $summaries;
    }

    private static function templates(int $form_id): array {
        $handler = new \BitApps\BitForm\Services\Messages\EmailTemplateHandler($form_id);
        $result = $handler->getAllTemplate();
        if (is_wp_error($result) || !is_array($result)) {
            return [];
        }
        return array_values(array_filter($result, static function($t) { return (int) ($t->status ?? 1) === 1; }));
    }

    private static function template_details(object $template): object {
        $config = json_decode((string) ($template->config ?? ''));
        if (!is_object($config)) {
            $config = (object) [];
        }
        $details = (object) ['id' => wp_json_encode(['id' => (string) $template->id])];
        foreach (['to', 'from', 'from_name', 'cc', 'bcc', 'replyto', 'attachment', 'mediaAttachment', 'pdfId', 'pdfIds'] as $key) {
            $details->$key = $config->$key ?? (in_array($key, ['to','cc','bcc','replyto','attachment','mediaAttachment','pdfIds'], true) ? [] : '');
        }
        return $details;
    }

    /** Workflow actions can override attachment / PDF selections from the template config.
     *  Use the actual saved action details, just as Bit Form does during submission.
     *  Keep explicit empty values (they mean disabled), and fill only ABSENT keys.
     */
    private static function details_from_action(object $template, $action_details): object {
        $details = self::template_details($template);
        if (is_object($action_details)) {
            foreach (['to', 'from', 'from_name', 'cc', 'bcc', 'replyto', 'attachment', 'mediaAttachment', 'pdfId', 'pdfIds'] as $key) {
                if (property_exists($action_details, $key)) {
                    $details->$key = $action_details->$key;
                }
            }
        }
        // Saved workflow actions can carry a stale, empty pdfIds/pdfId while the
        // current email template has an active PDF selection. Use the current
        // template PDF selection when the action contains no actual PDF ids.
        // The review screen allows explicit per-email selection/disablement.
        if (!self::pdf_ids_from_details($details)) {
            $from_template = self::template_details($template);
            if (self::pdf_ids_from_details($from_template)) {
                $details->pdfIds = $from_template->pdfIds;
                $details->pdfId = $from_template->pdfId;
            }
        }
        // MailNotifier expects a JSON string id even if a workflow carries an object.
        $details->id = wp_json_encode(['id' => (string) $template->id]);
        return $details;
    }

    /** Normalize Pro PDF template references from current template or workflow.
     * Supports multi-PDF JSON id strings, legacy single selection, and ids stored
     * as numbers/objects. The resender always passes Pro's expected JSON strings.
     */
    private static function pdf_ids_from_details(object $details): array {
        $raw = !empty($details->pdfIds) && is_array($details->pdfIds)
            ? $details->pdfIds : (!empty($details->pdfId) ? [$details->pdfId] : []);
        $ids = [];
        foreach ($raw as $entry) {
            if (is_object($entry)) {
                $id = absint($entry->id ?? 0);
            } elseif (is_array($entry)) {
                $id = absint($entry['id'] ?? 0);
            } elseif (is_numeric($entry)) {
                $id = absint($entry);
            } elseif (is_string($entry)) {
                $decoded = json_decode($entry);
                $id = is_object($decoded) ? absint($decoded->id ?? 0) : 0;
            } else {
                $id = 0;
            }
            if ($id) { $ids[$id] = $id; }
        }
        return array_values($ids);
    }

    /** Only published/enabled PDF templates can be explicitly selected. */
    private static function pdf_catalog(int $form_id): array {
        if (!defined('BITFORMPRO_VERSION') || !class_exists('\\BitApps\\BitFormPro\\Services\\Messages\\PdfTemplateHandler')) {
            return [];
        }
        try {
            $handler = new \BitApps\BitFormPro\Services\Messages\PdfTemplateHandler($form_id);
            $result = $handler->getAll();
            if (!is_array($result) || is_wp_error($result)) { return []; }
            $catalog = [];
            foreach ($result as $row) {
                $id = absint($row->id ?? 0);
                if (!$id || !\BitApps\BitFormPro\Services\Messages\PdfTemplateHandler::isEnabled($row->pdf_config ?? null)) { continue; }
                $catalog[$id] = ['id' => $id, 'title' => (string) ($row->title ?? ('PDF #' . $id))];
            }
            return array_values($catalog);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function apply_pdf_choice(object $details, array $ids): void {
        // No selection means explicit choice to attach no generated PDF.
        $details->pdfIds = array_map(static function($id) {
            return wp_json_encode(['id' => (string) $id]);
        }, array_values(array_unique(array_map('absint', $ids))));
        // Pro always prefers pdfIds, but legacy pdfId could otherwise silently
        // re-enable a disabled PDF when pdfIds is an empty array.
        $details->pdfId = '';
    }

    /** Resolve currently eligible notification IDs without triggering submission side effects. */
    private static function resolve_notifications(int $form_id, int $entry_id, array $values, object $fields): array {
        $notifications = [];
        $templates = self::templates($form_id);
        $template_map = [];
        foreach ($templates as $tpl) {
            $template_map[(int) $tpl->id] = $tpl;
        }
        $defaults = \BitApps\BitForm\Workflow\Helper::getDefaultMailNotifications($form_id, 'create');
        foreach ($defaults as $notify) {
            $id = self::notification_id($notify->id ?? null);
            if (isset($template_map[$id])) {
                $notifications[$id] = self::details_from_action($template_map[$id], $notify);
            }
        }
        $workflow = new \BitApps\BitForm\Workflow\WorkFlow($form_id);
        $categories = ['classic', 'basic'];
        $rows = $workflow->getWorkFlow(['create_edit','create'], 'onsubmit', null, 'workflow_order', 1, $categories);
        self::apply_builder_rows((array) $rows, $notifications, $values, $fields, $form_id, $template_map);

        // Pro inline advanced email conditions, and advanced workflow builder rows.
        if (class_exists('\\BitApps\\BitFormPro\\Workflow\\AdvancedConditionalLogic') || class_exists('\\BitCode\\BitFormPro\\Core\\WorkFlow\\AdvancedConditionalLogic')) {
            $advanced = $workflow->getWorkFlow(['create_edit','create'], 'onsubmit', null, 'workflow_order', 1, ['advanced']);
            $builders = [];
            $features = [];
            foreach ((array) $advanced as $row) {
                $info = json_decode((string) ($row->workflow_info ?? ''));
                if (is_object($info) && !empty($info->action)) {
                    $features[] = [$row, $info];
                } else {
                    $builders[] = $row;
                }
            }
            self::apply_builder_rows($builders, $notifications, $values, $fields, $form_id, $template_map);
            foreach ($features as $pair) {
                [$row, $info] = $pair;
                if (($info->action ?? '') !== 'email') {
                    continue;
                }
                $id = absint($info->targetId ?? 0);
                if (!$id) {
                    continue;
                }
                $conditions = json_decode((string) ($row->workflow_condition ?? ''));
                $condition = is_array($conditions) ? ($conditions[0] ?? null) : null;
                if (!$condition) {
                    continue;
                }
                $logics = $condition->logics ?? [];
                $matched = self::condition_match($logics, $fields, $form_id, $condition, true);
                $active = ($info->effect ?? 'show') === 'show' ? $matched : !$matched;
                if ($active && isset($template_map[$id])) {
                    $notifications[$id] = self::template_details($template_map[$id]);
                } else {
                    unset($notifications[$id]);
                }
            }
        }
        $resolved = [];
        foreach (array_keys($notifications) as $id) {
            if (isset($template_map[$id])) {
                $resolved[] = ['template' => $template_map[$id], 'details' => $notifications[$id]];
            }
        }
        return ['notifications' => $resolved, 'values' => $values];
    }

    private static function notification_id($value): int {
        if (is_array($value)) {
            foreach ($value as $part) {
                $id = self::notification_id($part);
                if ($id) { return $id; }
            }
        }
        if (is_object($value) && isset($value->id)) {
            return absint($value->id);
        }
        if (is_string($value)) {
            $obj = json_decode($value);
            if (is_object($obj) && isset($obj->id)) {
                return absint($obj->id);
            }
        }
        return 0;
    }

    private static function condition_match($logics, object $fields, int $form_id, $condition, bool $empty_means_true = false): bool {
        if (empty($logics)) {
            $match = $empty_means_true;
            $field_data = \BitApps\BitForm\Workflow\Helper::getFieldData($fields);
        } else {
            $field_data = \BitApps\BitForm\Workflow\Helper::getFieldData($fields);
            $field_data = \BitApps\BitForm\Workflow\Helper::smartFldMargeFormFld($logics, $field_data);
            $match = (bool) (new \BitApps\BitForm\Workflow\ConditionalLogic($logics, $field_data))->getConditionStatus();
        }
        return (bool) apply_filters('bitform_filter_workflow_condition_status', $match, $condition, $field_data, $form_id);
    }

    private static function apply_builder_rows(array $rows, array &$notifications, array &$values, object $fields, int $form_id, array $template_map): void {
        $actions_obj = new \BitApps\BitForm\Workflow\Actions($form_id);
        foreach ($rows as $row) {
            $conditions = json_decode((string) ($row->workflow_condition ?? ''));
            if (!is_array($conditions) || !$conditions) {
                continue;
            }
            foreach ($conditions as $condition) {
                if (empty($condition->actions) || !is_object($condition->actions)) {
                    continue;
                }
                $behaviour = $row->workflow_behaviour ?? '';
                $type = $condition->cond_type ?? '';
                if ('always' === $behaviour) {
                    $matched = true;
                } elseif ('cond' === $behaviour) {
                    $matched = ('else' === $type) || (in_array($type, ['if','else-if'], true) && self::condition_match($condition->logics ?? [], $fields, $form_id, $condition));
                } else {
                    $matched = false;
                }
                if (!$matched) {
                    continue;
                }
                $actions = $condition->actions;
                // Same in-memory submit-field transformations as Bit Form, but NO success, webhook or integration execution.
                if (!empty($actions->fields)) {
                    $values = $actions_obj->setOnSubmitSetFieldValue($actions->fields, $values);
                    foreach ($values as $key => $val) {
                        if (isset($fields->$key)) { $fields->$key->val = $val; }
                    }
                }
                foreach ((array) ($actions->success ?? []) as $success) {
                    if (($success->type ?? '') !== 'mailNotify') {
                        continue;
                    }
                    $id = self::notification_id($success->details->id ?? null);
                    if ($id && isset($template_map[$id])) {
                        $notifications[$id] = self::details_from_action($template_map[$id], $success->details);
                    }
                }
                // Bit Form executes only the first matching branch of this workflow.
                break;
            }
        }
    }

    /** Validate a literal list of replacement email addresses, never smart tags. */
    private static function parse_recipient_input($raw): array {
        if (!is_string($raw) || strlen($raw) > 3000) {
            throw new \InvalidArgumentException('Recipient addresses must be plain text (up to 3000 characters).');
        }
        if (trim($raw) === '') { return []; }
        $items = preg_split('/[,;\r\n]+/', $raw);
        if (count($items) > 30) {
            throw new \InvalidArgumentException('Too many addresses (maximum 30 per field).');
        }
        $found = [];
        foreach ($items as $item) {
            $address = trim($item);
            if (!$address) { continue; }
            if (!is_email($address) || preg_match('/[<>\x00-\x1F]/', $address)) {
                throw new \InvalidArgumentException('Invalid email address: ' . wp_html_excerpt($address, 90, ''));
            }
            $found[strtolower($address)] = sanitize_email($address);
        }
        return array_values($found);
    }

    /** A shared, read-only entry loader for preview and sending. */
    private static function entry_context(int $form_id, int $entry_id): array {
        global $wpdb;
        $entry = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM `{$wpdb->prefix}bitforms_form_entries` WHERE id = %d AND form_id = %d", $entry_id, $form_id
        ));
        if (!$entry || (int) $entry->status === 2) {
            throw new \RuntimeException('Submission not found or trashed.');
        }
        $meta = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM `{$wpdb->prefix}bitforms_form_entrymeta` WHERE bitforms_form_entry_id = %d", $entry_id
        ));
        if ($wpdb->last_error) {
            throw new \RuntimeException('Cannot read submission metadata; check Bit Form database tables.');
        }
        $values = [];
        foreach ((array) $meta as $m) { $values[$m->meta_key] = $m->meta_value; }
        $manager = new \BitApps\BitForm\Services\Admin\AdminFormManager($form_id);
        if (!$manager->isExist()) { throw new \RuntimeException('Form configuration unavailable.'); }
        $content = $manager->getFormContent();
        if (!isset($content->fields) || !is_object($content->fields)) {
            throw new \RuntimeException('Cannot load form fields.');
        }
        $fields = $content->fields;
        $array_types = ['file-up', 'advanced-file-up', 'signature', 'repeater', 'check', 'image-select', 'select', 'name', 'address'];
        foreach ($fields as $key => $fld) {
            if (array_key_exists($key, $values)) {
                // Bit Form saves array and object values as JSON in entrymeta.
                // A new submission uses the PHP array; a resend must reconstruct it
                // for the native file attachment, signature, and repeater processors.
                if (in_array((string) ($fld->typ ?? ''), $array_types, true) && is_string($values[$key])) {
                    $encoded = trim($values[$key]);
                    if (str_starts_with($encoded, '[') || str_starts_with($encoded, '{')) {
                        $decoded = json_decode($encoded, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $values[$key] = $decoded;
                        }
                    }
                }
                $fld->val = $values[$key];
                $fld->name = $key;
            }
        }
        $resolved = self::resolve_notifications($form_id, $entry_id, $values, $fields);
        $formatted = \BitApps\BitForm\Helpers\FieldValueHandler::formatFieldValueForMail($fields, $resolved['values']);
        return ['notifications' => $resolved['notifications'], 'formatted' => $formatted, 'values' => $values, 'fields' => $fields];
    }

    /**
     * Inspect the CURRENT attachment requirements before sending. No PDF is generated
     * in preview. Only the native Bit Form mailer may generate PDFs for actual sends.
     * Never return physical server paths or private signature filenames to the browser.
     */
    private static function attachment_check(array $item, int $form_id, int $entry_id, array $values, object $fields): array {
        $details = $item['details'];
        $report = [
            'pdf_templates' => 0, 'signature_fields' => 0, 'signatures_available' => 0,
            'uploads_required' => 0, 'uploads_available' => 0,
            'media_required' => 0, 'media_available' => 0,
            'warnings' => [], 'blocking' => [],
        ];
        $pdf_ids = self::pdf_ids_from_details($details);
        $report['pdf_ids'] = $pdf_ids;
        $report['pdf_templates'] = count($pdf_ids);
        if ($pdf_ids) {
            $dir_error = self::pdf_output_directory_status();
            if ($dir_error !== '') { $report['blocking'][] = $dir_error; }
            if (!defined('BITFORMPRO_VERSION') || !has_filter('bitform_generate_pdf_attachment')
                || !class_exists('\BitApps\BitFormPro\Services\Messages\PdfTemplateHandler')) {
                $report['blocking'][] = 'PDF attachments require active Bit Form Pro and its PDF generation hook.';
            } else {
                $handler = new \BitApps\BitFormPro\Services\Messages\PdfTemplateHandler($form_id);
                foreach ($pdf_ids as $id) {
                    try {
                        $rows = $handler->getById($id);
                        $row = is_array($rows) && !empty($rows) ? reset($rows) : null;
                        if (is_wp_error($rows) || !$row) {
                            $report['blocking'][] = 'PDF template #' . $id . ' cannot be loaded.';
                        } elseif (!\BitApps\BitFormPro\Services\Messages\PdfTemplateHandler::isEnabled($row->pdf_config ?? null)) {
                            $report['blocking'][] = 'PDF template #' . $id . ' is disabled.';
                        }
                    } catch (\Throwable $e) {
                        $report['blocking'][] = 'PDF template #' . $id . ' cannot be verified: ' . $e->getMessage();
                    }
                }
            }
        }
        $base = \BitApps\BitForm\Helpers\FileHandler::getEntriesFileUploadDir($form_id, $entry_id);
        $real_base = realpath($base);
        // WordPress media library attachments selected in the current template.
        foreach ((array) ($details->mediaAttachment ?? []) as $attachment_id) {
            $report['media_required']++;
            $file = get_attached_file(absint($attachment_id));
            if ($file && is_readable($file)) { $report['media_available']++; }
            else { $report['blocking'][] = 'A selected WordPress media attachment is missing.'; }
        }
        // Selected uploaded/signature fields, including JSON-encoded multiple uploads.
        foreach ((array) ($details->attachment ?? []) as $key) {
            if (!is_string($key) || !array_key_exists($key, $values)) { continue; }
            $files = self::file_names($values[$key]);
            foreach ($files as $name) {
                $report['uploads_required']++;
                if (self::saved_upload_exists($real_base, $name)) { $report['uploads_available']++; }
                else { $report['blocking'][] = 'A file selected for attachment in field ' . $key . ' is missing.'; }
            }
        }
        // A form may contain signatures even when not set as separate mail attachments.
        // Report them for email/PDF troubleshooting, without auto-attaching them to
        // templates that never selected the signature field.
        foreach ($fields as $key => $field) {
            if ('signature' !== (string) ($field->typ ?? '')) { continue; }
            foreach (self::file_names($values[$key] ?? null) as $name) {
                if ('signature-failed.png' === $name) { continue; }
                $report['signature_fields']++;
                if (self::saved_upload_exists($real_base, $name)) {
                    $report['signatures_available']++;
                } else {
                    $report['warnings'][] = 'A signature file saved with the entry is no longer readable.';
                }
            }
        }
        $report['blocking'] = array_values(array_unique($report['blocking']));
        $report['warnings'] = array_values(array_unique($report['warnings']));
        return $report;
    }

    /**
     * Expose Bit Form Pro's native, per-PDF diagnostic records for this RESEND log.
     * Native Pro records messages in bitforms_form_log_details, which avoids guessing
     * at whether a template was disabled, missing, or failed in the mPDF renderer.
     * Never return the PDF HTML, submitted field values, local file paths or PHP traces.
     */
    /** Return a bounded, admin-only error description without submitting PDF HTML or credentials. */
    private static function safe_pdf_error(string $raw): string {
        $raw = wp_strip_all_tags($raw);
        // Image/font errors may contain signed URLs, absolute paths and submission addresses.
        $raw = preg_replace('~https?://[^\s\"\'<>]+~i', '[URL]', $raw);
        $raw = preg_replace('~(?:[a-zA-Z]:[\\\\/]|/(?:home|var|srv|mnt|tmp|wp-content|opt|usr)/)[^\s\"\'<>]+~i', '[path]', $raw);
        $raw = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $raw);
        $raw = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $raw);
        return substr(trim((string) $raw), 0, 350);
    }

    /**
     * A minimal real mPDF health check, run only on explicit administrator request.
     * Uses Pro's installed PDF renderer and the same Bit Form output directory.
     * No user records are read and temporary output is deleted.
     */
    public static function ajax_pdf_self_test(): void {
        if (!self::allowed()) { wp_send_json_error(['message' => 'Insufficient permissions.'], 403); }
        check_ajax_referer('bf_email_resender_send', 'nonce');
        $missing = [];
        foreach (['mbstring', 'gd'] as $ext) {
            if (!extension_loaded($ext)) { $missing[] = $ext; }
        }
        $dependency_warning = $missing ? ' Missing PHP extension(s): ' . implode(', ', $missing) . '.' : '';
        if (!class_exists('\\BitApps\\BitFormPro\\Services\\Admin\\AppSetting\\Pdf')) {
            wp_send_json_success(['message' => 'Bit Form Pro PDF generator is not available.' . $dependency_warning, 'passed' => false]);
        }
        $dir_error = self::pdf_output_directory_status();
        if ($dir_error) {
            wp_send_json_success(['message' => $dir_error . $dependency_warning, 'passed' => false]);
        }
        $path = rtrim(BITFORMS_CONTENT_DIR, '/\\') . DIRECTORY_SEPARATOR . 'pdf';
        $settings = (object) ['pdfFileName' => 'bfres-check-' . wp_generate_password(12, false, false)];
        $output = null;
        $message = '';
        $passed = false;
        try {
            $output = \BitApps\BitFormPro\Services\Admin\AppSetting\Pdf::getInstance()->generator(
                $settings, '<html><body><p>Bit Form PDF renderer health check</p></body></html>', $path, null, 'F'
            );
            if (is_wp_error($output)) {
                $message = 'PDF generator failed: ' . self::safe_pdf_error($output->get_error_message());
            } elseif (!is_string($output) || !is_file($output) || !is_readable($output) || filesize($output) < 100) {
                $message = 'PDF generator returned no readable output file.';
            } else {
                $message = 'PASS: Bit Form Pro generated a minimal PDF. If submission PDFs still fail, check the template-specific diagnostic displayed with the resend result.';
                $passed = true;
            }
        } catch (\Throwable $e) {
            $message = 'PHP exception during minimal PDF render: ' . self::safe_pdf_error($e->getMessage());
        } finally {
            if (is_string($output) && is_file($output) && basename($output) === $settings->pdfFileName . '.pdf') {
                @unlink($output);
            }
        }
        wp_send_json_success(['message' => $message . $dependency_warning, 'passed' => $passed]);
    }

    /**
     * When native Pro reports a generic PDF error, reproduce it once using the body
     * and settings it passed to mPDF. We only expose the underlying exception summary.
     * Diagnostic PDFs, if any, are deleted; this does not send a PDF-less email.
     */
    private static function pdf_template_render_diagnostics(array $traces): array {
        if (!defined('BITFORMS_CONTENT_DIR') || !class_exists('\\BitApps\\BitFormPro\\Services\\Admin\\AppSetting\\Pdf')) { return []; }
        $dir = rtrim(BITFORMS_CONTENT_DIR, '/\\') . DIRECTORY_SEPARATOR . 'pdf';
        if (!is_dir($dir) || !is_writable($dir)) { return ['PDF output directory is not writable.']; }
        $notes = [];
        foreach (array_slice($traces, 0, 2) as $trace) {
            if (empty($trace['setting']) || !is_object($trace['setting']) || !is_string($trace['body'])) { continue; }
            $setting = clone $trace['setting'];
            $setting->pdfFileName = 'bfres-debug-' . wp_generate_password(12, false, false);
            $result = null;
            try {
                $result = \BitApps\BitFormPro\Services\Admin\AppSetting\Pdf::getInstance()->generator(
                    $setting, $trace['body'], $dir, null, 'F'
                );
                if (is_wp_error($result)) {
                    $notes[] = 'mPDF: ' . self::safe_pdf_error($result->get_error_message());
                } elseif (!is_string($result) || !is_file($result)) {
                    $notes[] = 'mPDF returned no output file during diagnostic reproduction.';
                } else {
                    $notes[] = 'Diagnostic render succeeded on retry; check file name/output path, transient resources and any filter affecting PDF output.';
                }
            } catch (\Throwable $e) {
                $notes[] = 'PHP error: ' . self::safe_pdf_error($e->getMessage());
            } finally {
                if (is_string($result) && is_file($result) && basename($result) === $setting->pdfFileName . '.pdf') {
                    @unlink($result);
                }
            }
        }
        return array_values(array_unique($notes));
    }

    private static function pdf_native_diagnostics(int $log_id): array {
        if (!$log_id) { return []; }
        global $wpdb;
        $table = $wpdb->prefix . 'bitforms_form_log_details';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT api_type, response_type, response_obj FROM `{$table}` WHERE log_id = %d ORDER BY id DESC LIMIT 30",
            $log_id
        ));
        if (!is_array($rows)) { return []; }
        $notes = [];
        foreach ($rows as $row) {
            $type = json_decode((string) ($row->api_type ?? ''), true);
            if (!is_array($type) || ($type['type_name'] ?? null) !== 'pdf') { continue; }
            $raw = json_decode((string) ($row->response_obj ?? ''), true);
            if (is_string($raw)) { $message = $raw; }
            elseif (is_array($raw)) { $message = (string) ($raw['description'] ?? $raw['message'] ?? 'PDF event logged'); }
            else { $message = 'PDF event logged'; }
            $message = trim(wp_strip_all_tags($message));
            if ($message !== '') { $notes[] = sanitize_text_field((string) ($row->response_type ?? 'unknown')) . ': ' . substr($message, 0, 160); }
        }
        return array_values(array_unique($notes));
    }

    /** Check the single native Pro PDF output directory; do not expose its path. */
    private static function pdf_output_directory_status(): string {
        if (!defined('BITFORMS_CONTENT_DIR')) { return 'Bit Form PDF content directory is undefined.'; }
        $root = BITFORMS_CONTENT_DIR;
        if (!is_dir($root)) { return 'Bit Form content directory is missing.'; }
        $pdf = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'pdf';
        if (!is_dir($pdf) && !wp_mkdir_p($pdf)) { return 'Cannot create Bit Form PDF output directory (filesystem permissions).'; }
        if (!is_dir($pdf) || !is_writable($pdf)) { return 'Bit Form PDF output directory is not writable (filesystem permissions).'; }
        return '';
    }

    private static function file_names($value): array {
        if (is_string($value)) {
            $trimmed = trim($value);
            if (str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) { $value = $decoded; }
            }
        }
        $result = [];
        $visit = static function($item) use (&$visit, &$result) {
            if (is_array($item)) {
                foreach ($item as $part) { $visit($part); }
            } elseif (is_string($item) && $item !== '') {
                $result[] = trim($item);
            }
        };
        $visit($value);
        return array_values(array_unique(array_filter($result)));
    }

    private static function saved_upload_exists($real_base, string $name): bool {
        if (!$real_base || !$name || str_contains($name, '\0')) { return false; }
        $file = realpath($real_base . DIRECTORY_SEPARATOR . $name);
        return $file !== false
            && str_starts_with($file, $real_base . DIRECTORY_SEPARATOR)
            && is_file($file) && is_readable($file);
    }

    /** Fully resolve notification routing as Bit Form would, for the current saved entry. */
    private static function preview_notification_recipients(array $item, array $formatted, int $form_id): array {
        $details = $item['details'];
        $handler = \BitApps\BitForm\Helpers\FieldValueHandler::class;
        $to = $handler::validateMailArry($details->to, $formatted);
        $to = apply_filters('bitform_email_conditional_routing', $to, $item['template'], $formatted, $form_id);
        $get = static function($value) use ($handler, $formatted) {
            $addresses = [];
            foreach ((array) $handler::validateMailArry($value, $formatted) as $addr) {
                if (is_string($addr) && is_email($addr)) { $addresses[strtolower($addr)] = sanitize_email($addr); }
            }
            return array_values($addresses);
        };
        $only_valid = static function($list) {
            $out = [];
            foreach ((array) $list as $address) {
                if (is_string($address) && is_email($address)) { $out[strtolower($address)] = sanitize_email($address); }
            }
            return array_values($out);
        };
        return ['template_id' => (int) $item['template']->id,
            'template' => (string) ($item['template']->title ?: ('Template #' . $item['template']->id)),
            'to' => $only_valid($to), 'cc' => $get($details->cc), 'bcc' => $get($details->bcc)];
    }

    public static function ajax_preview(): void {
        if (!self::allowed() || !check_ajax_referer('bf_email_resender_send', 'nonce', false)) {
            wp_send_json_error(['message' => 'Permission or security token check failed. Reload and retry.'], 403);
        }
        if (!self::available()) {
            wp_send_json_error(['message' => 'Bit Form Free 3.4.0 is required and must be active.'], 409);
        }
        $form_id = absint($_POST['form_id'] ?? 0);
        $template_id = absint($_POST['template_id'] ?? 0);
        $input = isset($_POST['entry_ids']) ? wp_unslash($_POST['entry_ids']) : [];
        $ids = is_array($input) ? array_values(array_unique(array_filter(array_map('absint', $input)))) : [];
        if (!$form_id || !$ids || count($ids) > 25) {
            wp_send_json_error(['message' => 'Select between 1 and 25 submissions.'], 400);
        }
        global $wpdb;
        if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM `{$wpdb->prefix}bitforms_form` WHERE id = %d AND status <> 2", $form_id))) {
            wp_send_json_error(['message' => 'Form not found.'], 404);
        }
        $result = [];
        foreach ($ids as $id) {
            try {
                $context = self::entry_context($form_id, $id);
                $notifications = $template_id ? array_values(array_filter($context['notifications'], static function($n) use($template_id) {
                    return (int) $n['template']->id === $template_id;
                })) : $context['notifications'];
                $notices = [];
                foreach ($notifications as $item) {
                    $notice = self::preview_notification_recipients($item, $context['formatted'], $form_id);
                    $notice['attachments'] = self::attachment_check($item, $form_id, $id, $context['values'], $context['fields']);
                    $notices[] = $notice;
                }
                $result[] = ['entry_id' => $id, 'notifications' => $notices];
            } catch (\Throwable $e) {
                $result[] = ['entry_id' => $id, 'error' => $e->getMessage(), 'notifications' => []];
            }
        }
        wp_send_json_success(['entries' => $result, 'pdf_catalog' => self::pdf_catalog($form_id)]);
    }

    /** Independent mail-path test: diagnoses Post SMTP separately from Bit Form templates/workflows. */
    public static function ajax_test_mail(): void {
        if (!self::allowed() || !check_ajax_referer('bf_email_resender_send', 'nonce', false)) {
            wp_send_json_error(['message' => 'Permission or security token check failed. Reload the page.'], 403);
        }
        $raw = wp_unslash($_POST['address'] ?? '');
        $address = is_string($raw) ? trim($raw) : '';
        if (!is_email($address) || strlen($address) > 254) {
            wp_send_json_error(['message' => 'Enter a valid recipient email address.'], 400);
        }
        $key = 'bfres_test_mail_' . get_current_user_id();
        if (get_transient($key)) {
            wp_send_json_error(['message' => 'Please allow at least 30 seconds between mail transport tests.'], 429);
        }
        set_transient($key, 1, 30);
        $errors = [];
        $failure = static function($error) use (&$errors) {
            if (is_wp_error($error)) { $errors[] = $error->get_error_message(); }
        };
        add_action('wp_mail_failed', $failure, PHP_INT_MAX);
        try {
            $accepted = wp_mail(
                sanitize_email($address),
                'Bit Form Email Resender mail transport test',
                'This is a requested test message from your WordPress administration. It does not use Bit Form templates or resend a submission.'
            );
        } catch (\Throwable $e) {
            remove_action('wp_mail_failed', $failure, PHP_INT_MAX);
            wp_send_json_error(['message' => 'wp_mail() threw an error: ' . $e->getMessage()], 500);
        }
        remove_action('wp_mail_failed', $failure, PHP_INT_MAX);
        if (!$accepted) {
            wp_send_json_error(['message' => 'wp_mail() returned false. ' . ($errors ? implode(' | ', $errors) : 'Check Post SMTP → Email Log / Session Transcript for the delivery error.')], 502);
        }
        wp_send_json_success(['message' => 'wp_mail() returned success. Check Post SMTP → Email Log and the test recipient inbox. This means the mailer accepted the message, not guaranteed inbox delivery.']);
    }

    public static function ajax_send(): void {
        if (!self::allowed()) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }
        if (!check_ajax_referer('bf_email_resender_send', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security token expired. Reload the page.'], 403);
        }
        if (!self::available()) {
            wp_send_json_error(['message' => 'Requires Bit Form Free version 3.4.0.'], 409);
        }
        $form_id = absint($_POST['form_id'] ?? 0);
        $template_id = absint($_POST['template_id'] ?? 0);
        $ids_input = isset($_POST['entry_ids']) ? wp_unslash($_POST['entry_ids']) : [];
        if (!is_array($ids_input)) {
            wp_send_json_error(['message' => 'Invalid entry selection.'], 400);
        }
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids_input))));
        $mode_raw = wp_unslash($_POST['recipient_mode'] ?? 'original');
        $mode = is_string($mode_raw) ? sanitize_key($mode_raw) : '';
        if (!in_array($mode, ['original', 'custom'], true)) {
            wp_send_json_error(['message' => 'Invalid recipient mode.'], 400);
        }
        $recipient_override = null;
        if ($mode === 'custom') {
            try {
                $recipient_override = [
                    'to'  => self::parse_recipient_input(wp_unslash($_POST['recipient_to'] ?? '')),
                    'cc'  => self::parse_recipient_input(wp_unslash($_POST['recipient_cc'] ?? '')),
                    'bcc' => self::parse_recipient_input(wp_unslash($_POST['recipient_bcc'] ?? '')),
                ];
                if (!$recipient_override['to']) { throw new \InvalidArgumentException('At least one valid To recipient is required.'); }
            } catch (\InvalidArgumentException $e) {
                wp_send_json_error(['message' => $e->getMessage()], 400);
            }
        }
        // PDF selections are explicit per (entry, email template), never global:
        // different emails can require different PDF templates.
        $pdf_choices = null;
        if (isset($_POST['pdf_choices'])) {
            $raw_choices = wp_unslash($_POST['pdf_choices']);
            if (!is_string($raw_choices) || strlen($raw_choices) > 16000) {
                wp_send_json_error(['message' => 'Invalid PDF selection payload.'], 400);
            }
            $choices = json_decode($raw_choices, true);
            if (!is_array($choices) || count($choices) > 150) {
                wp_send_json_error(['message' => 'Invalid PDF selections. Review and retry.'], 400);
            }
            $pdf_choices = [];
            foreach ($choices as $key => $selected) {
                if (!is_string($key) || !preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/', $key)
                    || !is_array($selected) || count($selected) > 30) {
                    wp_send_json_error(['message' => 'Invalid per-notification PDF selection.'], 400);
                }
                $pdf_choices[$key] = array_values(array_unique(array_map('absint', $selected)));
                if (in_array(0, $pdf_choices[$key], true)) {
                    wp_send_json_error(['message' => 'Invalid PDF template ID.'], 400);
                }
            }
        }
        if (!$form_id || !$ids || count($ids) > self::MAX_BATCH) {
            wp_send_json_error(['message' => 'Choose between 1 and ' . self::MAX_BATCH . ' submissions.'], 400);
        }
        global $wpdb;
        $forms_table = $wpdb->prefix . 'bitforms_form';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$forms_table}` WHERE id = %d AND status <> 2", $form_id));
        if (!$exists) {
            wp_send_json_error(['message' => 'Form not found.'], 404);
        }
        $pdf_allowed = array_column(self::pdf_catalog($form_id), 'id');
        if ($pdf_choices !== null) {
            foreach ($pdf_choices as $choice) {
                if (array_diff($choice, $pdf_allowed)) {
                    wp_send_json_error(['message' => 'One or more selected PDFs are not enabled or do not belong to this form.'], 400);
                }
            }
        }
        $results = [];
        foreach ($ids as $id) {
            try {
                $results[] = self::send_entry($form_id, $id, $template_id, $recipient_override, $pdf_choices);
            } catch (\Throwable $e) {
                $results[] = ['entry_id' => $id, 'status' => 'error', 'message' => 'Unexpected send error: ' . $e->getMessage()];
            }
        }
        wp_send_json_success(['results' => $results]);
    }

    private static function send_entry(int $form_id, int $entry_id, int $template_id, ?array $recipient_override, ?array $pdf_choices): array {
        global $wpdb;
        // Atomic lock prevents duplicate email bursts when multiple admins click at once.
        $lock = 'bfres_lock_' . $form_id . '_' . $entry_id;
        $locked = add_option($lock, time(), '', false);
        if (!$locked) {
            $since = (int) get_option($lock, 0);
            if ($since && $since < time() - 180) {
                delete_option($lock);
                $locked = add_option($lock, time(), '', false);
            }
        }
        if (!$locked) {
            return ['entry_id' => $entry_id, 'status' => 'error', 'message' => 'A resend for this entry is already in progress.'];
        }
        try {
            try {
                $context = self::entry_context($form_id, $entry_id);
            } catch (\Throwable $e) {
                return ['entry_id' => $entry_id, 'status' => 'error', 'message' => $e->getMessage()];
            }
            $found = $context['notifications'];
            $formatted = $context['formatted'];
            if ($template_id) {
                $found = array_values(array_filter($found, static function($n) use($template_id) {
                    return (int) $n['template']->id === $template_id;
                }));
            }
            if (!$found) {
                return ['entry_id' => $entry_id, 'status' => 'skipped', 'message' => 'No enabled notifications match the submission and workflow rules.'];
            }
            $accepted = 0;
            $attempted = 0;
            $errors = [];
            $addresses = [];
            // Create a separate Bit Form entry log, so native mail logging attaches to this resend rather than its original submission.
            $log_data = [
                'user_id'       => get_current_user_id(),
                'log_type'      => 'entry',
                'action_type'   => 'email_resend',
                'form_entry_id' => $entry_id,
                'form_id'       => $form_id,
                'content'       => wp_json_encode(['source' => 'bf-email-resender']),
                'created_at'    => current_time('mysql'),
            ];
            $log_ok = $wpdb->insert($wpdb->prefix . 'bitforms_form_entry_log', $log_data);
            $log_id = $log_ok ? (int) $wpdb->insert_id : 0;
            $all_attachments = 0;
            $all_pdfs = 0;
            foreach ($found as $item) {
                $item['details'] = clone $item['details'];
                $choice_key = $entry_id . ':' . (int) $item['template']->id;
                if ($pdf_choices !== null) {
                    if (!array_key_exists($choice_key, $pdf_choices)) {
                        $errors[] = 'PDF review for ' . $choice_key . ' is missing. Reopen Review & resend before sending.';
                        continue;
                    }
                    self::apply_pdf_choice($item['details'], $pdf_choices[$choice_key]);
                }
                $report = self::attachment_check($item, $form_id, $entry_id, $context['values'], $context['fields']);
                $label = $item['template']->title ?: ('Template #' . $item['template']->id);
                if ($report['blocking']) {
                    $errors[] = $label . ': Attachment preflight failed: ' . implode(' ', $report['blocking']);
                    continue; // Never intentionally resend a message missing required assets.
                }
                // A template without a resolved To cannot send in original mode. Make this
                // explicit instead of silently entering MailNotifier with an empty array.
                if ($recipient_override === null) {
                    $destinations = self::preview_notification_recipients($item, $formatted, $form_id);
                    if (!$destinations['to']) {
                        $errors[] = ($item['template']->title ?: ('Template #' . $item['template']->id))
                            . ': No To recipient could be resolved. Review the original To field, form values and conditional routing, or choose custom To.';
                        continue;
                    }
                }
                $sent_in_this_call = 0;
                $failed_in_this_call = false;
                $captured_args = false;
                $seen_errors = [];
                $captured_attachments = [];
                $pdf_generated = null;
                $pdf_bodies_prepared = 0;
                $pdf_render_traces = [];
                $pdf_body_observer = static function($body, $ctx = null) use (&$pdf_bodies_prepared, &$pdf_render_traces) {
                    $pdf_bodies_prepared++;
                    if (is_string($body) && is_array($ctx) && isset($ctx['pdf_setting']) && is_object($ctx['pdf_setting']) && count($pdf_render_traces) < 2) {
                        // The Pro renderer adds header/footer to this same object immediately after the hook.
                        $pdf_render_traces[] = ['body' => $body, 'setting' => $ctx['pdf_setting']];
                    }
                    return $body;
                };
                $pdf_observer = static function($paths) use (&$pdf_generated) {
                    $paths = is_array($paths) ? $paths : [$paths];
                    $pdf_generated = count(array_filter($paths, static function($path) {
                        return is_string($path) && is_file($path) && is_readable($path);
                    }));
                    return is_array($paths) ? $paths : [];
                };
                $missing_pdf_before_send = false;
                $pdf_guard = static function($pre, $args) use ($report, &$missing_pdf_before_send) {
                    if ($pre !== null || !$report['pdf_templates']) { return $pre; }
                    $pdf_paths = (array) ($args['attachments'] ?? []);
                    $count = 0;
                    foreach ($pdf_paths as $path) {
                        if (is_string($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf' && is_readable($path)) { $count++; }
                    }
                    if ($count < $report['pdf_templates']) {
                        $missing_pdf_before_send = true;
                        return false; // Do not send an incomplete email containing sensitive PDF content.
                    }
                    return $pre;
                };
                $capture = static function($args) use (&$attempted, &$captured_args, &$addresses, &$captured_attachments) {
                    $captured_attachments = array_values(array_filter((array) ($args['attachments'] ?? []), 'is_string'));
                    $attempted++;
                    $captured_args = true;
                    foreach ((array) ($args['to'] ?? []) as $address) {
                        if (is_string($address) && is_email($address)) {
                            $addresses[] = sanitize_email($address);
                        }
                    }
                    return $args;
                };
                $success = static function($mail) use (&$accepted, &$sent_in_this_call) {
                    $accepted++;
                    $sent_in_this_call++;
                };
                $failure = static function($error) use (&$failed_in_this_call, &$seen_errors) {
                    $failed_in_this_call = true;
                    if (is_wp_error($error)) {
                        $seen_errors[] = $error->get_error_message();
                    }
                };
                add_filter('bitform_filter_pdf_body', $pdf_body_observer, PHP_INT_MAX, 2);
                add_filter('bitform_generate_pdf_attachment', $pdf_observer, PHP_INT_MAX, 1);
                add_filter('pre_wp_mail', $pdf_guard, -PHP_INT_MAX, 2);
                add_filter('wp_mail', $capture, PHP_INT_MAX);
                add_action('wp_mail_succeeded', $success, PHP_INT_MAX);
                add_action('wp_mail_failed', $failure, PHP_INT_MAX);
                $details = clone $item['details'];
                // Custom recipient mode must remove every original recipient, including Pro conditional routes.
                $route_override = null;
                if (null !== $recipient_override) {
                    $details->to = $recipient_override['to'];
                    $details->cc = $recipient_override['cc'];
                    $details->bcc = $recipient_override['bcc'];
                    $route_override = static function() use ($recipient_override) { return $recipient_override['to']; };
                    add_filter('bitform_email_conditional_routing', $route_override, PHP_INT_MAX);
                }
                try {
                    \BitApps\BitForm\Services\Notification\MailNotifier::notify(
                        $details, $form_id, $formatted, $entry_id, false, $log_id
                    );
                } catch (\Throwable $e) {
                    $failed_in_this_call = true;
                    $seen_errors[] = $e->getMessage();
                } finally {
                    if (null !== $route_override) { remove_filter('bitform_email_conditional_routing', $route_override, PHP_INT_MAX); }
                    remove_filter('bitform_filter_pdf_body', $pdf_body_observer, PHP_INT_MAX);
                    remove_filter('bitform_generate_pdf_attachment', $pdf_observer, PHP_INT_MAX);
                    remove_filter('pre_wp_mail', $pdf_guard, -PHP_INT_MAX);
                    remove_filter('wp_mail', $capture, PHP_INT_MAX);
                    remove_action('wp_mail_succeeded', $success, PHP_INT_MAX);
                    remove_action('wp_mail_failed', $failure, PHP_INT_MAX);
                }
                $pdf_count = 0;
                foreach ($captured_attachments as $file) {
                    if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf') { $pdf_count++; }
                }
                $all_attachments += count($captured_attachments);
                $all_pdfs += $pdf_count;
                if ($sent_in_this_call && count($captured_attachments) < $report['uploads_required'] + $report['media_required'] + $report['pdf_templates']) {
                    $errors[] = $label . ': Email handed off, but fewer attachments were passed to WordPress than configured. Review Post SMTP log before retrying.';
                }
                if ($sent_in_this_call && $pdf_count < $report['pdf_templates']) {
                    $errors[] = $label . ': Email handed off without all configured PDF templates (' . $pdf_count . '/' . $report['pdf_templates'] . '). Check Bit Form Pro PDF errors before retrying.';
                }
                if (!$sent_in_this_call) {
                    $pdf_details = $report['pdf_templates']
                        ? ' Bit Form Pro generated ' . ($pdf_generated === null ? 'an unobserved number of' : $pdf_generated) . ' / ' . $report['pdf_templates'] . ' selected PDF(s).'
                        : '';
                    if ($missing_pdf_before_send) {
                        $why = 'Sending blocked: selected PDF attachment(s) were not generated or not readable.';
                    } else {
                        $why = $seen_errors ? implode('; ', $seen_errors) : ($captured_args ? 'Mail handoff not confirmed.' : 'Bit Form did not invoke wp_mail().');
                    }
                    $native_diagnostics = $report['pdf_templates'] ? self::pdf_native_diagnostics($log_id) : [];
                    $debug_details = $report['pdf_templates']
                        ? ' PDF body preparation reached ' . $pdf_bodies_prepared . ' / ' . $report['pdf_templates'] . ' template(s).'
                        : '';
                    if ($native_diagnostics) {
                        $debug_details .= ' Native Bit Form results: ' . implode(' | ', $native_diagnostics) . '.';
                    } elseif ($report['pdf_templates']) {
                        $debug_details .= ' No native PDF status record found for this resend.';
                    }
                    if ($report['pdf_templates'] && $pdf_generated === 0 && $pdf_render_traces) {
                        $render_diagnostics = self::pdf_template_render_diagnostics($pdf_render_traces);
                        if ($render_diagnostics) { $debug_details .= ' Actual mPDF diagnostic: ' . implode(' | ', $render_diagnostics) . '.'; }
                    }
                    $errors[] = $label . ': ' . $why . $pdf_details . $debug_details
                        . ($report['pdf_templates'] ? ' If needed, use the PDF generator test above and check PHP error logs.' : '');
                }
            }
            $status = $accepted && !$errors ? 'accepted' : ($accepted ? 'partial' : 'error');
            $message = $accepted . ' notification(s) accepted by WordPress mail; ' . $all_attachments . ' attached file(s), including ' . $all_pdfs . ' PDF(s); ' . count($errors) . ' warning(s)/failure(s).';
            if ($errors) { $message .= ' ' . implode(' | ', $errors); }
            self::add_history([
                'time' => current_time('mysql'), 'form' => $form_id, 'entry' => $entry_id,
                'attempted' => $attempted, 'accepted' => $accepted, 'attachments' => $all_attachments, 'pdfs' => $all_pdfs, 'status' => $status,
                'user' => (string) wp_get_current_user()->user_login,
                'recipient_mode' => $recipient_override === null ? 'original' : 'custom',
                'to' => array_values(array_unique($addresses)),
            ]);
            return ['entry_id' => $entry_id, 'status' => $status, 'accepted' => $accepted, 'attempted' => $attempted, 'attachments' => $all_attachments, 'pdfs' => $all_pdfs, 'message' => $message];
        } finally {
            delete_option($lock);
        }
    }

    private static function add_history(array $history): void {
        $existing = get_option(self::HISTORY_OPTION, []);
        $existing = is_array($existing) ? $existing : [];
        $existing[] = $history;
        update_option(self::HISTORY_OPTION, array_slice($existing, -150), false);
    }
}
add_action('plugins_loaded', ['BF_Email_Resender', 'init'], 20);
