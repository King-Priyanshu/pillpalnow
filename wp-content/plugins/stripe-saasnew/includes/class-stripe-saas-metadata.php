<?php
/**
 * Stripe SaaS Metadata
 * 
 * Standardizes and validates Stripe metadata across all objects
 */

if (!defined('ABSPATH')) {
    exit;
}

class Stripe_SaaS_Metadata
{

    /**
     * Build standardized metadata array
     */
    public static function build($custom_data = [])
    {
        $base = [
            'wp_domain' => parse_url(site_url(), PHP_URL_HOST),
            'plugin_id' => 'stripe_saas',
            'schema_version' => '1'
        ];

        return array_merge($base, $custom_data);
    }

    /**
     * Validate metadata object
     */
    public static function validate($metadata, $required_keys = [])
    {
        // Convert object to array if needed
        if (is_object($metadata)) {
            $metadata = (array) $metadata;
        }

        // Check required keys (prioritize these over domain validation)
        foreach ($required_keys as $key) {
            if (!isset($metadata[$key])) {
                error_log('Stripe SaaS: Missing required metadata key: ' . $key);
                return false;
            }
        }

        // Domain validation - only warn if metadata exists but doesn't match (backward compatibility)
        if (isset($metadata['wp_domain'])) {
            $expected_domain = parse_url(site_url(), PHP_URL_HOST);
            if ($metadata['wp_domain'] !== $expected_domain) {
                error_log('Stripe SaaS: Domain mismatch - Expected: ' . $expected_domain . ', Got: ' . $metadata['wp_domain']);
                // Don't fail validation - just warn
            }
        }

        return true;
    }

    /**
     * Extract user ID from metadata
     */
    public static function get_user_id($metadata)
    {
        if (is_object($metadata)) {
            return $metadata->user_id ?? null;
        }
        return $metadata['user_id'] ?? null;
    }

    /**
     * Extract tier slug from metadata
     */
    public static function get_tier_slug($metadata)
    {
        if (is_object($metadata)) {
            return $metadata->tier_slug ?? null;
        }
        return $metadata['tier_slug'] ?? null;
    }
}
