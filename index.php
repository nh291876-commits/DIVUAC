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


// --- [חדש] פונקציה לפעולה מושהית (דיווח חמור) ---
function handle_urgent_report($source_path, $dest_path_a, $dest_path_b) {
    ignore_user_abort(true);
    sleep(60); // המתנה של 60 שניות

    // 1. העתקה ל-88 (החשוב ביותר)
    $api_params_move_copy = ['action' => 'copy', 'what' => $source_path, 'target' => $dest_path_a];
    $api_response_move_copy = call_yemot_api('FileAction', $api_params_move_copy);

    if ($api_response_move_copy && $api_response_move_copy['responseStatus'] == 'OK') {
        // 2. העתקה ל-85 (תיעוד)
        call_yemot_api('FileAction', ['action' => 'copy', 'what' => $source_path, 'target' => $dest_path_b]);
        // 3. מחיקת המקור
        call_yemot_api('FileAction', ['action' => 'delete', 'what' => $source_path]);
        // 4. שמירת מיפוי
        $mappings = load_mappings();
        add_mapping($mappings, $dest_path_a, $source_path);
        save_mappings($mappings);
    }
}

// --- [חדש] פונקציה לפעולה מושהית (שחזור) ---
function handle_restore($dest_path_a) { // מקבלת רק את הנתיב לשחזור
    ignore_user_abort(true);
    
    // --- לוגיקה שהועברה מה-CASE ---
    $mappings = load_mappings(); // קורא את הקובץ ברקע
    $source_path = find_source($mappings, $dest_path_a); // מחפש ברקע
    
    if (!$source_path) {
        // אין למי להחזיר תשובה, אבל אפשר לרשום לוג שגיאה בצד השרת
        // error_log("Restore failed: source not found for $dest_path_a");
        return; // הפסקת הפונקציה
    }
    // --- סוף הלוגיקה שהועברה ---

    // 1. מחיקת הקובץ מהמקור (לפנות מקום)
    call_yemot_api('FileAction', ['action' => 'delete', 'what' => $source_path]);

    // 2. העתקה חזרה למקור
    $api_params_restore = ['action' => 'copy', 'what' => $dest_path_a, 'target' => $source_path];
    $resp = call_yemot_api('FileAction', $api_params_restore);

    if ($resp && $resp['responseStatus'] == 'OK') {
        // 3. מחיקה משלוחת הטיפול (88)
        call_yemot_api('FileAction', ['action' => 'delete', 'what' => $dest_path_a]);
        
        // 4. עדכון המיפוי (טוען מחדש למניעת התנגשות)
        $mappings = load_mappings();
        remove_mapping($mappings, $dest_path_a);
        save_mappings($mappings);
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
                        $response_message = "id_list_message=t-הדיווח הרגיל התקבל&go_to_folder=/800/61";
                    } else {
                        $err = $api_response['message'] ?? 'שגיאת תקשורת';
                        $response_message = "id_list_message=t-שגיאה בהעתקה: " . $err;
                    }

                } elseif ($report_type == '1') {
                    // --- דיווח חמור (מושהה) ---
                    $dest_path_a = 'ivr2:/' . DEST_URGENT_A . '/' . $file_name;
                    $dest_path_b = 'ivr2:/' . DEST_URGENT_B . '/' . $file_name;

                    ob_start();
                    $response_message = "id_list_message=t-בוצע&go_to_folder=/800/60";
                    echo $response_message;
                    header('Connection: close');
                    header('Content-Length: ' . ob_get_length());
                    ob_end_flush();
                    flush();
                    
                    register_shutdown_function('handle_urgent_report', $source_path, $dest_path_a, $dest_path_b);
                    $response_message = null;
                }
            }
            break;

        case 'delete_regular': // מחיקה מ-8000
            // זו עדיין פעולה איטית שעלולה לגרום ל-Timeout
            // אם היא גורמת לבעיות, נצטרך להעביר גם אותה ל-register_shutdown_function
            $dest_path = $what;
            $mappings = load_mappings();
            $source_path = find_source($mappings, $dest_path);

            $api_params_dest = ['action' => 'delete', 'what' => $dest_path];
            $api_response_dest = call_yemot_api('FileAction', $api_params_dest);
            
            if ($api_response_dest && $api_response_dest['responseStatus'] == 'OK') {
                if ($source_path) {
                    $api_params_source = ['action' => 'delete', 'what' => $source_path];
                    call_yemot_api('FileAction', $api_params_source); // קריאה שנייה
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
            $dest_path_a = $what; // פעולה מהירה
            
            // כל הלוגיקה של קריאת הקובץ וחיפוש המקור הועברה לפונקציית הרקע
            // $mappings = load_mappings();
            // $source_path = find_source($mappings, $dest_path_a);
            // if (!$source_path) { ... }

            // --- תיקון: שימוש ב-Shutdown Function ---
            
            // 1. שלח תשובה מיידית (תשובה אופטימית)
            ob_start();
            $response_message = "id_list_message=t-הקובץ שוחזר בהצלחה";
            echo $response_message;
            
            // 2. נתק את המשתמש
            header('Connection: close');
            header('Content-Length: ' . ob_get_length());
            ob_end_flush();
            flush();

            // 3. בצע את העבודה הכבדה ברקע
            register_shutdown_function('handle_restore', $dest_path_a); // מעביר רק את מה שצריך

            // 4. מנע הדפסה כפולה
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
