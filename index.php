<?php

// --- תצורה ראשית - יש לערוך ---

// הגדר את הטוקן שלך (מספר מערכת:סיסמה)
define('YEMOT_TOKEN', '0733181406:80809090'); // ודא שהסיסמה נכונה!

// הגדר את כתובת ה-API למפתחים
define('YEMOT_API_URL', 'https://www.call2all.co.il/ym/api/');

// הגדרות שלוחות
define('SOURCE_EXTENSIONS', ['11', '90', '97', '94', '988', '9999']);
define('DEST_REGULAR', '8000');
define('DEST_URGENT_A', '88'); // ודא ששלוחה זו קיימת!
define('DEST_URGENT_B', '85'); // ודא ששלוחה זו קיימת!

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

// --- לוגיקה ראשית ---

header('Content-Type: text/html; charset=utf-8');
$params = $_REQUEST;
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
                // תיקון קריטי: סימן שווה אחרי הטקסט
                $response_message = "read=t-אם זה דיווח חמור הקש 1 ואם דיווח רגיל הקש 2=report_type,no,,1,1,Digits,yes,yes,*/,1.2";
            } else {
                $file_name = basename($what);
                $source_path = $what;

                if ($report_type == '2') {
                    // דיווח רגיל
                    $dest_path = 'ivr2:/' . DEST_REGULAR . '/' . $file_name;
                    $api_params = ['action' => 'copy', 'what' => $source_path, 'target' => $dest_path];
                    $api_response = call_yemot_api('FileAction', $api_params);

                    if ($api_response && $api_response['responseStatus'] == 'OK') {
                        $mappings = load_mappings();
                        add_mapping($mappings, $dest_path, $source_path);
                        save_mappings($mappings);
                        $response_message = "id_list_message=t-הדיווח הרגיל התקבל";
                    } else {
                        $err = $api_response['message'] ?? 'שגיאת תקשורת';
                        $response_message = "id_list_message=t-שגיאה בהעתקה: " . $err;
                    }

                } elseif ($report_type == '1') {
                    // דיווח חמור
                    $dest_path_a = 'ivr2:/' . DEST_URGENT_A . '/' . $file_name;
                    $dest_path_b = 'ivr2:/' . DEST_URGENT_B . '/' . $file_name;

                    // נסיון העתקה לשלוחה 88
                    $api_params_move_copy = ['action' => 'copy', 'what' => $source_path, 'target' => $dest_path_a];
                    $api_response_move_copy = call_yemot_api('FileAction', $api_params_move_copy);

                    if ($api_response_move_copy && $api_response_move_copy['responseStatus'] == 'OK') {
                        // העתקה הצליחה -> מוחקים את המקור
                        $api_params_move_delete = ['action' => 'delete', 'what' => $source_path];
                        call_yemot_api('FileAction', $api_params_move_delete);

                        // מעתיקים לתיעוד (85)
                        $api_params_log = ['action' => 'copy', 'what' => $source_path, 'target' => $dest_path_b];
                        call_yemot_api('FileAction', $api_params_log);

                        $mappings = load_mappings();
                        add_mapping($mappings, $dest_path_a, $source_path);
                        save_mappings($mappings);
                        
                        $response_message = "id_list_message=t-דיווח חמור טופל והקובץ הוסר";
                    } else {
                        // כאן אנחנו תופסים את השגיאה!
                        $err = $api_response_move_copy['message'] ?? 'לא ידוע';
                        $response_message = "id_list_message=t-שגיאה בהעברה לשלוחה 88: " . $err;
                    }
                }
            }
            break;

        case 'delete_regular': // מחיקה מ-8000
            $dest_path = $what;
            $mappings = load_mappings();
            $source_path = find_source($mappings, $dest_path);

            $api_params_dest = ['action' => 'delete', 'what' => $dest_path];
            $api_response_dest = call_yemot_api('FileAction', $api_params_dest);
            
            if ($api_response_dest && $api_response_dest['responseStatus'] == 'OK') {
                if ($source_path) {
                    $api_params_source = ['action' => 'delete', 'what' => $source_path];
                    call_yemot_api('FileAction', $api_params_source);
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

        case 'restore_urgent': // שחזור מ-88
            $dest_path_a = $what;
            $mappings = load_mappings();
            $source_path = find_source($mappings, $dest_path_a);

            if (!$source_path) {
                $response_message = "id_list_message=t-מקור לא נמצא";
                break;
            }

            $api_params_restore = ['action' => 'copy', 'what' => $dest_path_a, 'target' => $source_path];
            $resp = call_yemot_api('FileAction', $api_params_restore);

            if ($resp && $resp['responseStatus'] == 'OK') {
                call_yemot_api('FileAction', ['action' => 'delete', 'what' => $dest_path_a]);
                remove_mapping($mappings, $dest_path_a);
                save_mappings($mappings);
                $response_message = "id_list_message=t-הקובץ שוחזר בהצלחה";
            } else {
                $err = $resp['message'] ?? '';
                $response_message = "id_list_message=t-שגיאה בשחזור: " . $err;
            }
            break;
    }

} catch (Exception $e) {
    $response_message = "id_list_message=t-שגיאת שרת קריטית";
}

echo $response_message;
?>
