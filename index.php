<?php
// ===================================================================
//
// קובץ API מאוחד עבור ימות המשיח
// 1. העתקה/מחיקה של קבצים בין שלוחות (מבוסס על 'what')
// 2. התקנת שלוחה חדשה במערכת אחרת (מבוסס על 'target_system')
//   כולל תמיכה מלאה באימות דו-שלבי (2FA) טלפוני
//
// ===================================================================

// --- [אזור תצורה 1: העתקה/מחיקה] ---

// הגדר את הטוקן שלך (מספר מערכת:סיסמה)
define('YEMOT_TOKEN', '0733181406:80809090'); // ⚠️ החלף בטוקן שלך

// הגדר את כתובת ה-API למפתחים
define('YEMOT_API_URL', 'https://www.call2all.co.il/ym/api/');

// הגדר את שלוחות המקור (יכול להיות אחד או יותר)
define('SOURCE_EXTENSIONS', [
    '11', '90', '97', '94', '988', '9999' // שורה זו נוקתה
]);

// הגדר את שלוחת היעד (רק אחת)
define('DEST_EXTENSION', '800/54');

// קובץ מסד נתונים למיפוי קבצים
define('DB_FILE', __DIR__ . '/file_mappings.json'); // שימוש בנתיב מוחלט ל-Render

// הגדרות תגובות להעתקה/מחיקה
define('RESPONSE_ON_COPY_SUCCESS', 'id_list_message=t-הקובץ הועתק בהצלחה');
define('RESPONSE_ON_DELETE_SUCCESS', 'id_list_message=t-הקובץ נמחק בהצלחה');
define('RESPONSE_ON_DELETE_PARTIAL_ERROR', 'id_list_message=t-שגיאה במציאת הקובץ המקורי');
define('RESPONSE_ON_DELETE_NO_SOURCE', 'id_list_message=t-הקובץ בשלוחת המקור לא נמצא');


// --- [אזור תצורה 2: התקנת שלוחה] ---

// [ ⭐️ התיקון כאן - שורה זו והבאות נוקו מתווים בעייתיים ⭐️ ]
define('INSTALL_PATH', 'ivr2:/'); 

// שנה את התוכן הזה לתוכן השלוחה שברצונך להתקין
define('INSTALL_CONTENT', "type=routing_yemot\n" .
                         "routing_yemot_number=0733181406\n" . // ⚠️ שנה למספר הרצוי
                         "routing_yemot_end=yes\n");


// ===================================================================
// --- פונקציות עזר - אין צורך לערוך מכאן והלאה ---
// ===================================================================

// --- [פונקציות עזר: העתקה/מחיקה] ---

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
    if (isset($mappings[$dest_path])) unset($mappings[$dest_path]);
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

// --- [ ⭐️ התיקון כאן ⭐️ ] ---
// הפונקציה הוחלפה לשימוש ב-cURL במקום file_get_contents
function call_yemot_api_static_token($method, $params) {
    $url = YEMOT_API_URL . $method;
    $params['token'] = YEMOT_TOKEN; // מוסיף את הטוקן הסטטי
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200 && $response) {
        return json_decode($response, true);
    }
    return null; // שגיאת רשת או שגיאת API
}


// --- [פונקציות עזר: התקנת שלוחה (עם cURL)] ---
// (הפונקציה הזו נשארת ללא שינוי, עם cURL)
function call_yemot_api_dynamic_auth($service, $params) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, YEMOT_API_URL . $service);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200 && $response) {
        return json_decode($response, true);
    }
    return null;
}

// ⭐️ פונקציית עזר חדשה: ממירה את תוכן השלוחה למערך
function parse_ini_content_to_array($content) {
    $settings = [];
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
        $line = trim($line);
        // ודא שהשורה מכילה = ואינה הערה
        if (!empty($line) && strpos($line, '=') !== false && $line[0] !== ';') {
            list($key, $value) = explode('=', $line, 2);
            $settings[trim($key)] = trim($value);
        }
    }
    return $settings;
}

// ===================================================================
// --- לוגיקה ראשית (פונקציות נפרדות) ---
// ===================================================================

/**
 * מטפל בבקשות להעתקה/מחיקה
 */
function handle_copy_delete($params) {
    // ... (הפונקציה הזו נשארת ללא שינוי) ...
    $current_file_path = $params['what'];
    $file_name = basename($current_file_path);
    $response_message = "id_list_message=t-פעולה לא זוהתה";

    try {
        if (is_source_extension($current_file_path)) {
            $source_path = $current_file_path;
            $dest_path = 'ivr2:/' . DEST_EXTENSION . '/' . $file_name;
            $api_params = ['action' => 'copy', 'what' => $source_path, 'target' => $dest_path];
            $api_response = call_yemot_api_static_token('FileAction', $api_params);

            if ($api_response && $api_response['responseStatus'] == 'OK') {
                $mappings = load_mappings();
                add_mapping($mappings, $dest_path, $source_path);
                save_mappings($mappings);
                $response_message = RESPONSE_ON_COPY_SUCCESS;
            } else {
                // שים לב: אם ה-API נכשל, $api_response יהיה null (בגלל התיקון)
                $error = $api_response['message'] ?? 'Network Error or API Failure';
                $response_message = "id_list_message=t-שגיאה בעת העתקת הקובץ: " . $error;
            }
        } 
        elseif (strpos($current_file_path, 'ivr2:/' . DEST_EXTENSION . '/') === 0) {
            $dest_path = $current_file_path;
            $mappings = load_mappings();
            $source_path = find_source($mappings, $dest_path);
            $api_params_dest = ['action' => 'delete', 'what' => $dest_path];
            $api_response_dest = call_yemot_api_static_token('FileAction', $api_params_dest);
            $deleted_source = false;
            
            if ($source_path) {
                $api_params_source = ['action' => 'delete', 'what' => $source_path];
                $api_response_source = call_yemot_api_static_token('FileAction', $api_params_source);
                if ($api_response_source && $api_response_source['responseStatus'] == 'OK') {
                    $deleted_source = true;
                }
            }
            if ($api_response_dest && $api_response_dest['responseStatus'] == 'OK') {
                remove_mapping($mappings, $dest_path);
                save_mappings($mappings);
                if ($deleted_source) $response_message = RESPONSE_ON_DELETE_SUCCESS;
                else if ($source_path) $response_message = RESPONSE_ON_DELETE_PARTIAL_ERROR;
                else $response_message = RESPONSE_ON_DELETE_NO_SOURCE;
            } else {
                 $error = $api_response_dest['message'] ?? 'Network Error or API Failure';
                 $response_message = "id_list_message=t-שגיאה בעת מחיקת קובץ היעד: " . $error;
            }
        }
    } catch (Exception $e) {
        $response_message = "id_list_message=t-שגיאת שרת קריטית: " . $e->getMessage();
    }
    return $response_message;
}

/**
 * מטפל בבקשות להתקנת שלוחה (עם תמיכה ב-2FA)
 */
function handle_install_extension($params) {
    // שלב 3: אימות הקוד והתקנה
    if (isset($params['mfa_token']) && isset($params['mfa_method_id']) && isset($params['mfa_code'])) {
        $token = $params['mfa_token'];
        $method_id = $params['mfa_method_id'];
        $code = $params['mfa_code'];

        $validation_response = call_yemot_api_dynamic_auth('ValidationToken', [
            'token' => $token, 'method' => $method_id, 'code' => $code
        ]);

        if ($validation_response && $validation_response['responseStatus'] == 'OK' && ($validation_response['isPass'] ?? false) == true) {
            // קוד תקין! בצע התקנה עם הטוקן המאומת
            
            // ⭐️ התיקון: שימוש ב-UpdateExtension
            // 1. פרסר את תוכן השלוחה למערך
            $settings_array = parse_ini_content_to_array(INSTALL_CONTENT);
            
            // 2. הכן את הפרמטרים הבסיסיים
            $api_params = [
                'token' => $token,
                'path' => INSTALL_PATH // 'ivr2:45'
            ];
            
            // 3. אחד את כל הפרמטרים יחד
            $final_params = array_merge($api_params, $settings_array);
            
            // 4. קרא ל-API
            $upload_response = call_yemot_api_dynamic_auth('UpdateExtension', $final_params);
            // ===================================
            
            call_yemot_api_dynamic_auth('Logout', ['token' => $token]); // התנתקות

            if ($upload_response && $upload_response['responseStatus'] == 'OK') {
                return "id_list_message=t-ההתקנה בוצעה בהצלחה";
            } else {
                $error_msg = $upload_response['message'] ?? 'Upload failed or no response';
                $error_msg_for_tts = str_replace(['"', "'", ':', '/'], '', $error_msg); 
                return "id_list_message=t-הקוד אומת אך ההתקנה נכשלה. t-השגיאה שהתקבלה היא. t-" . $error_msg_for_tts;
            }
        } else {
            $error = $validation_response['message'] ?? 'Invalid code';
            return "id_list_message=t-הקוד שהוקש שגוי. " . $error . "t-אנא נסו שנית מההתחלה";
        }
    }

    // שלב 2: שליחת קוד אימות
    if (isset($params['mfa_token']) && isset($params['mfa_choice'])) {
        // ... (הלוגיקה הזו נשארת ללא שינוי) ...
        $token = $params['mfa_token'];
        $choice = $params['mfa_choice'];
        $method_id_to_use = null;

        if ($choice == '1' && isset($params['sms_id'])) $method_id_to_use = $params['sms_id'];
        if ($choice == '2' && isset($params['email_id'])) $method_id_to_use = $params['email_id'];

        if ($method_id_to_use) {
            $send_code_response = call_yemot_api_dynamic_auth('SendMFACode', [
                'token' => $token, 'method' => $method_id_to_use
            ]);

            if ($send_code_response && $send_code_response['responseStatus'] == 'OK') {
                $response_parts = [
                    "read=p-mfa_code=t-אנא הקישו את הקוד שקיבלתם, וסולמית,number,4,8,7,NO,yes,no,#",
                    "p-mfa_token=d-NONE," . $token,
                    "p-mfa_method_id=d-NONE," . $method_id_to_use
                ];
                return implode("&", $response_parts);
            } else {
                $error = $send_code_response['message'] ?? 'Send code failed';
                return "id_list_message=t-שגיאה בשליחת הקוד. " . $error;
            }
        } else {
            return "id_list_message=t-בחירה לא חוקית";
        }
    }

    // שלב 1: התחברות ראשונית ובדיקת 2FA
    if (isset($params['target_system']) && isset($params['target_password'])) {
        $login_response = call_yemot_api_dynamic_auth('Login', [
            'username' => $params['target_system'],
            'password' => $params['target_password']
        ]);

        if (!$login_response) {
            return "id_list_message=t-שגיאת רשת. אין תקשורת עם שרתי ימות המשיח";
        }

        // מקרה א': התחברות הצליחה (אין 2FA או IP לבן)
        if ($login_response['responseStatus'] == 'OK' && !empty($login_response['token'])) {
            $token = $login_response['token'];

            // ⭐️ התיקון: שימוש ב-UpdateExtension
            // 1. פרסר את תוכן השלוחה למערך
            $settings_array = parse_ini_content_to_array(INSTALL_CONTENT);
            
            // 2. הכן את הפרמטרים הבסיסיים
            $api_params = [
                'token' => $token,
                'path' => INSTALL_PATH // 'ivr2:45'
            ];
            
            // 3. אחד את כל הפרמטרים יחד
            $final_params = array_merge($api_params, $settings_array);
            
            // 4. קרא ל-API
            $upload_response = call_yemot_api_dynamic_auth('UpdateExtension', $final_params);
            // ===================================
            
            call_yemot_api_dynamic_auth('Logout', ['token' => $token]);

            if ($upload_response && $upload_response['responseStatus'] == 'OK') {
                return "id_list_message=t-ההתקנה בוצעה בהצלחה. אין צורך באימות דו שלבי";
            } else {
                // הוספנו דיבאג גם כאן
                $error_msg = $upload_response['message'] ?? 'Upload failed or no response';
                $error_msg_for_tts = str_replace(['"', "'", ':', '/'], '', $error_msg); 
                return "id_list_message=t-ההתחברות הצליחה אך ההתקנה נכשלה. t-השגיאה שהתקבלה היא. t-" . $error_msg_for_tts;
            }
        }

        // מקרה ב': נדרש אימות דו-שלבי
        if ($login_response['responseStatus'] == 'ERROR' && ($login_response['message'] ?? '') == 'MFA_REQUIRED') {
            // ... (הלוגיקה הזו נשארת ללא שינוי) ...
            $token = $login_response['token']; 
            $methods_response = call_yemot_api_dynamic_auth('GetMFAMethods', ['token' => $token]);
            
            if ($methods_response && $methods_response['responseStatus'] == 'OK' && !empty($methods_response['methods'])) {
                $methods = $methods_response['methods'];
                $prompt_parts = ["t-אימות דו שלבי נדרש"];
                $response_params = ["p-mfa_token=d-NONE," . $token];
                $choice_num = 1;
                $sms_id = null;
                $email_id = null;

                foreach ($methods as $method) {
                    if ($method['type'] == 'sms' && !$sms_id) {
                        $prompt_parts[] = "t-להקשת קוד מ-SMS למספר " . $method['name'] . " הקישו " . $choice_num;
                        $response_params[] = "p-sms_id=d-NONE," . $method['id'];
                        $sms_id = $method['id'];
                        $choice_num = 2; 
                    }
                    if ($method['type'] == 'email' && !$email_id) {
                        $prompt_parts[] = "t-להקשת קוד ממייל לכתובת " . $method['name'] . " הקישו " . $choice_num;
                        $response_params[] = "p-email_id=d-NONE," . $method['id'];
                        $email_id = $method['id'];
                        $choice_num = 3; 
                    }
                }
                
                if ($choice_num == 1) { 
                    return "id_list_message=t-נדרש אימות דו שלבי, אך לא מוגדרות שיטות אימות במערכת היעד.";
                }

                $max_choice = $choice_num - 1;
                array_unshift($prompt_parts, "read=p-mfa_choice=t-בחרו שיטת אימות," . $max_choice . "," . $max_choice . ",7,Digits,no,no,");
                
                return implode("&", array_merge($prompt_parts, $response_params));
                
            } else {
                return "id_list_message=t-שגיאה בקבלת שיטות אימות.";
            }
        }

        // מקרה ג': התחברות נכשלה (סיסמה שגויה)
        $error = $login_response['message'] ?? 'Login failed';
        return "id_list_message=t-ההתחברות למערכת היעד נכשלה. " . $error;
    }
    
    // אם הגענו לכאן, כנראה חסרים פרמטרים
    return "id_list_message=t-שגיאה. חסרים פרמטרים נדרשים.";
}

// ===================================================================
// --- נתב ראשי (ROUTER) ---
// ===================================================================

// קבל את כל הפרמטרים שנשלחו מימות המשיח
$request_params = $_REQUEST;
$final_response = "id_list_message=t-פעולה לא מזוהה או חוסר בפרמטרים"; // ברירת מחדל לשגיאה

// --- ניתוב ---
if (isset($request_params['what'])) {
    // זו בקשה להעתקה/מחיקה (מהשלוחות הקיימות שלך)
    $final_response = handle_copy_delete($request_params);
    
} elseif (isset($request_params['target_system']) || isset($request_params['mfa_token'])) {
    // זו בקשה להתקנת שלוחה (חדשה או המשך תהליך 2FA)
    $final_response = handle_install_extension($request_params);
}

// הדפסת התגובה חזרה לימות המשיח
echo $final_response;
exit;
?>
