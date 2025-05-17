<?php

/*
	Plugin Name: PCP Finder
	Plugin URI:
	Description: Allows users to search by address for their Precinct Committee Person
	Version: 0.2
	Author: Marty Sloan
	Author URI: https://petersrepublicans.com
	License: GPLv2
*/

if (!function_exists('write_log')) {

    function write_log($log) {
        if (true === WP_DEBUG) {
            if (is_array($log) || is_object($log)) {
                error_log(print_r($log, true));
            } else {
                error_log($log);
            }
        }
    }

}

// Register plugin
add_shortcode( 'ptrc_pcp_finder', 'ptrc_pcp_finder' );

// Load Alpine.js component for PCP search
function ptrc_pcp_finder($atts): bool|string
{
    ob_start();
    include dirname( __FILE__ ) . '/index.html';
    return ob_get_clean();
}

// Register REST API endpoint for PCP searches
add_action('rest_api_init', function () {
    register_rest_route( 'ptrc/v1', '/pcp-search', [
        'methods' => 'GET',
        'callback' => 'ptrc_search_pcp',
    ]);
    register_rest_route( 'ptrc/v1', '/pcp-address-details', [
        'methods' => 'GET',
        'callback' => 'ptrc_pcp_address_details',
    ]);
} );

function ptrc_search_pcp(WP_REST_Request $request): WP_REST_Response
{
    $address = $request['address'];
    $endpoint = 'https://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/suggest' .
        '?f=json' .
        "&text=$address" .
        '&maxSuggestions=10' .
        '&location={"x":-8925596.771804526,"y":4905995.34432425,"spatialReference":{"wkid":102100,"latestWkid":3857}}' .
        '&distance=50000';
    $data = json_decode(wp_remote_get($endpoint)['body'], true);
    $response = new WP_REST_Response($data['suggestions']);
    $response->set_status( 201 );
    return $response;
}

function ptrc_pcp_address_details(WP_REST_Request $request): WP_REST_Response
{
    $location = $request['location'];
    $magicKey = $request['magicKey'];
    $endpoint = 'https://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/findAddressCandidates' .
        "?SingleLine=$location,USA" .
        '&f=json' .
        '&outSR={"wkid":102100,"latestWkid":3857}' .
        '&outFields=Addr_type,Match_addr,StAddr,City' .
        "&magicKey=$magicKey" .
        '&distance=50000&location={"x":-8925596.771804526,"y":4905995.34432425,"spatialReference":{"wkid":102100,"latestWkid":3857}}' .
        '&maxLocations=6';
    $data = json_decode(wp_remote_get($endpoint)['body'], true);
    $location = $data['candidates'][0]['location'] ?? null;

    if(is_null($location)) {
        return_no_results();
    }

    $endpoint = 'https://services2.arcgis.com/LgK9DpUhNjdU0HLy/arcgis/rest/services/Elections/FeatureServer/1/query' .
        '?f=json' .
        '&where=' .
        '&returnGeometry=false' .
        '&spatialRel=esriSpatialRelIntersects' .
        '&geometry={"x":' . $location['x'] . ',"y":' . $location['y'] . ',"spatialReference":{"wkid":102100,"latestWkid":3857}}' .
        '&geometryType=esriGeometryPoint' .
        '&inSR=102100' .
        '&outFields=*' .
        '&outSR=102100';

    $data = json_decode(wp_remote_get($endpoint)['body'], true);
    $precinct = $data['features'][0]['attributes'] ?? [];

    if(empty($precinct) || count(str_split($precinct['district_number'])) !== 2) {
        return_no_results();
    }

    $pcpTable = include(dirname( __FILE__ ) . '/committeePersons.php');
    $precinct['pcp'] = $pcpTable[$precinct['district_number']];

    $response = new WP_REST_Response($precinct);
    $response->set_status( 201 );
    return $response;
}

function return_no_results(): WP_REST_Response
{
    $response = new WP_REST_Response([]);
    $response->set_status( 201 );
    return $response;
}
