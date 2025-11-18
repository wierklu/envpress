<?php

declare(strict_types=1);

namespace EnvPress\Layer;

use EnvPress\Exception\InvalidEnvVarException;
use EnvPress\Util\Env;

/**
 * If the environment variable is present, set the WordPress admin email address
 * to it and make the relevant field in the WordPress admin read-only. Disable
 * admin email verification checks and email change confirmations.
 *
 * Must be applied after the MultisiteLayer.
 */
class AdminEmailLayer implements LayerInterface
{
    /**
     * Environment variable key for the admin email.
     */
    const ENV_VAR_KEY = 'ADMIN_EMAIL';

    /**
     * Create a new AdminEmailLayer instance.
     *
     * @return void
     */
    private function __construct()
    {
        //
    }

    /**
     * Create a new AdminEmailLayer instance.
     *
     * @return AdminEmailLayer
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Return the name of a WordPress hook from which this layer should be
     * applied. If null is returned, the layer should be applied immediately.
     *
     * @return string|null
     */
    public function getHookName(): string|null
    {
        return 'init';
    }

    /**
     * Decide on whether this layer should be applied based on the current
     * environment.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        // Opt in to this layer by providing the relevant environment variable
        return !empty(Env::getString(self::ENV_VAR_KEY, ''));
    }

    /**
     * Apply the configuration of this layer.
     *
     * @return void
     */
    public function apply(): void
    {
        $adminEmail = Env::getString(self::ENV_VAR_KEY, '');

        if (!\is_multisite()) {
            $this->applyAdminEmailToCurrentSite($adminEmail);
        } else {
            $sites = get_sites([
                'fields' => 'ids',
                // The magic -1 does not appear to work here
                'number' => 1024,
            ]);
            foreach ($sites as $blogId) {
                \switch_to_blog($blogId);
                $this->applyAdminEmailToCurrentSite($adminEmail);
                \restore_current_blog();
            }

            // TODO: Handle network admin email change confirmations
        }
    }

    /**
     * Apply the configuration of this layer to the current site.
     *
     * @return void
     */
    private function applyAdminEmailToCurrentSite(string $adminEmail): void
    {
        // Note that `get_option` and `update_option` are site-specific
        // As `admin_email` is autoloaded, it is immediately available
        $currentAdminEmail = \get_option('admin_email', '');

        // Handle an admin email change
        if ($currentAdminEmail !== $adminEmail) {
            // Validate email address
            if (!$this->validateEmail($adminEmail)) {
                throw new InvalidEnvVarException(
                    'Env var ' . self::ENV_VAR_KEY .
                    ' contains an invalid email address'
                );
            }

            // Update admin email and clear potential pending change
            \update_option('admin_email', $adminEmail, true);
            \delete_option('adminhash');
		    \delete_option('new_admin_email');
        }

        // Disable admin email verification checks
        \add_filter('admin_email_check_interval', '__return_false');

        // Disable admin email change confirmations
        \add_filter('send_site_admin_email_change_email', '__return_false');
        // Added in wp-admins/includes/admin-filters.php
        \remove_action(
            'add_option_new_admin_email',
            'update_option_new_admin_email',
            10
        );
        \remove_action(
            'update_option_new_admin_email',
            'update_option_new_admin_email',
            10
        );

        // Capture and modify the admin email input field and description
        \add_action('admin_head', [$this, 'captureGeneralOptions']);
        \add_action('admin_footer', [$this, 'modifyGeneralOptions']);
    }

    /**
     * Validate an email address. It must be valid and canonicalized.
     *
     * @param string $email
     *
     * @return bool
     */
    private function validateEmail(string $email): bool
    {
        return
            \is_email($email) &&
            \sanitize_email($email) === $email;
    }

    /**
     * Triggered by the `admin_head` hook, start capturing the general
     * options HTML.
     *
     * @return void
     */
    public function captureGeneralOptions(): void
    {
        global $pagenow;
        if ($pagenow === 'options-general.php') {
            ob_start();
        }
    }

    /**
     * Triggered by the `admin_footer` hook, stop capturing the general options
     * HTML and then modify it, updating the admin email input and description.
     *
     * @return void
     */
    public function modifyGeneralOptions(): void
    {
        global $pagenow;
        if ($pagenow === 'options-general.php') {
            $html = ob_get_contents();
            ob_end_clean();

            // Find and disable the admin email input field
            $html = preg_replace_callback(
                '/<input [^>]*name=["\']new_admin_email["\'][^>]*>/',
                function ($matches) {
                    $inputHtml = $matches[0];

                    if (!str_contains($inputHtml, 'disabled')) {
                        $inputHtml =
                            substr($inputHtml, 0, -1) .
                            ' disabled=\'disabled\'>';
                        $inputHtml = preg_replace(
                            '/class=["\']([^"\']*)["\']/',
                            'class="$1 disabled"',
                            $inputHtml,
                            1
                        );
                    }

                    return $inputHtml;
                },
                $html,
                1
            );

            // TODO: Translate this message
            $descriptionHtml = 'This email address is managed by your hosting environment and can’t be changed here.';

            // Find and replace the admin email description
            $html = preg_replace(
                '/<p ([^>]*)id=["\']new-admin-email-description["\']([^>]*)>(.*?)<\/p>/',
                '<p $1id="new-admin-email-description"$2>' . $descriptionHtml . '</p>',
                $html,
                1
            );

            echo $html;
        }
    }
}
