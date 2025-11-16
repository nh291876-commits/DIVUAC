<?php
// --- [חדש] טעינת ספריות חיצוניות (Composer) ---
// ודא שקובץ זה נמצא בתיקייה הראשית של הפרויקט שלך
require 'vendor/autoload.php';

// שימוש בספריות של גוגל
use Google\Cloud\Speech\V1\SpeechClient;
use Google\Cloud\Speech\V1\RecognitionConfig;
use Google\Cloud\Speech\V1\RecognitionAudio;
use Google\Cloud\Speech\V1\RecognitionConfig\AudioEncoding;
use Google\ApiCore\ApiException;

// הגדרת דיווח שגיאות
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- תצורה ראשית - יש לערוך ---

define('YEMOT_TOKEN', '0733181406:80809090');
define('YEMOT_API_URL', 'https://www.call2all.co.il/ym/api/');

// --- [שדרוג 1] - שלוחות מקור ויעד ---
define('SOURCE_EXTENSIONS', [
    '11', '90', '97', '94', '988', '9999'
]);

// [שונה] שלוחת יעד לבדיקה ידנית (מסופקים / תקינים)
define('DEST_EXTENSION_MANUAL', '8000');
// [חדש] שלוחת יעד לבידוד (קבצים בעייתיים שזוהו ע"י AI)
define('DEST_EXTENSION_QUARANTINE', '922');

// קובץ מסד נתונים למיפוי קבצים (רק עבור שלוחה 8000)
define('DB_FILE', 'file_mappings.json');
define('TEMP_DIR', sys_get_temp_dir()); // תיקייה זמנית של השרת

// --- [שדרוג 2] - הגדרות AI ו-API ---

// [חדש] נתיב לקובץ ה-JSON של חשבון השירות של Google Cloud (עבור תמלול)
define('GOOGLE_STT_CREDENTIALS', __DIR__ . '/YOUR_GOOGLE_STT_CREDENTIALS_FILE.json');

// [חדש] מפתח API של Google AI Studio (עבור Gemini)
define('GOOGLE_STT_CREDENTIALS', __DIR__ . '/google-stt.json');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-09-2025:generateContent');

// --- [שדרוג 3] - הגדרות תגובה ---

// [חדש] תגובות לתהליך ה-AI
define('RESPONSE_ON_AI_QUARANTINE', 'id_list_message=t-הקובץ זוהה כבעייתי והועבר לבידוד');
define('RESPONSE_ON_AI_MANUAL', 'id_list_message=t-הקובץ הועבר לבדיקה ידנית');
define('RESPONSE_ON_AI_ERROR', 'id_list_message=t-שגיאת AI. הקובץ הועבר לבדיקה ידנית');
define('RESPONSE_ON_DOWNLOAD_FAIL', 'id_list_message=t-שגיאה בהורדת הקובץ. הועבר לבדיקה ידנית');

// תגובות למחיקה ידנית (משלוחה 8000)
define('RESPONSE_ON_DELETE_SUCCESS', 'id_list_message=t-הקובץ נמחק בהצלחה');
define('RESPONSE_ON_DELETE_PARTIAL_ERROR', 'id_list_message=t-שגיאה במחיקת הקובץ המקורי');
define('RESPONSE_ON_DELETE_NO_SOURCE', 'id_list_message=t-הקובץ בשלוחת המקור לא נמצא');


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
    return $mappings[$dest_path] ?? null;
}

function remove_mapping(&$mappings, $dest_path) {
    if (isset($mappings[$dest_path])) {
        unset($mappings[$dest_path]);
    }
}

function is_source_extension($path) {
    if (!is_array(SOURCE_EXTENSIONS)) return false;
    foreach (SOURCE_EXTENSIONS as $ext) {
        if (!empty($ext) && strpos($path, 'ivr2:/' . $ext . '/') === 0) {
            return true;
        }
    }
    return false;
}

// --- פונקציות עזר ל-API ---

/**
 * מבצע קריאת API של ימות המשיח (JSON)
 */
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
    $result = @file_get_contents($url, false, $context);
    
    if ($result === FALSE) return null;
    return json_decode($result, true);
}

/**
 * [חדש] מוריד קובץ שמע משרת ימות המשיח
 */
function download_yemot_file($yemot_file_path, $local_save_path) {
    $url = YEMOT_API_URL . 'DownloadFile';
    $params = [
        'token' => YEMOT_TOKEN,
        'path' => $yemot_file_path
    ];
    
    $full_url = $url . '?' . http_build_query($params);
    $audio_content = @file_get_contents($full_url);
    
    if ($audio_content !== FALSE && strlen($audio_content) > 100) { 
        return (file_put_contents($local_save_path, $audio_content) !== FALSE);
    }
    return false;
}

/**
 * [חדש] מתמלל קובץ שמע מקומי לטקסט
 * דורש התקנת 'composer require google/cloud-speech'
 * וקובץ JSON של חשבון שירות
 */
function transcribe_audio_file($local_file_path) {
    try {
        $speechClient = new SpeechClient([
            'credentials' => GOOGLE_STT_CREDENTIALS
        ]);

        $audio_content = file_get_contents($local_file_path);

        $config = (new RecognitionConfig())
            ->setEncoding(AudioEncoding::LINEAR16) // או AudioEncoding::ENCODING_UNSPECIFIED
            ->setSampleRateHertz(8000) // ודא שזה קצב הדגימה של ימות המשיח
            ->setLanguageCode('he-IL'); // עברית

        $audio = (new RecognitionAudio())
            ->setContent($audio_content);

        $response = $speechClient->recognize($config, $audio);
        $transcript = '';
        foreach ($response->getResults() as $result) {
            $transcript .= $result->getAlternatives()[0]->getTranscript();
        }
        $speechClient->close();
        return $transcript;
    } catch (ApiException $e) {
        // שגיאה בתמלול
        return null;
    } catch (Exception $e) {
        // שגיאה כללית (למשל, קובץ JSON לא נמצא)
        return null;
    }
}

/**
 * [חדש] מבצע קריאת API למודל שפה (Gemini) לניתוח טקסט
 * דורש מפתח API של Gemini
 */
function call_ai_api($transcript) {
    if (empty($transcript)) {
        return 'IGNORE'; // אין טקסט, אין מה לנתח
    }
    
    $system_instruction = "אתה מסנן תוכן אוטומטי. נתח את הטקסט שסופק ובדוק אם הוא מכיל תוכן שאינו הולם או אסור. עליך להשיב אך ורק באחת משלוש המילים הבאות: 'DELETE' אם הטקסט מכיל תוכן אסור, 'IGNORE' אם הטקסט תקין לחלוטין, או 'UNCERTAIN' אם אינך בטוח או אם התוכן גבולי. לעולם אל תחזיר דבר מלבד אחת מהמילים הללו.";
    
    $payload = [
        'contents' => [['parts' => [['text' => "האם הטקסט הבא מכיל תוכן אסור? הטקסט: " . $transcript]]]],
        'systemInstruction' => ['parts' => [['text' => $system_instruction]]],
        'generationConfig' => [
            'temperature' => 0.1,
            'responseMimeType' => "text/plain",
        ],
    ];

    $url = GEMINI_API_URL . "?key=" . GEMINI_API_KEY;

    $options = [
        'http' => [
            'header'  => "Content-Type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($payload),
            'ignore_errors' => true
        ],
    ];
    $context  = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    if ($response === FALSE) {
        return 'ERROR'; // שגיאת רשת
    }
    
    $data = json_decode($response, true);
    $text = trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
    
    if (in_array($text, ['DELETE', 'IGNORE', 'UNCERTAIN'])) {
        return $text;
    }
    
    return 'ERROR'; // תשובה לא צפויה
}

/**
 * [חדש] פונקציית עזר להעתקה ושמירת מיפוי (לבדיקה ידנית)
 */
function copy_and_map_file($source_path, $dest_path) {
    $api_params = [
        'action' => 'copy',
        'what'   => $source_path,
        'target' => $dest_path
    ];
    $api_response = call_yemot_api('FileAction', $api_params);

    if ($api_response && $api_response['responseStatus'] == 'OK') {
        $mappings = load_mappings();
        add_mapping($mappings, $dest_path, $source_path);
        save_mappings($mappings);
        return true;
    }
    return false;
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
    // --- [לוגיקה חדשה] ---
    // בדוק אם זו בקשת דיווח (משלוחות המקור)
    if (is_source_extension($current_file_path)) {
        
        $source_path = $current_file_path;
        $temp_local_file = TEMP_DIR . '/' . uniqid('audio_') . '_' . $file_name . '.wav';
        $ai_decision = 'ERROR'; // ברירת מחדל למקרה שההורדה נכשלת

        // 1. הורדת הקובץ לשרת
        if (download_yemot_file($source_path, $temp_local_file)) {
            // 2. תמלול וניתוח AI
            $transcript = transcribe_audio_file($temp_local_file);
            $ai_decision = call_ai_api($transcript);
            
            // מחיקת הקובץ הזמני
            @unlink($temp_local_file);
        } else {
            // ההורדה נכשלה, AI לא יכול לנתח
            $ai_decision = 'ERROR'; // יגרום להעברה לבדיקה ידנית
            $response_message = RESPONSE_ON_DOWNLOAD_FAIL;
        }

        // 3. קבלת החלטה
        if ($ai_decision === 'DELETE') {
            // AI בטוח -> העבר לבידוד (922)
            $quarantine_path = 'ivr2:/' . DEST_EXTENSION_QUARANTINE . '/' . $file_name;
            $api_params_copy = [
                'action' => 'copy', 'what' => $source_path, 'target' => $quarantine_path
            ];
            $api_response_copy = call_yemot_api('FileAction', $api_params_copy);
            
            if ($api_response_copy && $api_response_copy['responseStatus'] == 'OK') {
                // העתקה לבידוד הצליחה, מחק את המקור
                $api_params_delete = ['action' => 'delete', 'what' => $source_path];
                call_yemot_api('FileAction', $api_params_delete);
            }
            $response_message = RESPONSE_ON_AI_QUARANTINE;

        } else {
            // AI מסופק (UNCERTAIN), תקין (IGNORE) או שגיאה (ERROR)
            // -> העבר לבדיקה ידנית (8000)
            $manual_path = 'ivr2:/' . DEST_EXTENSION_MANUAL . '/' . $file_name;
            if (copy_and_map_file($source_path, $manual_path)) {
                $response_message = RESPONSE_ON_AI_MANUAL;
            } else {
                $response_message = "id_list_message=t-שגיאה בהעתקת קובץ לבדיקה ידנית";
            }
        }
    } 
    // --- [לוגיקה קיימת] ---
    // בדוק אם זו בקשת מחיקה ידנית (משלוחת היעד 8000)
    elseif (strpos($current_file_path, 'ivr2:/' . DEST_EXTENSION_MANUAL . '/') === 0) {
        
        $dest_path = $current_file_path;
        $mappings = load_mappings();
        $source_path = find_source($mappings, $dest_path);

        // 1. מחק את קובץ היעד (מ-8000)
        $api_params_dest = ['action' => 'delete', 'what' => $dest_path];
        $api_response_dest = call_yemot_api('FileAction', $api_params_dest);
        
        $deleted_source = false;
        
        // 2. אם המקור נמצא, מחק גם אותו
        if ($source_path) {
            $api_params_source = ['action' => 'delete', 'what' => $source_path];
            $api_response_source = call_yemot_api('FileAction', $api_params_source);
            if ($api_response_source && $api_response_source['responseStatus'] == 'OK') {
                $deleted_source = true;
            }
        }

        // 3. עדכן DB והחזר תשובה
        if ($api_response_dest && $api_response_dest['responseStatus'] == 'OK') {
            remove_mapping($mappings, $dest_path); // הסר מהמיפוי
            save_mappings($mappings);
            
            if ($deleted_source) {
                $response_message = RESPONSE_ON_DELETE_SUCCESS;
            } else if ($source_path) {
                $response_message = RESPONSE_ON_DELETE_PARTIAL_ERROR;
            } else {
                $response_message = RESPONSE_ON_DELETE_NO_SOURCE;
            }
        } else {
             $error = $api_response_dest ? $api_response_dest['message'] : 'Network Error';
             $response_message = "id_list_message=t-שגיאה בעת מחיקת קובץ היעד: " . $error;
        }
    }

} catch (Exception $e) {
    $response_message = "id_list_message=t-שגיאת שרת קריטית: " . $e->getMessage();
}

// החזר תשובה למערכת הטלפונית
echo $response_message;

?>
