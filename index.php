<?php

// --- תצורה ראשית - יש לערוך ---

// הגדר את הטוקן שלך (מספר מערכת:סיסמה)
define('YEMOT_TOKEN', '0733181406:80809090'); 

// הגדר את כתובת ה-API למפתחים
define('YEMOT_API_URL', 'https://www.call2all.co.il/ym/api/');

// --- [שדרוג 1] ---
// הגדר את שלוחות המקור (יכול להיות אחד או יותר)
// כל השלוחות ברשימה זו יעתיקו קבצים אל שלוחת היעד
define('SOURCE_EXTENSIONS', [
    '11',
    '90', // הוסף עוד שלוחות מקור כאן
    '97', // הוסף עוד שלוחות מקור כאן
    '94', // הוסף עוד שלוחות מקור כאן
    '988', // הוסף עוד שלוחות מקור כאן  
    '9999'  // לפי הצורך
]);

// הגדר את שלוחת היעד (רק אחת)
define('DEST_EXTENSION', '800/54');    // שלוחת יעד (אליה מעתיקים)

// קובץ מסד נתונים למיפוי קבצים (דורש אחסון קבוע ב-Render)
define('DB_FILE', 'file_mappings.json');


// --- [שדרוג 2] ---
// הגדרות ניווט ותגובות לאחר פעולה מוצלחת
// כאן אתה קובע מה המאזין ישמע ו/או לאן הוא יועבר.
// אפשר להשתמש ב: "id_list_message=t-הודעה להשמעה" (כדי להשמיע הודעה)


define('RESPONSE_ON_DELETE_SUCCESS', 'id_list_message=t-הקובץ נמחק בהצלחה');

// הגדרות תגובה למקרים פחות נפוצים (אבל תקינים)
define('RESPONSE_ON_DELETE_PARTIAL_ERROR', 'id_list_message=t-שגיאה במציאת הקובץ המקורי');
define('RESPONSE_ON_DELETE_NO_SOURCE', 'id_list_message=t-הקובץ בשלוחת המקור לא נמצא');

// --- סוף תצורה ---


// --- פונקציות עזר למסד נתונים (JSON) ---

/**
 * טוען את מיפוי הקבצים מהקובץ
 * @return array
 */
function load_mappings() {
    if (!file_exists(DB_FILE)) {
        return [];
    }
    $data = file_get_contents(DB_FILE);
    return json_decode($data, true) ?: [];
}

/**
 * שומר את מיפוי הקבצים לקובץ
 * @param array $mappings
 */
function save_mappings($mappings) {
    file_put_contents(DB_FILE, json_encode($mappings, JSON_PRETTY_PRINT));
}

/**
 * מוסיף מיפוי חדש (יעד -> מקור)
 * @param array &$mappings
 * @param string $dest_path
 * @param string $source_path
 */
function add_mapping(&$mappings, $dest_path, $source_path) {
    $mappings[$dest_path] = $source_path;
}

/**
 * מוצא את נתיב המקור לפי נתיב היעד
 * @param array $mappings
 * @param string $dest_path
 * @return string|null
 */
function find_source($mappings, $dest_path) {
    return isset($mappings[$dest_path]) ? $mappings[$dest_path] : null;
}

/**
 * מסיר מיפוי
 * @param array &$mappings
 * @param string $dest_path
 */
function remove_mapping(&$mappings, $dest_path) {
    if (isset($mappings[$dest_path])) {
        unset($mappings[$dest_path]);
    }
}

// --- [חדש] פונקציית עזר לבדיקת שלוחות מקור ---
/**
 * בודק אם הנתיב שייך לאחת משלוחות המקור המוגדרות
 * @param string $path
 * @return bool
 */
function is_source_extension($path) {
    // ודא ש-SOURCE_EXTENSIONS מוגדר כמערך
    if (!is_array(SOURCE_EXTENSIONS)) {
        return false;
    }
    
    foreach (SOURCE_EXTENSIONS as $ext) {
        // ודא שהערך אינו ריק
        if (!empty($ext) && strpos($path, 'ivr2:/' . $ext . '/') === 0) {
            return true;
        }
    }
    return false;
}


// --- פונקציית עזר לביצוע קריאת API למפתחים ---

/**
 * מבצע קריאת API של ימות המשיח (למפתחים)
 * @param string $method (לדוגמה 'FileAction')
 * @param array $params
 * @return array|null
 */
function call_yemot_api($method, $params) {
    $url = YEMOT_API_URL . $method;
    
    // הוסף את הטוקן לפרמטרים
    $params['token'] = YEMOT_TOKEN;
    
    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($params),
            'ignore_errors' => true
        ],
    ];
    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    
    if ($result === FALSE) {
        return null; // שגיאת רשת
    }
    
    return json_decode($result, true);
}


// --- לוגיקה ראשית - עיבוד הבקשה ---

// קבל את כל הפרמטרים שנשלחו מהשלוחה
$params = $_REQUEST;

// ודא שהפרמטר 'what' (נתיב הקובץ) קיים
if (!isset($params['what'])) {
    // אם אין נתיב קובץ, החזר הודעת שגיאה
    echo "id_list_message=t-שגיאה, לא התקבל נתיב קובץ";
    exit;
}

$current_file_path = $params['what']; // ivr2:/11/001.wav או ivr2:/14/001.wav
$file_name = basename($current_file_path); // 001.wav

$response_message = "id_list_message=t-פעולה לא זוהתה"; // הודעת ברירת מחדל

try {
    // --- [שונה] ---
    // בדוק אם זו בקשת העתקה (מכל אחת משלוחות המקור)
    if (is_source_extension($current_file_path)) {
        // --- לוגיקת העתקה ---
        
        $source_path = $current_file_path;
        $dest_path = 'ivr2:/' . DEST_EXTENSION . '/' . $file_name;

        // 1. בצע העתקה דרך ה-API למפתחים
        $api_params = [
            'action' => 'copy',
            'what'   => $source_path,
            'target' => $dest_path
        ];
        $api_response = call_yemot_api('FileAction', $api_params);

        if ($api_response && $api_response['responseStatus'] == 'OK') {
            // 2. אם ההעתקה הצליחה, שמור ב-DB
            $mappings = load_mappings();
            add_mapping($mappings, $dest_path, $source_path);
            save_mappings($mappings);
            
            // --- [שונה] ---
            $response_message = RESPONSE_ON_COPY_SUCCESS; // השתמש בהגדרה שקבעת למעלה
        } else {
            $error = $api_response ? $api_response['message'] : 'Network Error';
            // במקרה של שגיאה, תמיד נשמיע הודעה ולא ננווט
            $response_message = "id_list_message=t-שגיאה בעת העתקת הקובץ: " . $error;
        }

    } 
    // בדוק אם זו בקשת מחיקה (משלוחת היעד)
    elseif (strpos($current_file_path, 'ivr2:/' . DEST_EXTENSION . '/') === 0) {
        // --- לוגיקת מחיקה כפולה ---
        
        $dest_path = $current_file_path;
        
        // 1. טען את ה-DB ומצא את קובץ המקור
        $mappings = load_mappings();
        $source_path = find_source($mappings, $dest_path);

        // 2. מחק את קובץ היעד
        $api_params_dest = [
            'action' => 'delete',
            'what'   => $dest_path
        ];
        $api_response_dest = call_yemot_api('FileAction', $api_params_dest);
        
        $deleted_source = false;
        
        // 3. אם המקור נמצא, מחק גם אותו
        if ($source_path) {
            $api_params_source = [
                'action' => 'delete',
                'what'   => $source_path
            ];
            $api_response_source = call_yemot_api('FileAction', $api_params_source);
            if ($api_response_source && $api_response_source['responseStatus'] == 'OK') {
                $deleted_source = true;
            }
        }

        // 4. עדכן DB והחזר תשובה
        if ($api_response_dest && $api_response_dest['responseStatus'] == 'OK') {
            remove_mapping($mappings, $dest_path); // הסר מהמיפוי
            save_mappings($mappings);
            
            // --- [שונה] ---
            // בחר את התגובה המתאימה לפי ההגדרות שקבעת למעלה
            if ($deleted_source) {
                $response_message = RESPONSE_ON_DELETE_SUCCESS;
            } else if ($source_path) {
                $response_message = RESPONSE_ON_DELETE_PARTIAL_ERROR;
            } else {
                $response_message = RESPONSE_ON_DELETE_NO_SOURCE;
            }
        } else {
             $error = $api_response_dest ? $api_response_dest['message'] : 'Network Error';
             // במקרה של שגיאה, תמיד נשמיע הודעה ולא ננווט
             $response_message = "id_list_message=t-שגיאה בעת מחיקת קובץ היעד: " . $error;
        }
    }

} catch (Exception $e) {
    $response_message = "id_list_message=t-שגיאת שרת קריטית: " . $e->getMessage();
}

// החזר תשובה למערכת הטלפונית
echo $response_message;

?>

