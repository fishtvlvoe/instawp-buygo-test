<?php

namespace BuyGo\Core\Services;

class RoleManager {

    public function __construct() {
        add_action('init', [$this, 'register_roles']);
    }

    public function register_roles() {
        // Register Buyer Role (default role for new LINE users in Plus One flow)
        if (!get_role('buygo_buyer')) {
            add_role(
                'buygo_buyer',
                __('BuyGo Buyer', 'buygo-role-permission'),
                [
                    'read' => true,
                ]
            );
        }

        // Register BuyGo Admin Role (can manage BuyGo plugin features only)
        if (!get_role('buygo_admin')) {
            add_role(
                'buygo_admin',
                __('BuyGo Admin', 'buygo-role-permission'),
                [
                    'read' => true,
                    'manage_buygo_shop' => true,
                    'manage_buygo_settings' => true, // Custom capability for BuyGo settings
                ]
            );
        }

        // Register Seller Role
        if (!get_role('buygo_seller')) {
            add_role(
                'buygo_seller',
                __('BuyGo Seller', 'buygo-role-permission'),
                [
                    'read' => true,
                    'upload_files' => true,
                    // Minimal permissions, mostly Custom Capabilities
                    'manage_buygo_shop' => true, 
                ]
            );
        }

        // Register Helper Role
        if (!get_role('buygo_helper')) {
            add_role(
                'buygo_helper',
                __('BuyGo Helper', 'buygo-role-permission'),
                [
                    'read' => true,
                    'manage_buygo_shop' => true, // Helper also needs to access shop features
                ]
            );
        }
    }

    /**
     * Check if a user has a specific role.
     * 
     * @param int $user_id
     * @param string $role
     * @return bool
     */
    public function user_has_role($user_id, $role) {
        $user = get_userdata($user_id);
        if (!$user) return false;
        return in_array($role, (array) $user->roles);
    }

    public function is_seller($user_id) {
        return $this->user_has_role($user_id, 'buygo_seller');
    }

    public function is_helper($user_id) {
        return $this->user_has_role($user_id, 'buygo_helper');
    }

    public function is_admin($user_id) {
        return $this->user_has_role($user_id, 'buygo_admin');
    }

    /**
     * Set user role (replaces existing roles)
     */
    public function set_user_role($user_id, $role) {
        $user = get_userdata($user_id);
        if (!$user) return false;
        
        $old_roles = $user->roles;
        
        // Remove all roles
        $user->set_role($role);
        
        // Trigger role sync to FluentCart and FluentCommunity
        $role_sync = App::instance()->make(Services\RoleSyncService::class);
        if ($role_sync) {
            $role_sync->sync_role_to_integrations($user_id, $role);
        }
        
        // Trigger hook for other plugins
        do_action('buygo_role_changed', $user_id, $old_roles, [$role]);
        
        return true;
    }

    /**
     * Add role to user
     */
    public function add_user_role($user_id, $role) {
        $user = get_userdata($user_id);
        if (!$user) return false;
        $user->add_role($role);
        return true;
    }

    /**
     * Remove role from user
     */
    public function remove_user_role($user_id, $role) {
        $user = get_userdata($user_id);
        if (!$user) return false;
        $user->remove_role($role);
        return true;
    }
    /**
     * Get all users with a specific role.
     * 
     * @param string $role
     * @return array List of WP_User objects
     */
    public function get_users_by_role($role) {
        $args = [
            'role'    => $role,
            'orderby' => 'user_nicename',
            'order'   => 'ASC'
        ];
        return get_users($args);
    }

    /**
     * Validate if user has specific permission.
     * 
     * @param int $user_id
     * @param string $permission
     * @return bool
     */
    public function validate_role_permission($user_id, $permission) {
        return user_can($user_id, $permission);
    }

    /**
     * Check if helper has permission for a specific seller's resource.
     * 
     * @param int $helper_id Helper's user ID
     * @param int $seller_id Seller's user ID
     * @param string $permission Permission to check (e.g., 'can_manage_products', 'can_view_orders')
     * @return bool
     */
    public function helper_can($helper_id, $seller_id, $permission) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'buygo_helpers';

        // First, check if user is the seller themselves
        if ($helper_id == $seller_id) {
            return true; // Sellers have full permission on their own resources
        }

        // Check if user is an admin
        if ($this->is_admin($helper_id) || user_can($helper_id, 'manage_options')) {
            return true; // Admins have full permissions
        }

        // Check helper relationship and permission
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT %i FROM $table_name WHERE seller_id = %d AND helper_id = %d AND status = 'active'",
            $permission,
            $seller_id,
            $helper_id
        ));

        return $result && !empty($result->$permission);
    }

    /**
     * Get all sellers that a helper is assigned to.
     * 
     * @param int $helper_id
     * @return array Array of seller IDs
     */
    public function get_helper_sellers($helper_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'buygo_helpers';

        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT seller_id FROM $table_name WHERE helper_id = %d AND status = 'active'",
            $helper_id
        ));

        return $results ?: [];
    }

    /**
     * Check if user can access seller's resource (either as seller or helper).
     * This is a convenience method that checks both seller identity and helper permission.
     * 
     * @param int $user_id User ID attempting access
     * @param int $seller_id Seller ID who owns the resource
     * @param string $permission Required permission (for helpers)
     * @return bool
     */
    public function can_access_seller_resource($user_id, $seller_id, $permission = 'can_view_orders') {
        // Seller can always access their own resources
        if ($user_id == $seller_id) {
            return true;
        }

        // Admins can access all resources
        if ($this->is_admin($user_id) || user_can($user_id, 'manage_options')) {
            return true;
        }

        // Check helper permission
        if ($this->is_helper($user_id)) {
            return $this->helper_can($user_id, $seller_id, $permission);
        }

        return false;
    }
}
