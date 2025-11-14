<?php

// --- תצורה ראשית - יש לערוך ---

// הגדר את הטוקן שלך (מספר מערכת:סיסמה)
define('YEMOT_TOKEN', '0733181406:80809090'); 

// הגדר את כתובת ה-API למפתחים
define('YEMOT_API_URL', 'https://www.call2all.co.il/ym/api/');

// --- [שדרוג 1] ---
// הגדר את שלוחות המקור
define('SOURCE_EXTENSIONS', [
    '11',
    '90', 
    '97', 
    '94', 
    '988', 
    '9999'
]);

// הגדר את שלוחת היעד (רק אחת)
define('DEST_EXTENSION', '800/54');

// --- [בעיה פוטנציאלית 1] ---
// הגדר נתיב *עם הרשאות כתיבה* לקובץ מסד הנתונים
// בשרת Render, זה *חייב* להיות נתיב לדיסק קשיח (Persistent Disk)
// לדוגמה: '/var/data/' או 'C:/temp/' (במחשב מקומי)
// שנה את זה לנתיב הנכון בשרת שלך!
define('DB_WRITE_PATH', './'); // ברירת מחדל היא התיקייה הנוכחית (כנראה לא יעבוד ב-Render)

// קובץ מסד נתונים למיפוי קבצים
define('DB_FILE', DB_WRITE_PATH . 'file_mappings.json');


// --- [שדרוג 2] ---
// הגדרות תגובה
define('RESPONSE_ON_COPY_SUCCESS', 'id_list_message=t-הקובץ הועתק בהצלחה');
define('RESPONSE_ON_DELETE_SUCCESS', 'id_list_message=t-הקובץ נמחק בהצלחה');
define('RESPONSE_ON_DELETE_PARTIAL_ERROR', 'id_list_message=t-שגיאה במציאת הקובץ המקורי');
define('RESPONSE_ON_DELETE_NO_SOURCE', 'id_list_message=t-הקובץ בשלוחת המקור לא נמצא');

// --- סוף תצורה ---


// --- פונקציות עזר למסד נתונים (JSON) ---

function load_mappings() {
    if (!file_exists(DB_FILE)) {
        return [];
    }
    $data = file_get_contents(DB_FILE);
    return json_decode($data, true) ?: [];
}

function save_mappings($mappings) {
    // נסיון כתיבה לקובץ - זה עלול להיכשל ב-Render אם הנתיב לא נכון
    $result = file_put_contents(DB_FILE, json_encode($mappings, JSON_PRETTY_PRINT));
    if ($result === false) {
        // אם הכתיבה נכשלה, זרוק שגיאה שנתפוס למעלה
        throw new Exception("Failed to write to DB file: " . DB_FILE);
    }
}

function add_mapping(&$mappings, $dest_path, $source_path) {
    $mappings[$dest_path] = $source_path;
}

function find_source($mappings, $dest_path) {
    return $mappings[$dest_path] ?? null;
}

function remove_mapping(&$mappings, $dest_path) {
    if (isset($mappings[$dest_path])) {
        unset($mappings[$dest_path]);
    }
}

// --- פונקציית עזר לבדיקת שלוחות מקור ---
function is_source_extension($path) {
    if (!is_array(SOURCE_EXTENSIONS)) return false;
    foreach (SOURCE_EXTENSIONS as $ext) {
        if (!empty($ext) && strpos($path, 'ivr2:/' . $ext . '/') === 0) {
            return true;
        }
    }
    return false;
}


// --- פונקציית עזר לביצוע קריאת API למפתחים ---
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
    
    if ($result === FALSE) {
        return null; // שגיאת רשת
    }
    
    return json_decode($result, true);
}


// --- לוגיקה ראשית - עיבוד הבקשה ---

$params = $_REQUEST;

if (!isset($params['what'])) {
    echo "id_list_message=t-שגיאה, לא התקבל נתיב קובץ";
    exit;
}

$current_file_path = $params['what']; 
$file_name = basename($current_file_path); 
$response_message = "id_list_message=t-פעולה לא זוהתה"; 

try {
    if (is_source_extension($current_file_path)) {
        // --- לוגיקת העתקה ---
        $source_path = $current_file_path;
        $dest_path = 'ivr2:/' . DEST_EXTENSION . '/' . $file_name;

        $api_params = [
            'action' => 'copy',
            'what'   => $source_path,
            'target' => $dest_path
        ];
        $api_response = call_yemot_api('FileAction', $api_params);

        if ($api_response && $api_response['responseStatus'] == 'OK') {
            $mappings = load_mappings();
            add_mapping($mappings, $dest_path, $source_path);
            save_mappings($mappings); // זה עלול להיכשל!
            
            $response_message = RESPONSE_ON_COPY_SUCCESS;
        } else {
            // --- [שדרוג דיבאגינג v2] ---
            if (is_array($api_response)) {
                $status = $api_response['responseStatus'] ?? 'Unknown Status';
                $message = $api_response['message'] ?? 'Unknown Message';
                
                $safe_status = preg_replace("/[^a-zA-Z0-9 ]/", "", $status);
                $safe_message = preg_replace("/[^a-zA-Z0-9 ]/", "", $message);

                // ודא שההודעה אינה ריקה
                if (empty($safe_status)) $safe_status = "Empty";
                if (empty($safe_message)) $safe_message = "Empty";

                $response_message = "id_list_message=t-שגיאת API בהעתקה. סטטוס. a-" . $safe_status . ". t-הודעה. a-" . $safe_message;
            
            } elseif ($api_response === null) {
                $response_message = "id_list_message=t-שגיאת רשת. אין תשובה מהשרת של ימות";
            } else {
                $response_message = "id_list_message=t-תשובה לא מובנת מהשרת של ימות";
            }
            // --- [סוף שדרוג דיבאגינג v2] ---
        }

    } 
    elseif (strpos($current_file_path, 'ivr2:/' . DEST_EXTENSION . '/') === 0) {
        // --- לוגיקת מחיקה כפולה ---
        $dest_path = $current_file_path;
        $mappings = load_mappings();
        $source_path = find_source($mappings, $dest_path);

        $api_params_dest = ['action' => 'delete', 'what' => $dest_path];
        $api_response_dest = call_yemot_api('FileAction', $api_params_dest);
        
        $deleted_source = false;
        if ($source_path) {
            $api_params_source = ['action' => 'delete', 'what' => $source_path];
            $api_response_source = call_yemot_api('FileAction', $api_params_source);
            if ($api_response_source && $api_response_source['responseStatus'] == 'OK') {
                $deleted_source = true;
            }
        }

        if ($api_response_dest && $api_response_dest['responseStatus'] == 'OK') {
            remove_mapping($mappings, $dest_path);
            save_mappings($mappings); // זה עלול להיכשל!
            
            if ($deleted_source) {
                $response_message = RESPONSE_ON_DELETE_SUCCESS;
            } else if ($source_path) {
                $response_message = RESPONSE_ON_DELETE_PARTIAL_ERROR;
            } else {
                $response_message = RESPONSE_ON_DELETE_NO_SOURCE;
            }
        } else {
            // --- [שדרוג דיבאגינג v2] ---
            if (is_array($api_response_dest)) {
                 $status = $api_response_dest['responseStatus'] ?? 'Unknown Status';
                 $message = $api_response_dest['message'] ?? 'Unknown Message';
                 
                 $safe_status = preg_replace("/[^a-zA-Z0-9 ]/", "", $status);
                 $safe_message = preg_replace("/[^a-zA-Z0-9 ]/", "", $message);

                 if (empty($safe_status)) $safe_status = "Empty";
                 if (empty($safe_message)) $safe_message = "Empty";

                 $response_message = "id_list_message=t-שגיאת API במחיקה. סטטוס. a-" . $safe_status . ". t-הודעה. a-" . $safe_message;
            } elseif ($api_response_dest === null) {
                $response_message = "id_list_message=t-שגיאת רשת במחיקה. אין תשובה מימות";
            } else {
                 $response_message = "id_list_message=t-תשובה לא מובנת מהשרת של ימות";
            }
            // --- [סוף שדרוג דיבאגינג v2] ---
        }
    }

} catch (Exception $e) {
    // --- [שדרוג דיבאגינג] ---
    // נקריא את שגיאת ה-PHP הפנימית
    $error_msg = $e->getMessage();
    $safe_error = preg_replace("/[^a-zA-Z0-9 ]/", "", $error_msg);
    if (empty($safe_error)) $safe_error = "Unknown Exception";
    
    $response_message = "id_list_message=t-שגיאת שרת קריטית. a-" . $safe_error;
}

// החזר תשובה למערכת הטלפונית
echo $response_message;

?>
