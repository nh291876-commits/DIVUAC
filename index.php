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

// --- [חובה! הרשאות כתיבה] ---
// הגדר נתיב *עם הרשאות כתיבה* לקובץ מסד הנתונים
// בשרת Render, זה *חייב* להיות נתיב לדיסק קשיח (Persistent Disk)
// לדוגמה: '/var/data/'
define('DB_WRITE_PATH', './'); // שנה לנתיב הכתיבה שלך ב-Render

// --- קבצי לוג ו-DB ---
define('DB_FILE', DB_WRITE_PATH . 'file_mappings.json');
define('DEBUG_LOG_FILE', DB_WRITE_PATH . 'script_debug_log.txt');

// --- [שדרוג 2] ---
// הגדרות ניווט חזרה לימות המשיח (מבוסס על ההגדרות שלך)
define('API_GOTO_SUCCESS', '/800/55'); // זה ה- api_end_goto שלך
define('API_GOTO_FAILURE', '/800/ERROR'); // ניצור שלוחת שגיאה ייעודית

// --- פונקציית לוגינג חדשה ---
function debug_log($message) {
    // הוסף חותמת זמן והודעה ללוג
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(DEBUG_LOG_FILE, "[$timestamp] $message\n", FILE_APPEND);
}

// --- ניקוי לוג ישן (אופציונלי) ---
// אם הלוג גדול מדי, מחק אותו
if (file_exists(DEBUG_LOG_FILE) && filesize(DEBUG_LOG_FILE) > 5000000) { // 5MB
    unlink(DEBUG_LOG_FILE);
}

// התחלת לוג לבקשה זו
debug_log("--- New Request Received ---");
debug_log("Raw Params: " . json_encode($_REQUEST));


// --- פונקציות עזר למסד נתונים (JSON) ---

function load_mappings() {
    if (!file_exists(DB_FILE)) {
        return [];
    }
    $data = file_get_contents(DB_FILE);
    return json_decode($data, true) ?: [];
}

function save_mappings($mappings) {
    $result = file_put_contents(DB_FILE, json_encode($mappings, JSON_PRETTY_PRINT));
    if ($result === false) {
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
    debug_log("Calling Yemot API: $method with params: " . json_encode($params));
    
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
        debug_log("Yemot API Call Failed (Network Error)");
        return null; // שגיאת רשת
    }
    
    debug_log("Yemot API Raw Response: " . $result);
    return json_decode($result, true);
}


// --- לוגיקה ראשית - עיבוד הבקשה ---

$response_command = "go_to_folder=" . API_GOTO_FAILURE; // ברירת מחדל תיכשל

try {
    if (!isset($_REQUEST['what'])) {
        throw new Exception("No 'what' parameter received.");
    }

    $current_file_path = $_REQUEST['what']; 
    $file_name = basename($current_file_path); 

    if (is_source_extension($current_file_path)) {
        // --- לוגיקת העתקה ---
        debug_log("Action: Copy");
        $source_path = $current_file_path;
        $dest_path = 'ivr2:/' . DEST_EXTENSION . '/' . $file_name;

        $api_params = [
            'action' => 'copy',
            'what'   => $source_path,
            'target' => $dest_path
        ];
        $api_response = call_yemot_api('FileAction', $api_params);

        if ($api_response && $api_response['responseStatus'] == 'OK') {
            debug_log("Copy OK. Saving to DB.");
            $mappings = load_mappings();
            add_mapping($mappings, $dest_path, $source_path);
            save_mappings($mappings);
            
            $response_command = "go_to_folder=" . API_GOTO_SUCCESS;
        } else {
            throw new Exception("Copy API call failed. Response: " . json_encode($api_response));
        }

    } 
    elseif (strpos($current_file_path, 'ivr2:/' . DEST_EXTENSION . '/') === 0) {
        // --- לוגיקת מחיקה כפולה ---
        debug_log("Action: Delete");
        $dest_path = $current_file_path;
        $mappings = load_mappings();
        $source_path = find_source($mappings, $dest_path);

        $api_params_dest = ['action' => 'delete', 'what' => $dest_path];
        $api_response_dest = call_yemot_api('FileAction', $api_params_dest);
        
        if (!$api_response_dest || $api_response_dest['responseStatus'] != 'OK') {
             throw new Exception("Delete (Destination) API call failed. Response: " . json_encode($api_response_dest));
        }
        
        debug_log("Delete (Destination) OK.");
        $deleted_source = false;
        if ($source_path) {
            debug_log("Found source file to delete: $source_path");
            $api_params_source = ['action' => 'delete', 'what' => $source_path];
            $api_response_source = call_yemot_api('FileAction', $api_params_source);
            if ($api_response_source && $api_response_source['responseStatus'] == 'OK') {
                debug_log("Delete (Source) OK.");
                $deleted_source = true;
            } else {
                 debug_log("WARNING: Delete (Source) FAILED. Response: " . json_encode($api_response_source));
            }
        } else {
             debug_log("WARNING: No source file found in DB for $dest_path");
        }

        remove_mapping($mappings, $dest_path);
        save_mappings($mappings);
        debug_log("DB mapping removed.");
        
        $response_command = "go_to_folder=" . API_GOTO_SUCCESS;

    } else {
         debug_log("Action: None. Path did not match source or destination.");
         // ישמור על ברירת המחדל של כישלון
    }

} catch (Exception $e) {
    debug_log("--- CRITICAL ERROR ---");
    debug_log("Exception: " . $e->getMessage());
    $response_command = "go_to_folder=" . API_GOTO_FAILURE;
}

// החזר תשובה למערכת הטלפונית
debug_log("Final response to Yemot: $response_command");
echo $response_command;

?>
```eof

### הוראות הפעלה (חובה!)

כעת עליך לבצע 4 פעולות קריטיות כדי שזה יעבוד:

1.  **החלף את הקוד:** החלף את כל הקוד ב-`index.php` שלך בקוד שסיפקתי למעלה.

2.  **צור שלוחות בימות המשיח:**
    * ודא שהשלוחה `/800/55` קיימת (זו השלוחה שציינת ב-`api_end_goto`, אז אני מניח שהיא קיימת).
    * **צור שלוחה חדשה:** ` /800/ERROR` (אפשר שתהיה שלוחה ריקה או שלוחת השמעת קבצים עם הודעת "אירעה שגיאה").

3.  **תקן הרשאות כתיבה ב-Render (הכי חשוב):**
    * הסקריפט עכשיו צריך לכתוב שני קבצים: `file_mappings.json` ו-`script_debug_log.txt`.
    * אתה **חייב** להגדיר "Persistent Disk" ב-Render ולקבל נתיב תיקייה עם הרשאות כתיבה.
    * שנה את שורה 28 בקוד:
        ```php
        // שנה את זה:
        define('DB_WRITE_PATH', './'); 
        
        // לנתיב שקיבלת מ-Render, לדוגמה:
        define('DB_WRITE_PATH', '/var/data/'); 
        ```
    * **אם לא תעשה זאת, הסקריפט יכשל מיידית.**

4.  **בצע את הניסוי ודווח:**
    * התקשר למערכת, גש לשלוחה `11` (או שלוחת מקור אחרת), והקש `*` ואז `5` כדי לנסות להעתיק.
    * **מה קרה?**
        * **אם הועברת לשלוחה `/800/55`:** הצלחה! הקובץ הועתק.
        * **אם הועברת לשלוחה `/800/ERROR`:** כישלון.
    * **אם זה נכשל (הגעת ל-ERROR):**
        1.  גש לשרת שלך ב-Render.
        2.  פתח את התיקייה שהגדרת ב-`DB_WRITE_PATH`.
        3.  מצא את הקובץ `script_debug_log.txt`.
        4.  **שלח לי את כל התוכן של הקובץ הזה.**

הלוג הזה יגיד לנו *בדיוק* מהי השגיאה שימות המשיח מחזיר (למשל: "Token invalid", "Target path not found" וכו').
