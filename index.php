<?php

// --- תצורה ראשית ---
define('YEMOT_TOKEN', '0733181406:80809090');
define('YEMOT_API_URL', 'https://www.call2all.co.il/ym/api/');

define('DEST_REGULAR', '8000');
define('DEST_URGENT_A', '88');
// שלוחה 85 הוסרה

define('DB_FILE', 'file_mappings.json');

// --- פונקציות עזר ---

function load_mappings() {
    if (!file_exists(DB_FILE)) return [];
    $data = file_get_contents(DB_FILE);
    return json_decode($data, true) ?: [];
}

function save_mappings($mappings) {
    file_put_contents(DB_FILE, json_encode($mappings, JSON_PRETTY_PRINT));
}

function add_mapping(&$mappings, $dest_path, $source_path) {
    $mappings[$dest_path] = $source_path;
}

function find_source($mappings, $dest_path) {
    return isset($mappings[$dest_path]) ? $mappings[$dest_path] : null;
}

function remove_mapping(&$mappings, $dest_path) {
    if (isset($mappings[$dest_path])) unset($mappings[$dest_path]);
}

function call_yemot_api($method, $params) {
    $url = YEMOT_API_URL . $method;
    $params['token'] = YEMOT_TOKEN;
    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($params),
            'ignore_errors' => true
        ],
    ];
    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    if ($result === FALSE) return null;
    return json_decode($result, true);
}

// --- פונקציות רקע ---

function handle_urgent_report($source_path, $dest_path_a) {
    ignore_user_abort(true);
    sleep(60); 

    // 1. העתקה לשלוחה 88
    $api_params_move_copy = ['action' => 'copy', 'what' => $source_path, 'target' => $dest_path_a];
    $api_response_move_copy = call_yemot_api('FileAction', $api_params_move_copy);

    if ($api_response_move_copy && $api_response_move_copy['responseStatus'] == 'OK') {
        
        // 2. מחיקת המקור
        call_yemot_api('FileAction', ['action' => 'delete', 'what' => $source_path]);
        
        // 3. שמירת מיפוי
        $mappings = load_mappings();
        add_mapping($mappings, $dest_path_a, $source_path);
        save_mappings($mappings);
    }
}

function handle_restore_fast($dest_path_a) {
    ignore_user_abort(true);
    set_time_limit(0);

    // 1. שליפת נתיב המקור
    $mappings = load_mappings();
    $source_path = find_source($mappings, $dest_path_a);
    
    if (!$source_path) return;

    // 2. מחיקת המקור
    call_yemot_api('FileAction', ['action' => 'delete', 'what' => $source_path]);

    // 3. העתקה מהארכיון למקור
    $res = call_yemot_api('FileAction', ['action' => 'copy', 'what' => $dest_path_a, 'target' => $source_path]);

    // 4. אם הצליח - מחיקת הארכיון ועדכון מיפוי
    if ($res && $res['responseStatus'] == 'OK') {
        call_yemot_api('FileAction', ['action' => 'delete', 'what' => $dest_path_a]);
        remove_mapping($mappings, $dest_path_a);
        save_mappings($mappings);
    }
}

// --- לוגיקה ראשית ---

header('Content-Type: text/html; charset=utf-8');
$params = $_REQUEST;

// [תוספת ל-Cron Job] בדיקת דופק כדי להשאיר את השרת ער
if (isset($params['action']) && $params['action'] === 'ping') {
    echo "OK - I am awake";
    exit;
}

$what = $params['what'] ?? null;
$action = $params['action'] ?? null;
$report_type = $params['report_type'] ?? null;

if (!$what || !$action) {
    echo "id_list_message=t-שגיאה חסרים נתונים";
    exit;
}

$response_message = "id_list_message=t-שגיאה כללית";

try {
    switch ($action) {
        case 'ask_report_type':
            if (empty($report_type)) {
                // שימוש ב-TTS במקום קובץ 050
                $response_message = "read=t-אם מדובר בתוכן בעייתי ברמה חמורה ביותר הַקֵּשׁ 1, לדיווח רגיל הַקֵּשׁ 2=report_type,no,1,1,7,No,yes,no,,1.2,,,,,no";
            } else {
                $file_name = basename($what);
                $source_path = $what;

                if ($report_type == '2') {
                    $dest_path = 'ivr2:/' . DEST_REGULAR . '/' . $file_name;
                    $api_params = ['action' => 'copy', 'what' => $source_path, 'target' => $dest_path];
                    $api_response = call_yemot_api('FileAction', $api_params);

                    if ($api_response && $api_response['responseStatus'] == 'OK') {
                        $mappings = load_mappings();
                        add_mapping($mappings, $dest_path, $source_path);
                        save_mappings($mappings);
                        $response_message = "id_list_message=t-הדיווח הרגיל התקבל&go_to_folder=/800/61";
                    } else {
                        $err = $api_response['message'] ?? 'שגיאת תקשורת';
                        $response_message = "id_list_message=t-שגיאה בהעתקה: " . $err;
                    }

                } elseif ($report_type == '1') {
                    $dest_path_a = 'ivr2:/' . DEST_URGENT_A . '/' . $file_name;

                    ob_start();
                    $response_message = "id_list_message=t-בוצע&go_to_folder=/800/60";
                    echo $response_message;
                    header('Connection: close');
                    header('Content-Length: ' . ob_get_length());
                    ob_end_flush();
                    flush();
                    
                    register_shutdown_function('handle_urgent_report', $source_path, $dest_path_a);
                    $response_message = null;
                }
            }
            break;

        case 'delete_regular':
            $dest_path = $what;
            $mappings = load_mappings();
            $source_path = find_source($mappings, $dest_path);

            $api_params_dest = ['action' => 'delete', 'what' => $dest_path];
            $api_response_dest = call_yemot_api('FileAction', $api_params_dest);
            
            if ($api_response_dest && $api_response_dest['responseStatus'] == 'OK') {
                if ($source_path) {
                    call_yemot_api('FileAction', ['action' => 'delete', 'what' => $source_path]);
                    $response_message = "id_list_message=t-נמחק מהארכיון ומהמקור";
                } else {
                    $response_message = "id_list_message=t-נמחק מהארכיון בלבד";
                }
                remove_mapping($mappings, $dest_path);
                save_mappings($mappings);
            } else {
                $response_message = "id_list_message=t-שגיאה במחיקה";
            }
            break;

        case 'restore_urgent': 
            $dest_path_a = $what;
            
            ob_start();
            $response_message = "id_list_message=t-הקובץ שוחזר בהצלחה";
            echo $response_message;
            
            header('Connection: close');
            header('Content-Length: ' . ob_get_length());
            ob_end_flush();
            flush();

            register_shutdown_function('handle_restore_fast', $dest_path_a);

            $response_message = null;
            break;
    }

} catch (Exception $e) {
    $response_message = "id_list_message=t-שגיאת שרת קריטית";
}

if ($response_message !== null) {
    echo $response_message;
}
?>
