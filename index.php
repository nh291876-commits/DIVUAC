<?php

// --- תצורה ראשית - יש לערוך ---

// הגדר את הטוקן שלך (מספר מערכת:סיסמה)
define('YEMOT_TOKEN', '0733181406:80809090'); 

// הגדר את כתובת ה-API למפתחים
define('YEMOT_API_URL', 'https://www.call2all.co.il/ym/api/');

// הגדרות שלוחות
define('SOURCE_EXTENSIONS', ['11', '90', '97', '94', '988', '9999']);
define('DEST_REGULAR', '8000');
define('DEST_URGENT_A', '88'); // שלוחת טיפול
define('DEST_URGENT_B', '85'); // שלוחת תיעוד (ודא שהיא קיימת!)

// הגדרות צינתוק
define('TZINTUK_LIST_NAME', '2020');
define('TZINTUK_CALLER_ID', '0733181406');

define('DB_FILE', 'file_mappings.json');
define('DEBUG_LOG_FILE', 'debug_tzintuk_log.txt'); // [חדש] קובץ לוג לצינתוק

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


// --- פונקציה לפעולה מושהית ---
/**
 * פונקציה זו תתבצע "מאחורי הקלעים" אחרי שהמשתמש כבר קיבל תשובה וניתק.
 * היא ממתינה 60 שניות ואז מבצעת את ההעברות.
 */
function handle_urgent_report($source_path, $dest_path_a, $dest_path_b) {
    // התעלם מניתוק המשתמש והמשך לרוץ
    ignore_user_abort(true);
    
    // [שוחזר] המתנה של 60 שניות (כפי שרצית)
    sleep(60);

    // 1. העתקה ל-88 (החשוב ביותר)
    $api_params_move_copy = ['action' => 'copy', 'what' => $source_path, 'target' => $dest_path_a];
    $api_response_move_copy = call_yemot_api('FileAction', $api_params_move_copy);

    if ($api_response_move_copy && $api_response_move_copy['responseStatus'] == 'OK') {
        
        // 2. העתקה ל-85 (תיעוד)
        call_yemot_api('FileAction', ['action' => 'copy', 'what' => $source_path, 'target' => $dest_path_b]);

        // 3. מחיקת המקור (רק אחרי שהעתקנו לשני היעדים)
        call_yemot_api('FileAction', ['action' => 'delete', 'what' => $source_path]);

        // 4. שמירת מיפוי לשחזור (לפי 88)
        $mappings = load_mappings();
        add_mapping($mappings, $dest_path_a, $source_path);
        save_mappings($mappings);
        
        // --- [תוספת צינתוק עם לוג] ---
        // 5. שליחת צינתוק למנהלים
        $tzintuk_params = [
            'listName' => TZINTUK_LIST_NAME,
            'callerId' => TZINTUK_CALLER_ID
        ];
        $tzintuk_response = call_yemot_api('RunTzintuk', $tzintuk_params);

        // [חדש] כתיבת התגובה של ימות ללוג
        $log_entry = "Time: " . date('Y-m-d H:i:s') . "\n";
        $log_entry .= "Params Sent: " . json_encode($tzintuk_params) . "\n";
        $log_entry .= "Response Received: " . json_encode($tzintuk_response) . "\n\n";
        file_put_contents(DEBUG_LOG_FILE, $log_entry, FILE_APPEND);
        // --- [סוף תוספת] ---

    } else {
        // ... (רישום שגיאה אם ההעתקה ל-88 נכשלה) ...
        $log_entry = "Time: " . date('Y-m-d H:i:s') . "\n";
        $log_entry .= "ERROR: Copy to 88 failed. Tzintuk not sent.\n";
        $log_entry .= "Response Received: " . json_encode($api_response_move_copy) . "\n\n";
        file_put_contents(DEBUG_LOG_FILE, $log_entry, FILE_APPEND);
    }
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
                // שימוש בקובץ 050, ללא אישור
                $response_message = "read=f-050=report_type,no,1,1,7,No,yes,no,,1.2,,,,,no";
            
            } else {
                $file_name = basename($what);
                $source_path = $what;

                if ($report_type == '2') {
                    // --- דיווח רגיל (מיידי) ---
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
                    // --- דיווח חמור (מושהה עם ניתוק מיידי) ---
                    $dest_path_a = 'ivr2:/' . DEST_URGENT_A . '/' . $file_name;
                    $dest_path_b = 'ivr2:/' . DEST_URGENT_B . '/' . $file_name;

                    // 1. התחלת הניתוק המיידי
                    ob_start();
                    
                    // 2. שלח תשובה מיידית למשתמש
                    $response_message = "id_list_message=t-דיווח חמור התקבל ויטופל בדקה הקרובה";
                    echo $response_message;

                    // 3. קביעת כותרות לניתוק
                    header('Connection: close');
                    header('Content-Length: ' . ob_get_length());
                    ob_end_flush(); // שולח את כל מה שב-buffer (את ההודעה)
                    flush(); // מוודא שהכל נשלח ל-client (ימות המשיח)

                    
                    // 4. רשום את הפעולה הכבדה לביצוע אחרי שהשיחה תסתיים
                    register_shutdown_function('handle_urgent_report', $source_path, $dest_path_a, $dest_path_b);

                    // 5. מנע מהסקריפט הראשי לשלוח עוד 'echo' בסוף
                    $response_message = null;
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

// שלח את התשובה הסופית למשתמש
if ($response_message !== null) {
    echo $response_message;
}

// כאן הסקריפט הראשי מסתיים.
// אם נרשמה פונקציית כיבוי, היא תתחיל לרוץ עכשיו.
?>
