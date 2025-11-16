<?php

// --- תצורה ראשית - יש לערוך ---

// הגדר את הטוקן שלך (מספר מערכת:סיסמה)
define('YEMOT_TOKEN', 'מספר_מערכת:סיסמה'); 

// הגדר את כתובת ה-API למפתחים
define('YEMOT_API_URL', 'https://www.call2all.co.il/ym/api/');

// --- [חדש] הגדרות שלוחות ---

// הגדר את שלוחות המקור (מהן מעתיקים/מזיזים)
define('SOURCE_EXTENSIONS', [
    '11',
    '90',
    '97',
    '94',
    '988',
    '9999'
]);

// שלוחת יעד לדיווחים רגילים (כמו קודם)
define('DEST_REGULAR', '8000');

// שלוחת יעד לדיווחים חמורים (לטיפול מנהל)
define('DEST_URGENT_A', '88');

// שלוחת יעד לדיווחים חמורים (לתיעוד)
define('DEST_URGENT_B', '85');


// קובץ מסד נתונים למיפוי קבצים (JSON)
define('DB_FILE', 'file_mappings.json');


// --- [חדש] הגדרות תגובה למאזין (לכל הפעולות) ---

// דיווחים
define('RESPONSE_ON_REGULAR_COPY_SUCCESS', 'id_list_message=t-הדיווח הרגיל התקבל, הקובץ הועתק');
define('RESPONSE_ON_URGENT_MOVE_SUCCESS', 'id_list_message=t-דיווח חמור התקבל, הקובץ הועבר באופן מיידי');

// פעולות מנהל
define('RESPONSE_ON_DELETE_SUCCESS', 'id_list_message=t-הקובץ נמחק בהצלחה מהארכיון ומהמקור');
define('RESPONSE_ON_RESTORE_SUCCESS', 'id_list_message=t-הקובץ שוחזר בהצלחה לשלוחה המקורית');

// שגיאות
define('RESPONSE_ON_DELETE_PARTIAL_ERROR', 'id_list_message=t-הקובץ נמחק מהארכיון אך המקור לא נמצא');
define('RESPONSE_ON_DELETE_NO_SOURCE', 'id_list_message=t-הקובץ נמחק מהארכיון (לא נמצא קישור למקור)');
define('RESPONSE_ON_RESTORE_NO_SOURCE', 'id_list_message=t-שגיאת שחזור, לא נמצא נתיב מקור ביומן');
define('RESPONSE_ON_ERROR', 'id_list_message=t-אירעה שגיאה בביצוע הפעולה');
define('RESPONSE_ON_PARAM_ERROR', 'id_list_message=t-שגיאה: חסרים פרמטרים');
define('RESPONSE_ON_UNKNOWN_ACTION', 'id_list_message=t-פעולה לא מוכרת');

// --- סוף תצורה ---


// --- פונקציות עזר למסד נתונים (JSON) ---

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
    
    if ($result === FALSE) return null;
    return json_decode($result, true);
}


// --- לוגיקה ראשית ---

header('Content-Type: text/html; charset=utf-8');

$params = $_REQUEST;
$response_message = RESPONSE_ON_ERROR; // ברירת מחדל לשגיאה

// קבלת פרמטרים מרכזיים
$what = $params['what'] ?? null; // נתיב הקובץ (נשלח תמיד)
$action = $params['action'] ?? null; // הפעולה לביצוע (אנחנו מגדירים ב-ext.ini)
$report_type = $params['report_type'] ?? null; // יתקבל (1 או 2) אחרי ה-read

// בדיקה בסיסית
if (!$what || !$action) {
    echo RESPONSE_ON_PARAM_ERROR;
    exit;
}

try {
    // --- [חדש] נתב פעולות מרכזי ---
    switch ($action) {

        // --- תרחיש 1: מאזין מדווח (משלוחת מקור) ---
        case 'ask_report_type':
            // זוהי הלוגיקה הדו-שלבית שדיברנו עליה
            if (empty($report_type)) {
                // שלב 1: המאזין לחץ 7. ה-API עוד לא שאל אותו כלום.
                // נגיד לימות המשיח לשאול אותו עכשיו.
                $response_message = "read=t-אם זה דיווח חמור הקש 1 ואם דיווח רגיל הקש 2,report_type,no,1,1,Digits,yes,yes,*/,1.2";
            
            } else {
                // שלב 2: המאזין ענה 1 או 2. הנתון חזר אלינו יחד עם 'what'.
                // עכשיו נבצע את הפעולה לפי הבחירה.
                $file_name = basename($what);
                $source_path = $what;

                if ($report_type == '2') {
                    // --- דיווח רגיל (כמו הלוגיקה הישנה) ---
                    $dest_path = 'ivr2:/' . DEST_REGULAR . '/' . $file_name;
                    $api_params = ['action' => 'copy', 'what' => $source_path, 'target' => $dest_path];
                    $api_response = call_yemot_api('FileAction', $api_params);

                    if ($api_response && $api_response['responseStatus'] == 'OK') {
                        $mappings = load_mappings();
                        add_mapping($mappings, $dest_path, $source_path);
                        save_mappings($mappings);
                        $response_message = RESPONSE_ON_REGULAR_COPY_SUCCESS;
                    } else {
                        $response_message = "id_list_message=t-שגיאה בהעתקה רגילה";
                    }

                } elseif ($report_type == '1') {
                    // --- דיווח חמור (לוגיקה חדשה: העברה + העתקה) ---
                    $dest_path_a = 'ivr2:/' . DEST_URGENT_A . '/' . $file_name; // 88
                    $dest_path_b = 'ivr2:/' . DEST_URGENT_B . '/' . $file_name; // 85 (תיעוד)

                    // 1. העברה לשלוחה 88 (Move = Copy + Delete)
                    $api_params_move_copy = ['action' => 'copy', 'what' => $source_path, 'target' => $dest_path_a];
                    $api_response_move_copy = call_yemot_api('FileAction', $api_params_move_copy);

                    if ($api_response_move_copy && $api_response_move_copy['responseStatus'] == 'OK') {
                        // רק אם ההעתקה ל-88 הצליחה, נמחק את המקור
                        $api_params_move_delete = ['action' => 'delete', 'what' => $source_path];
                        call_yemot_api('FileAction', $api_params_move_delete); // נמשיך גם אם המחיקה נכשלת

                        // 2. העתקה לתיעוד (שלוחה 85)
                        $api_params_log = ['action' => 'copy', 'what' => $source_path, 'target' => $dest_path_b];
                        call_yemot_api('FileAction', $api_params_log); // זה רק לתיעוד, נמשיך גם אם נכשל

                        // 3. שמירת מיפוי (הכי חשוב זה המיפוי ל-88 לצורך שחזור)
                        $mappings = load_mappings();
                        add_mapping($mappings, $dest_path_a, $source_path); // מפתח: 88, ערך: המקור
                        save_mappings($mappings);
                        $response_message = RESPONSE_ON_URGENT_MOVE_SUCCESS;
                        
                    } else {
                         $response_message = "id_list_message=t-שגיאה בהעברה דחופה";
                    }
                }
            }
            break;

        // --- תרחיש 2: מנהל מוחק דיווח רגיל (משלוחה 8000) ---
        case 'delete_regular':
            $dest_path = $what; // $what הוא ivr2:/8000/file.wav
            $mappings = load_mappings();
            $source_path = find_source($mappings, $dest_path);

            // 1. מחיקת קובץ היעד (8000)
            $api_params_dest = ['action' => 'delete', 'what' => $dest_path];
            $api_response_dest = call_yemot_api('FileAction', $api_params_dest);
            
            if ($api_response_dest && $api_response_dest['responseStatus'] == 'OK') {
                $deleted_source = false;
                // 2. מחיקת קובץ המקור (אם קיים במיפוי)
                if ($source_path) {
                    $api_params_source = ['action' => 'delete', 'what' => $source_path];
                    $api_response_source = call_yemot_api('FileAction', $api_params_source);
                    if ($api_response_source && $api_response_source['responseStatus'] == 'OK') {
                        $deleted_source = true;
                    }
                }

                // עדכון המיפוי
                remove_mapping($mappings, $dest_path);
                save_mappings($mappings);
                
                // קביעת הודעת התגובה
                if ($deleted_source) $response_message = RESPONSE_ON_DELETE_SUCCESS;
                elseif ($source_path) $response_message = RESPONSE_ON_DELETE_PARTIAL_ERROR;
                else $response_message = RESPONSE_ON_DELETE_NO_SOURCE;

            } else {
                 $response_message = "id_list_message=t-שגיאה במחיקת קובץ היעד";
            }
            break;

        // --- תרחיש 3: מנהל משחזר דיווח חמור (משלוחה 88) ---
        case 'restore_urgent':
            $dest_path_a = $what; // $what הוא ivr2:/88/file.wav
            $mappings = load_mappings();
            $source_path = find_source($mappings, $dest_path_a); // מחפש את המקור לפי 88

            if (!$source_path) {
                $response_message = RESPONSE_ON_RESTORE_NO_SOURCE;
                break; // יוצא מה-switch
            }

            // לוגיקת שחזור: העבר (Move) את הקובץ מ-88 בחזרה למקור
            // Move = Copy + Delete
            $api_params_restore_copy = ['action' => 'copy', 'what' => $dest_path_a, 'target' => $source_path];
            $api_response_restore_copy = call_yemot_api('FileAction', $api_params_restore_copy);

            if ($api_response_restore_copy && $api_response_restore_copy['responseStatus'] == 'OK') {
                // רק אם השחזור (העתקה) הצליח, נמחק את הקובץ מ-88
                $api_params_restore_delete = ['action' => 'delete', 'what' => $dest_path_a];
                call_yemot_api('FileAction', $api_params_restore_delete);

                // הסר את המיפוי מה-JSON
                remove_mapping($mappings, $dest_path_a);
                save_mappings($mappings);
                
                $response_message = RESPONSE_ON_RESTORE_SUCCESS;
            } else {
                $response_message = "id_list_message=t-שגיאה בשחזור הקובץ";
            }
            break;

        default:
            $response_message = RESPONSE_ON_UNKNOWN_ACTION;
            break;
    }

} catch (Exception $e) {
    $response_message = "id_list_message=t-שגיאת שרת: " . $e->getMessage();
}

// החזר תשובה סופית לימות המשיח
echo $response_message;

?>
