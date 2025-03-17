<?php
/**
 * Plugin Name: User Tags
 * Description: Categorize users with custom taxonomies and filter them in the admin panel.
 * Version: 1.0.0
 * Author: Developer
 * Text Domain: user-tags
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class UserTags {
    /**
     * The single instance of the class
     */
    private static $instance = null;

    /**
     * Taxonomy name
     */
    private $taxonomy = 'user_tag';

    /**
     * Main UserTags Instance
     */
    public static function instance() {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        // Register taxonomy
        add_action('init', array($this, 'register_taxonomy'));
        
        // Admin hooks
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add user profile fields for EXISTING users
        add_action('show_user_profile', array($this, 'user_profile_fields'));
        add_action('edit_user_profile', array($this, 'user_profile_fields'));
        add_action('personal_options_update', array($this, 'save_user_profile_fields'));
        add_action('edit_user_profile_update', array($this, 'save_user_profile_fields'));
        
        // Add user tags field to NEW USER form
        add_action('user_new_form', array($this, 'user_profile_fields'));
        add_action('user_register', array($this, 'save_new_user_fields'));
        
        // Add filter to users.php
        add_action('restrict_manage_users', array($this, 'add_users_filter'));
        add_filter('pre_get_users', array($this, 'filter_users_by_taxonomy'));
        
        // AJAX handlers
        add_action('wp_ajax_search_user_tags', array($this, 'search_user_tags'));
    }

    /**
     * Register user_tag taxonomy
     */
    public function register_taxonomy() {
        $labels = array(
            'name'                       => _x('User Tags', 'taxonomy general name', 'user-tags'),
            'singular_name'              => _x('User Tag', 'taxonomy singular name', 'user-tags'),
            'search_items'               => __('Search User Tags', 'user-tags'),
            'popular_items'              => __('Popular User Tags', 'user-tags'),
            'all_items'                  => __('All User Tags', 'user-tags'),
            'parent_item'                => null,
            'parent_item_colon'          => null,
            'edit_item'                  => __('Edit User Tag', 'user-tags'),
            'update_item'                => __('Update User Tag', 'user-tags'),
            'add_new_item'               => __('Add New User Tag', 'user-tags'),
            'new_item_name'              => __('New User Tag Name', 'user-tags'),
            'separate_items_with_commas' => __('Separate user tags with commas', 'user-tags'),
            'add_or_remove_items'        => __('Add or remove user tags', 'user-tags'),
            'choose_from_most_used'      => __('Choose from the most used user tags', 'user-tags'),
            'not_found'                  => __('No user tags found.', 'user-tags'),
            'menu_name'                  => __('User Tags', 'user-tags'),
        );

        $args = array(
            'hierarchical'          => false,
            'labels'                => $labels,
            'show_ui'               => true,
            'show_admin_column'     => false,
            'query_var'             => true,
            'rewrite'               => array('slug' => 'user-tag'),
            'show_in_rest'          => true,
        );

        register_taxonomy($this->taxonomy, null, $args);
    }

    /**
     * Add admin menu item
     */
    public function admin_menu() {
        add_users_page(
            __('User Tags', 'user-tags'),
            __('User Tags', 'user-tags'),
            'manage_options',
            'edit-tags.php?taxonomy=' . $this->taxonomy
        );
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts($hook) {
        // Only enqueue on users.php, profile pages, and user-new.php
        if (!in_array($hook, array('users.php', 'user-edit.php', 'profile.php', 'user-new.php'))) {
            return;
        }

        // Enqueue Select2
        wp_enqueue_style('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css');
        wp_enqueue_script('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array('jquery'), null, true);
        
        // Enqueue custom script
        wp_enqueue_script('user-tags', plugins_url('js/user-tags.js', __FILE__), array('jquery', 'select2'), '1.0.0', true);
        
        // Localize script
        wp_localize_script('user-tags', 'userTags', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('user_tags_nonce')
        ));
    }

    /**
     * Add User Tags field to user profile
     * Works for both new user and edit user forms
     */
    public function user_profile_fields($user) {
        $user_id = 0;
        $user_tags = array();
        $tag_ids = array();
        
        // Check if we're editing an existing user
        if (is_object($user) && isset($user->ID)) {
            $user_id = $user->ID;
            // Get user tags
            $user_tags = $this->get_user_terms($user_id);
            
            if (!empty($user_tags)) {
                foreach ($user_tags as $tag) {
                    $tag_ids[] = $tag->term_id;
                }
            }
        }
        ?>
        <h3><?php _e('User Tags', 'user-tags'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="user_tags"><?php _e('Tags', 'user-tags'); ?></label></th>
                <td>
                    <select name="user_tags[]" id="user_tags" class="user-tags-select" multiple="multiple" style="width: 100%;">
                        <?php
                        if (!empty($user_tags)) {
                            foreach ($user_tags as $tag) {
                                echo '<option value="' . esc_attr($tag->term_id) . '" selected="selected">' . esc_html($tag->name) . '</option>';
                            }
                        }
                        ?>
                    </select>
                    <p class="description"><?php _e('Select or search for tags to assign to this user.', 'user-tags'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save user tags for existing users
     */
    public function save_user_profile_fields($user_id) {
        // Check if current user has permission to edit the user
        if (!current_user_can('edit_user', $user_id)) {
            return false;
        }

        // Save user tags
        if (isset($_POST['user_tags']) && is_array($_POST['user_tags'])) {
            $term_ids = array_map('intval', $_POST['user_tags']);
            $this->set_user_terms($user_id, $term_ids);
        } else {
            // If no tags are selected, remove all tags
            $this->set_user_terms($user_id, array());
        }
    }

    /**
     * Save user tags for new users
     */
    public function save_new_user_fields($user_id) {
        // For new users, we don't need to check the nonce as WordPress has already done that
        // Save user tags if they exist in the form submission
        if (isset($_POST['user_tags']) && is_array($_POST['user_tags'])) {
            $term_ids = array_map('intval', $_POST['user_tags']);
            $this->set_user_terms($user_id, $term_ids);
        }
    }

    /**
     * Add filter dropdown to users list
     */
    public function add_users_filter() {
        $tag_id = isset($_GET[$this->taxonomy]) ? intval($_GET[$this->taxonomy]) : 0;
        
        // Get all taxonomy terms
        $terms = get_terms(array(
            'taxonomy' => $this->taxonomy,
            'hide_empty' => false,
        ));
        
        if (empty($terms)) {
            return;
        }
        
        echo '<label class="screen-reader-text" for="' . $this->taxonomy . '">' . __('Filter by user tag', 'user-tags') . '</label>';
        echo '<select name="' . $this->taxonomy . '" id="' . $this->taxonomy . '" class="user-tags-filter" style="width: 150px; margin-left: 5px;">';
        echo '<option value="0">' . __('All User Tags', 'user-tags') . '</option>';
        
        foreach ($terms as $term) {
            $selected = selected($tag_id, $term->term_id, false);
            echo '<option value="' . $term->term_id . '" ' . $selected . '>' . $term->name . '</option>';
        }
        
        echo '</select>';
        
        // Add a submit button next to the dropdown for better UX
        submit_button(__('Filter', 'user-tags'), '', 'filter_action', false);
    }

    /**
     * Filter users based on selected tag
     */
    public function filter_users_by_taxonomy($query) {
        global $pagenow;
        
        // Only on users.php page
        if ($pagenow !== 'users.php') {
            return $query;
        }
        
        // Check if filter is set
        if (isset($_GET[$this->taxonomy]) && intval($_GET[$this->taxonomy]) > 0) {
            $tag_id = intval($_GET[$this->taxonomy]);
            
            // Get users with the selected tag
            $users = $this->get_users_by_term_id($tag_id);
            
            if (!empty($users)) {
                $query->set('include', $users);
            } else {
                // If no users have this tag, force empty result
                $query->set('include', array(0));
            }
        }
        
        return $query;
    }

    /**
     * AJAX handler for searching tags
     */
    public function search_user_tags() {
        // Security check
        check_ajax_referer('user_tags_nonce', 'nonce');
        
        $search = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        
        $args = array(
            'taxonomy' => $this->taxonomy,
            'hide_empty' => false,
            'number' => 10,
            'offset' => ($page - 1) * 10,
            'fields' => 'all'
        );
        
        if (!empty($search)) {
            $args['search'] = $search;
        }
        
        $terms = get_terms($args);
        $total = wp_count_terms(array(
            'taxonomy' => $this->taxonomy,
            'hide_empty' => false,
            'search' => !empty($search) ? $search : ''
        ));
        
        $results = array();
        foreach ($terms as $term) {
            $results[] = array(
                'id' => $term->term_id,
                'text' => $term->name
            );
        }
        
        $response = array(
            'results' => $results,
            'pagination' => array(
                'more' => ($page * 10) < $total
            )
        );
        
        wp_send_json($response);
        exit;
    }

    /**
     * Get user terms
     */
    public function get_user_terms($user_id) {
        $terms = get_terms(array(
            'taxonomy' => $this->taxonomy,
            'hide_empty' => false,
            'meta_query' => array(
                array(
                    'key' => 'user_id',
                    'value' => $user_id,
                    'compare' => 'IN'
                )
            )
        ));
        
        return $terms;
    }

    /**
     * Set user terms
     */
    public function set_user_terms($user_id, $term_ids) {
        // First, clear all existing user associations for this user
        $existing_terms = $this->get_user_terms($user_id);
        
        foreach ($existing_terms as $term) {
            $user_ids = get_term_meta($term->term_id, 'user_id', false);
            $user_ids = array_diff($user_ids, array($user_id));
            
            delete_term_meta($term->term_id, 'user_id', $user_id);
            
            // Re-add the filtered user IDs
            foreach ($user_ids as $id) {
                add_term_meta($term->term_id, 'user_id', $id);
            }
        }
        
        // Then add new associations
        foreach ($term_ids as $term_id) {
            add_term_meta($term_id, 'user_id', $user_id);
        }
    }

    /**
     * Get users by term ID
     */
    public function get_users_by_term_id($term_id) {
        $user_ids = get_term_meta($term_id, 'user_id', false);
        return $user_ids;
    }
}

// Initialize the plugin
function user_tags_init() {
    return UserTags::instance();
}

// Start the plugin
user_tags_init();