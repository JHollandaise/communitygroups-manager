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


function cg_update_posts() {
    $REQUEST_HEADERS = [];
    $RELEVANT_CS_TAG = "CG2021-2";
    $POST_CATEGORY = "Community Groups";

    $x_headers = json_decode(file_get_contents(dirname(__FILE__) . "/x_headers.json"),
                    true);
    foreach(array("X-Account", "X-Application", "X-Auth") as $header_name) {
        if(!array_key_exists($header_name, $x_headers)) {
            throw new Exception(
                "Missing $header_name in x_headers.json");
        }
    }
    
    $REQUEST_HEADERS =  array_merge($x_headers,
        ["Content-Type" => "application/json"]);

    
    // --- GET RELEVANT GROUP/POST IDS FROM CHURCHSUITE ---
    $tags = remote_get_cached(CSAPI_ROOT_URL . "tags", $REQUEST_HEADERS, 600);

    if(is_wp_error($tags)) throw new Exception("Failed to submit request");
    $response_code = wp_remote_retrieve_response_code($tags); 
    if($response_code != 200)
        throw new Exception("bad response code: " . $response_code . " with x_headers: " . json_encode($REQUEST_HEADERS));

    $tags = json_decode(wp_remote_retrieve_body($tags),TRUE)["tags"];
    $tag_name_to_id = array_reduce($tags, function($result,$x)
                    {$result[$x["name"]]=$x["id"];
                    return $result;}, []);
    if (!array_key_exists($RELEVANT_CS_TAG, $tag_name_to_id))
        throw new Exception("invalid RELEVANT_CS_TAG: ". $RELEVANT_CS_TAG);
    $relevant_tag_id = $tag_name_to_id[$RELEVANT_CS_TAG];
    $groups_to_tags = remote_get_cached(CSAPI_ROOT_URL . "groups_to_tags",
        $REQUEST_HEADERS,600);
    $groups_to_tags = json_decode(wp_remote_retrieve_body($groups_to_tags),TRUE);
    $relevant_groups_to_tags = array_filter($groups_to_tags, function($x) use ($relevant_tag_id)
                            {return in_array($relevant_tag_id, $x);});
    // Seeing group[id]==0 for some reason, remove it here
    unset($relevant_groups_to_tags["0"]);

    $relevant_cs_group_ids = array_keys($relevant_groups_to_tags);
    // relevant posts are those under the category $POST_CATEGORY
    $relevant_wp_posts_ids = get_posts([
                            'numberposts'=>'50',
                            'fields'=>'ids',
                            'category'=>get_cat_ID($POST_CATEGORY)]);
    $wp_post_id_to_cs_group_id = array_reduce($relevant_wp_posts_ids,
                            function($result, $x)
                            {$result[$x]=get_post_meta($x, 'cg_cs_group_id',
                                TRUE);
                            return $result;},[]);
    // ----------------------------------------------------

    $wp_posts_to_remove = array_diff($wp_post_id_to_cs_group_id,
                        $relevant_cs_group_ids);
    foreach($wp_posts_to_remove as $post_id => $_) {
        wp_delete_post($post_id);
    }
    $cs_group_id_to_wp_post_id = array_flip($wp_post_id_to_cs_group_id);
    foreach($relevant_cs_group_ids as $cs_group_id) {
        $group = remote_get_cached(CSAPI_ROOT_URL . 'group/'.$cs_group_id,
              $REQUEST_HEADERS,600);
        if(is_wp_error($group)) throw new Exception("Failed to submit request: group/". $cs_group_id);
        $response_code = wp_remote_retrieve_response_code($group);
        if($response_code!=200)
            throw new Exception("bad response code: " . $response_code);
        $group = json_decode(wp_remote_retrieve_body($group),TRUE);


        $days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday",
                "Saturday", "Sunday"];
        $day_and_time = $group["frequency"] . " on " .
                $days[intval($group["day"])-1] . ($group["time"]!=""?" at " . $group["time"]:"");
        // edge-case for custom event frequency (JH - 14/12/21)
        if ($group["frequency"]=="custom") $day_and_time = $group["custom_frequency"];
        if($group["signup_capacity"]=="") $group["signup_capacity"]="N/A";
        $post_id = $cs_group_id_to_wp_post_id[$cs_group_id] ?? 0;
        $post_data = [
            'ID' => $post_id,
            'post_title' => $group["name"],
            'post_status' => 'publish',
            'post_author' => 1,
            'post_category' => [get_cat_ID($POST_CATEGORY)],
            'meta_input' => [
                'cg_description' => $group["description"],
                'cg_group_leaders' => $group["custom_fields"]["field85"]["value"],
                'cg_date_start_to_end' => 'TODO dates',
                'cg_day_and_time' => $day_and_time,
                'cg_location' => $group["location"]["name"],
                'cg_minimum_age' => $group["custom_fields"]["field81"]["value"],
                'cg_objective' => $group["custom_fields"]["field79"]["value"],
                'cg_signup_capacity' => $group["signup_capacity"],
                'cg_cs_group_id' => $group["id"],
                'cg_signup_url' => "https://allnations.churchsuite.co.uk/groups/" . $group["identifier"],
                '_knawatfibu_url' => $group["images"]["lg"]["url"]
            ]
        ];

        // hash $post_data and save as meta_input for difference evaluation

        $post_data_hash = hash("md5", serialize($post_data));
        $post_data['meta_input']['post_data_hash'] = $post_data_hash;

        // if we see a difference in post_data, then update
        if(get_post_meta($post_id, 'post_data_hash', true) != $post_data_hash)
            
            wp_insert_post($post_data);

    }
    

}
add_action('init','cg_update_posts');

function remote_get_cached(string $url, array $headers, int $expiry) {
    // TODO: add appropriate exception handling at this scope
    if ($response = get_transient("GET: " . $url) and $expiry!=0) return $response;
    $response = wp_remote_get($url, ['headers'=>$headers]);
    set_transient("GET: " . $url, $response, $expiry);
    return $response;
}
