<?php
$login = "login";
$password = "password";
$to = "mail to"; //адрес почты для отправки сообщения о бронировании
$headers = "from mail"; // от кого сообщение
$telegrambot="token"; //токен телеграм бота
$telegram_chat_id="chat_id";

//Список IP адресов, с которых разрешен вход
$white_list = array("IP 1", "IP 2", "IP 3", "IP 4", "IP 5", "IP 6", "IP 7");			
	
//Проверяем параметр health_check для мониторинга Zabbix
if(isset($_GET['health_check']) == '1'){
	die ("OK");
}

$logFileName = "log.txt"; 
date_default_timezone_set('Asia/Novosibirsk');

//Переменная с IP зашедшего на сайт
$ip_user = $_SERVER['REMOTE_ADDR'];

//Проверка если IP зашедшего нет в списке, то он ничего не может сделать
if (in_array($ip_user, $white_list)){
$check_denied = 'Access is allowed';
//echo $check_denied;
}
else{
$error_denied = 'Access is denied';
$log_message = date('d.m.Y H:i:s') . " - debug - " . $ip_user . " - доступ этому IP запрещен \n"; 
file_put_contents($logFileName, $log_message, FILE_APPEND); 
die("$error_denied");
}

if(isset($_SERVER['PHP_AUTH_USER']) && ($_SERVER['PHP_AUTH_PW']==$password) && (strtolower($_SERVER['PHP_AUTH_USER'])==$login))
{	
	$check_auth = 'Авторизация прошла успешно';
	$log_message = date('d.m.Y H:i:s') . " - debug - " . $check_auth . "\n"; 
	file_put_contents($logFileName, $log_message, FILE_APPEND); 
	
	//Если Пост запрос пустой 
	if ($_SERVER['REQUEST_METHOD'] == 'GET')
	{
		$error_get_request = "get запросу дальше нельзя";
		die("$error_get_request");
	}

	//Загружаем данные из файла в строку и превращаем строку в объект
	$string_json = file_get_contents("php://input");   
	$data = json_decode($string_json);
	$log_message = date('d.m.Y H:i:s') . " - debug - Принятый json - " . $string_json . "\n"; 
	file_put_contents($logFileName, $log_message, FILE_APPEND, iconv("UTF-8")); 
	
	// Отлавливаем ошибки возникшие при превращении
	switch (json_last_error()) 
	{
		case JSON_ERROR_NONE:
			$data_error = '';
			break;
		case JSON_ERROR_DEPTH:
			$data_error = 'Достигнута максимальная глубина стека';
			break;
		case JSON_ERROR_STATE_MISMATCH:
			$data_error = 'Неверный или не корректный JSON';
			break;
		case JSON_ERROR_CTRL_CHAR:
			$data_error = 'Ошибка управляющего символа, возможно верная кодировка';
			break;
		case JSON_ERROR_SYNTAX:
			$data_error = 'Синтаксическая ошибка';
			break;
		case JSON_ERROR_UTF8:
			$data_error = 'Некорректные символы UTF-8, возможно неверная кодировка';
			break;  
		default:
			$data_error = 'Неизвестная ошибка';
			break;
	}
	// Если ошибки есть, записываем в лог
	if($data_error !='')
	{
		$log_message = date('d.m.Y H:i:s') . " - error - " . $data_error . "\n";
		file_put_contents($logFileName, $log_message, FILE_APPEND); 
	}
	//Присваиваем данные переменным
	$name = $data->name;
	$dt_start = $data->dt_start;
	$doctor_id = $data->doctor_id;
	$doctor_name = $data->doctor_name;
	$specialty = $data->specialty;
	$dt_end = $data->dt_end;
	$comment = $data->comment;
	$birthday = $data->birthday;
	$filial_id = $data->filial_id;
	$filial_name = $data->filial_name;
	$phone = $data->phone;

	//Преобразование времени в Новосибирское
	$date_start_without_T = str_replace("T", " ", $dt_start);
	$normal_date_start = str_replace("Z", "", $date_start_without_T);
	$time_start = strtotime($normal_date_start .'UTC');
	$date_start = date('d.m.Y H:i', $time_start);	

	$date_end_without_T = str_replace("T", " ", $dt_end);
	$normal_date_end = str_replace("Z", "", $date_end_without_T);
	$time_end = strtotime($normal_date_end .'UTC');
	$date_end = date('H:i', $time_end);
	$time_of_receipt =  $date_start . " - " . $date_end; 

	$subject = "Новое бронирование"; //Тема письма
	$message = "Пациент: $name ($phone)\r\nДата рождения: $birthday\r\nДата приема: $time_of_receipt\r\nВрач: $doctor_name\r\nКлиника: $filial_name";

	//Функция отправки записи в телеграм
	function telegram($msg, $telegrambot, $telegram_chat_id) 
	{
        $url='https://api.telegram.org/bot'.$telegrambot.'/sendMessage';$data=array('chat_id'=>$telegram_chat_id,'text'=>$msg);
        $options=array('http'=>array('method'=>'POST','header'=>"Content-Type:application/x-www-form-urlencoded\r\n",'content'=>http_build_query($data),),);
        $context=stream_context_create($options);
        $result=file_get_contents($url,false,$context);
        return $result;
	}

	// Отправка записи в телеграм
	try 
	{
		$result = telegram ($message, $telegrambot, $telegram_chat_id);
		$telegram_result = json_decode($result);
		$status_telegram_result = $telegram_result->ok;
		$log_message = date('d.m.Y H:i:s') . " - debug - Телеграм вернул " . $status_telegram_result . "\n";
		file_put_contents($logFileName, $log_message, FILE_APPEND);
	}
	catch (Exception $e)
	{
		$telegram_error = $e->getMessage();
		$log_message = date('d.m.Y H:i:s') . " - error - " . $telegram_error . "\n";
		file_put_contents($logFileName, $log_message, FILE_APPEND);
	}

	//Отправляем письмо на почту
	if (mail($to, $subject, $message, $headers))
	{
		//Если ошибок нет
		$json_answer_ok = array('response_status'=>'success');
		echo(json_encode($json_answer_ok));
		$log_message = date('d.m.Y H:i:s') . " - debug - Отправленное письмо - \n" . $message . "\n";
		file_put_contents($logFileName, $log_message, FILE_APPEND);
	}
	else
	{
		//Если произошла ошибка
		$json_answer_not_ok = array('response_status'=>'error');
		echo(json_encode($json_answer_not_ok));
		$log_message = date('d.m.Y H:i:s') . " - error - Письмо не отправилось \n";
		file_put_contents($logFileName, $log_message, FILE_APPEND);
	}
}
else
{
	$error_auth = 'Авторизация не успешно';
	$log_message = date('d.m.Y H:i:s') . " - debug - " . $error_auth . "\n"; 
	file_put_contents($logFileName, $log_message, FILE_APPEND); 
	// Если ошибка при авторизации, выводим соответствующие заголовки и сообщение
    header('WWW-Authenticate: Basic realm="Backend"');
    header('HTTP/1.0 401 Unauthorized');
}		

?>