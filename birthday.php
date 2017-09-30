<?
#!/usr/bin/php
//print_r($_SERVER['DOCUMENT_ROOT']);
//$_SERVER['DOCUMENT_ROOT'] = realpath(dirname(__FILE__)); // на один уровень выше, чем /cron/
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS',true);
define('SITE_ID', 's2');
ini_set('max_execution_time', 100);
set_time_limit(0);
$DOCUMENT_ROOT = $_SERVER['DOCUMENT_ROOT'];
require($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');

//Подключаем ядро
use Bitrix\Main;
use Bitrix\Sale\Internals;
\Bitrix\Main\Loader::includeModule('sale');
$cUser = new CUser;

//Кол-во дней вперед на которые смотрим ДР
$daysAfter = 14;

$nowDate = date($DB->DateFormatToPHP(CSite::GetDateFormat("SHORT")), time());
$tmpDate = ConvertDateTime($nowDate, "MM-DD", "ru");

//Собираем массив $arResult пользователей с ДР
for ($i=0; $i <= $daysAfter ; $i++) {

    $tmpDate = MakeTimeStamp($tmpDate, "MM-DD");
    $arrAdd = array(
        "DD"			=> $i,
        "MM"			=> 0,
        "YYYY"		=> 0,
        "HH"			=> 0,
        "MI"			=> 0,
        "SS"			=> 0,
    );
    $tmpDate = AddToTimeStamp($arrAdd, $tmpDate);
    $tmpDate = date("m-d", $tmpDate);
    $filter = Array
    (
        "ACTIVE" => "Y",
        "ID" => 1,
        "PERSONAL_BIRTHDAY_DATE"=> $tmpDate
    );
    $rsUsers = CUser::GetList(
            ($by="timestamp_x"),
            ($order="desc"),
            $filter,
            array(
                    "SELECT" => array(
                            "UF_BIRTH"
                    )
            )
    ); // выбираем пользователей
    while ($arUser = $rsUsers->Fetch()){
        $dateFormat = FormatDate("j F", MakeTimeStamp($arUser["PERSONAL_BIRTHDAY"]));
        $arResult[$dateFormat][$arUser["ID"]]=$arUser;
    }
}
// Переберам ДР пользователей
foreach ($arResult as $days):
    foreach ($days as $day):
        //Проверяем был ди выдан купон в этом году
        if($day["UF_BIRTH"]==NULL && $day["UF_BIRTH"]!=date("Y")):

            //Генерируем купон за 7 дней до и после ДР в этом году
            $active_from = \Bitrix\Main\Type\DateTime::createFromTimestamp(strtotime("-7 day",mktime(0, 0, 0, explode('.', $day["PERSONAL_BIRTHDAY"])[1], explode('.', $day["PERSONAL_BIRTHDAY"])[0], date("Y"))));
            $active_to = \Bitrix\Main\Type\DateTime::createFromTimestamp(strtotime("+7 day",mktime(0, 0, 0, explode('.', $day["PERSONAL_BIRTHDAY"])[1], explode('.', $day["PERSONAL_BIRTHDAY"])[0], date("Y"))));
            $coupon = Internals\DiscountCouponTable::generateCoupon(true);

            $arCouponFields["COUNT"] = 1;
            $arCouponFields["COUPON"] = array(
                "COUPON" => $coupon,
                "DISCOUNT_ID" => (int) "37",
                "TYPE" => \Bitrix\Sale\Internals\DiscountCouponTable::TYPE_ONE_ORDER,
                "ACTIVE_FROM" => $active_from,
                "ACTIVE_TO" => $active_to,
                "CREATED_BY" => $day["ID"],
                "MAX_USE" => (int) 1,
            );

            $couponsResult = \Bitrix\Sale\Internals\DiscountCouponTable::add($arCouponFields["COUPON"]);
            if (!$couponsResult->isSuccess()) {
                $errors = $couponsResult->getErrorMessages();
                echo "<pre>".print_r($errors, true)."</pre>";
            } else {
                $cUser->Update($day["ID"],array("UF_BIRTH"=>date("Y")));
                echo "<pre>".print_r($couponsResult, true)."</pre>";

                // Отправляем письмо с промокодом
                $arMailFields['EMAIL'] = $day['EMAIL'];
                $arMailFields['PROMOCODE'] = $coupon;
                CEvent::Send('SUBSCRIBE_PROMOCODE', 's2', $arMailFields, "N", 38);

            }
        endif;
    endforeach;
endforeach;

//require($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_after.php');
?>