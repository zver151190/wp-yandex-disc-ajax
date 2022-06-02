<?php 

// If this file is called directly, abort. //
if ( ! defined( 'WPINC' ) ) {die;} // end if

define('API_URL','https://cloud-api.yandex.net/v1/disk/resources');

add_action( 'wp_ajax_ya_custom_plugin_frontend_ajax', 'ya_custom_plugin_frontend_ajax' );


function ya_custom_plugin_frontend_ajax(){  
    global $wpdb; 
    ob_start();

    $user_id = get_current_user_id();

    if(isset($_POST['action'])){
        $action = $_POST['func'];
        if($action == "getItems"){
            $path = $_POST['path'];
            $is_admin = false;
            if(isset($_POST['isAdmin'])) $is_admin = true;
            $items = getFolder($path,$is_admin);
            $prices = getAllPrices($user_id);
            foreach($items as $key => $item){
                if($item['type'] == 'dir'){
                    $images = $wpdb->get_results( "SELECT image FROM folder_images WHERE folder_id = '{$item['path']}'");
                    if(!count($images)){
                        $has = saveFolderImages($item['path']);
                        if($has){
                            $images = $wpdb->get_results( "SELECT image FROM folder_images WHERE folder_id = '{$item['path']}'");
                            $items[$key]['images'] = $images;
                        }
                    }else{
                        $items[$key]['images'] = $images;
                    }
                }
            }
            echo json_encode($items);
            exit;
        }
        $user_id = get_current_user_id();
        
        if($action == "generateLink"){
            $arr = $_POST['arr'];
            $token = openssl_random_pseudo_bytes(32);
            $token = bin2hex($token);
            saveToken($arr,$token);
            $link = generateLink($token);
            echo json_encode(array("status" => "success", "link" =>$link));
            exit;
        }
        
        if($action == "getCartCounter"){
            if($user_id > 0){
                $folder_id = $_POST['folder_id'];
                $items = $wpdb->get_results( "SELECT folder_id FROM cart WHERE user_id = '{$user_id}' AND status is null");
                $count = 0;
                $folders = [];
                foreach($items as $item){
                    $count++;
                    $folders[] = $item->folder_id;
                }
                echo json_encode(array("status" => "success", 'count' => $count,'items' => $folders));
            }else{
                echo json_encode(array("status" => "success", 'count' => 0));
            }
            exit;
        }
        
        if($action == "addAllToCart"){
            if($user_id > 0){
                $items = $_POST['items'];
                if( isset($_POST['add'])){
                    foreach($items as $item){
                        $wpdb->insert( 'cart', [ 'folder_id' => $item,'user_id' => $user_id, 'price' => 500 ] );
                    }
                    $count = $wpdb->get_row( "SELECT count(id) as count FROM cart WHERE user_id = '{$user_id}'");
                    echo json_encode(array("status" => "success","message" => "added","count" => $count->count));
                    exit;
                }else if(isset($_POST['remove'])){
                    $ids = implode("','", $items);
                    $rows_affected = $wpdb->query( "DELETE FROM cart WHERE folder_id IN('{$ids}') AND user_id = '{$user_id}'" );
                    $count = $wpdb->get_row( "SELECT count(id) as count FROM cart WHERE user_id = '{$user_id}'");
                    echo json_encode(array("status" => "success","message" => "removed","count" => $count->count));
                    exit;
                }
            }
            exit;
        }
        
        if($action == "addToCart"){
            if($user_id > 0){
                $folder_id = $_POST['path'];
                $items = $wpdb->get_results( "SELECT id  FROM cart WHERE user_id = '{$user_id}' and folder_id = '{$folder_id}'");
                if(count($items) > 0){
                    $wpdb->delete( 'cart', [ 'folder_id' => $folder_id,'user_id' => $user_id  ] );
                    $count = $wpdb->get_row( "SELECT count(id) as count FROM cart WHERE user_id = '{$user_id}'");
                    echo json_encode(array("status" => "success","message" => "removed","count" => $count->count));
                }else{
                    $wpdb->insert( 'cart', [ 'folder_id' => $folder_id,'user_id' => $user_id, 'price' => 500  ] );
                    $count = $wpdb->get_row( "SELECT count(id) as count FROM cart WHERE user_id = '{$user_id}'");
                    echo json_encode(array("status" => "success","message" => "added","count" => $count->count));
                }
            }else{
                echo json_encode(array("status" => "error", "message" => "please login"));
            }
            exit;
        }

        if($action == "download"){
            if($user_id > 0){
                $path = $_POST['path'];
            } else {
                echo json_encode(array("status" => "error", "message" => "please login"));
            }
            exit;
        }
        
        if($action == "checkout"){
            if($user_id > 0){
                //Get cart items and calculate SUM
                $items = $wpdb->get_results( "SELECT *  FROM cart WHERE user_id = '{$user_id}'");
                $sum = 0;
                foreach ($items as $item) {
                    $sum+=$item->price;
                }
                //CREATE TESTPAYMENT LINK REQUEST
                $url = "/payment-success/?orderId=70906e55-7114-41d6-8332-4609dc6590f4";
                $bank_id = '70906e55-7114-41d6-8332-4609dc6590f4';
                
                //SAVE PAYMENT LINK REQUEST ID AND CREATE ORDER
                $wpdb->insert( 'orders', ['bank_id'=>$bank_id, 'price' => $sum, 'user_id' => $user_id, 'status' => 'started'] );
                
                echo json_encode(array("status" => "success","url" => $url));
                exit;
            } else {
                echo json_encode(array("status" => "error", "message" => "please login"));
            }
            exit;
        }
    }
    wp_die();
}

function getAllPrices($user_id) {
    global $wpdb; 
    $folders = $wpdb->get_results(" SELECT a1.folder_id,a1.folder_price,a1.image_price
                                    FROM folders_prices as a1
                                    INNER Join users_tokens as a2
                                    ON a1.token_id = a2.token_id
                                    WHERE a2.user_id = {$user_id}");
    return $folders;
}

function getYandexData($params){
    $token = getToken();
    $ch = curl_init(API_URL.'?'.http_build_query($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: OAuth ' . $token));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HEADER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

function saveFolderImages($path){
    global $wpdb; 
    $params = array(
        'path' => urlencode($path)
    );
    $items = json_decode(getYandexData($params),1);
    if(isset($items['_embedded'])){
        foreach($items['_embedded']['items'] as $item){
            if(isset($item['type'])){
                if($item['type'] == "file"){
                    $src = $item['path'];
                    $wpdb->insert( 'folder_images', [ 'folder_id' => $path, 'image' =>  $src] );
                    return true;
                }
            }
        }
    }
    return true;
}

function saveToken($arr,$token){
    global $wpdb; 
    $wpdb->insert( 'tokens', [ 'token' => $token ] );
    $lastid = $wpdb->insert_id;
    foreach($arr as $item){
        $wpdb->insert( 'tokens_folders', [ 'token_id' => $lastid,'folder_id' => $item['id'] ] );        
        if(isset($item['folder_price'])){
            $wpdb->insert( 'folders_prices', [ 'token_id' => $lastid,'folder_id' => $item['id'], 'folder_price' => $item['folder_price'], 'image_price' => $item['image_price'] ] );
        }
        else $wpdb->insert( 'folders_prices', [ 'token_id' => $lastid,'folder_id' => $item['id'], 'folder_price' => 0, 'image_price' => $item['image_price'] ] );
    }
}

function getToken(){
    return $token = '-----------------------------';
}

function getRootFolders(){
    global $wpdb;
    $user_id = get_current_user_id();
    $folders = $wpdb->get_results( "SELECT folder_id FROM users_folders WHERE user_id = '{$user_id}'");
    $folders_arr = [];
    foreach($folders as $folder){
        $params = array(
            'path' => urlencode($folder->folder_id)
        );
        $items = json_decode(getYandexData($params),1);
        $folders_arr[] = $items;
    }
    return $folders_arr;
}

function getFolder($path='/',$is_admin=false){
    global $wpdb;
    $text = "";
    $user_id = get_current_user_id();
    $folders = $wpdb->get_results( "SELECT folder_id FROM users_folders WHERE user_id = '{$user_id}'");
    $folders_arr = [];
    $pass = false;
    foreach($folders as $folder){
        if(strpos($path,$folder->folder_id."/") !== false){
            $pass = true;
        }
        if($path == $folder->folder_id){
           $pass = true;
        }
    }
    if(!$is_admin){
        if($path == "/" || !$pass) return getRootFolders();
    }
    $token = getToken();
    $fields = '_embedded.items.name,_embedded.items.type,_embedded.items.path,_embedded.items.preview,_embedded.items.public_key';
    $limit = 100;
    if($flat){
        $params = array(
            'path' => urlencode($path)
        );
    }else{
        $params = array(
            'path' => urlencode($path),
            'fields' => $fields,
            'limit' => $limit
        );
    }
    $items = json_decode(getYandexData($params),1);
    if(isset($items['_embedded'])){
        return $items['_embedded']['items'];
    }
    return [];
}

function generateLink($token){
    $site = get_site_url();
    return $site."/welcome?token={$token}";
}