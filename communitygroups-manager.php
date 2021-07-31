<?php
/**
 * Plugin Name: Community Groups Manager 
 * Plugin URI: github.com/jhollandaise/communitygroups-manager
 * Description: Handles ANC Community Groups data  
 * Version: 0.0.1
 * Author: Joseph Holland
 * Author URI: jhol.land
 * Licence: GPLv2 
 * Licence URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html 
 */

define("CSAPI_ROOT_URL","https://api.churchsuite.co.uk/v1/smallgroups/");

$REQUEST_HEADERS = [];
$RELEVANT_CS_TAG = "CG2021-1";
$POST_CATEGORY = "Community Groups";

function cg_update_posts() {
    $x_headers = json_decode(file_get_contents(dirname(__FILE__) . "/$filename"),
                    true);
    foreach(array("X-Account", "X-Application", "X-Auth") as $header_name) {
        if(!array_key_exists($header_name, $x_headers)) {
            throw new Exception(
                "Missing $header_name in x_headers.json");
        }
    }
    $REQUEST_HEADERS =  array_merge($x_headers,
        ["Content_Type" => "application/json"]);

    
    $tags = wp_remote_get(CSAPI_ROOT_URL . "tags",
        ['headers' => $REQUEST_HEADERS]);

    if(is_wp_error($tags)) throw new exception("Failed to submit request");
    if(wp_remote_retrieve_response_code($tags)!=200)
        throw new Exception("invalid response");

    $tags = json_decode(wp_remote_retrieve_body($tags));
    $tag_name_to_id = array_map(function($x)
                    {return $x["name"] => $x["id"];},
                    $tags);
    if (!array_key_exists($RELEVANT_CG_TAG, $tag_name_to_id))
        throw new Exception("invalid RELEVANT_CS_TAG");
    $relevant_tag_id = $tag_name_to_id[$RELEVANT_CG_TAG];

    $groups_to_tags = wp_remote_get(CSAPI_ROOT_URL . "groups_to_tags",
        ['headers' => $REQUEST_HEADERS]);
    $groups_to_tags = json_decode(wp_remote_retrieve_body($groups_to_tags));
    $relevant_groups_to_tags = array_filter($groups_to_tags, function($x)
                    {return in_array($relevant_tag_id, $x);});

    $relevant_cs_group_ids = array_keys($relevant_groups_to_tags);

    $relevant_wp_posts_ids = get_posts([
                            'fields'=>'ids',
                            'category'=>get_cat_ID($POST_CATEGORY)]);
    
    $wp_post_id_to_cs_group_id = array_map(function($x)
                            {return $x=>get_post_meta($x, 'cg_cs_group_id',
                                TRUE);},
                            $relevant_wp_post_ids);
    $wp_posts_to_remove = array_diff($wp_post_id_to_cs_group_id,
                        $relevant_cs_group_ids);
    foreach($wp_posts_to_remove as $post_id => $_) {
        wp_delete_post($post_id);
    }
    
    $cs_group_id_to_wp_post_id = array_flip($wp_post_id_to_cs_group_id);
    foreach($relevant_cs_group_ids $cs_group_id) {
        $group = wp_remote_get(CSAPI_ROOT_URL . 'group/'.$cs_group_id);
        if(is_wp_error($group) throw new exception("Failed to submit request");
        if(wp_remote_retrieve_response_code($group)!=200)
            throw new Exception("invalid response, check request");
        $group = json_decode(wp_remote_retrieve_body($group));

        $post_data = [
            'ID' => $cs_group_id_to_wp_post_id[$cs_group_id] ?? 0,
            'post_title' => $group["name"],
            'post_status' => 'publish',
            'post_author' => 1,
            'post_category' => [get_cat_ID($POST_CATEGORY)],
            'meta_input' => [
                'cg_description' => $group["description"],
                'cg_group_leaders' => $group["custom_fields"]["field85"]["value"],
                'cg_date_start_to_end' => 'TODO dates',
                'cg_day_and_time' => 'TODO day and time',
                'cg_location' => $group["location"]["name"],
                'cg_minimum_age' => $group["custom_fields"]["field81"]["value"],
                'cg_objective' => $group["custom_fields"]["field79"]["value"],
                'cg_signup_capacity' => $group["signup_capacity"],
                'cg_cs_group_id' => $group["id"]
            ]
        ]
        wp_insert_post($post_data);
    }
    

}
