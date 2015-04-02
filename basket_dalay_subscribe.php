<?php
/**
 * Created by PhpStorm.
 * User: domackii
 */

use \Bitrix\Main;
use \Bitrix\Main\Type as FieldType;

#отключение статистики и агентов
define('STOP_STATISTICS', true);
define('NO_AGENT_CHECK', true);
define('STATISTIC_SKIP_ACTIVITY_CHECK', true);

#======================================================
# настройки работы скрипта
#======================================================
$debug = false;
$site_id = 's2';
$day_from = 30;
$users_in_one_step = 2;
$data_file = dirname(__FILE__).'/load.dat';
$_SERVER['DOCUMENT_ROOT'] = '';

# проверка DOCUMENT_ROOT
if(!file_exists($_SERVER['DOCUMENT_ROOT']))
    die("Please set DOCUMENT_ROOT in this script \n\n");

# подключаем bitrix
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

$last_user_id = 0;
$aUserEmail = array();
$date_from = FieldType\DateTime::createFromTimestamp(time() - 86400 * $day_from); #выборка товаров за последние 30 дней

#======================================================
# получаем послдениего обработанного пользователя
#======================================================
if(file_exists($data_file))
    $last_user_id = (int)file_get_contents($data_file);

#подключаем модуль интернет магазина
if(Main\Loader::includeModule('sale'))
{
    $start = microtime();
    $start_total = microtime();

    if($debug && $last_user_id > 0)
        echo "sript load form user $last_user_id \n";

    #======================================================
    # Поиск пользователей с отложенными товарами
    # предварительный поиск пользователей позволит сделать пошаговую выборку и отправку уведомлений
    #======================================================
    $rsBasket = \Bitrix\Sale\BasketTable::getList(array(
        'filter' => array(
            '>DATE_INSERT' => $date_from,
            'DELAY' => 'Y',             # отложенные
            '=ORDER_ID' => false,       # товар не в заказе
            '>PRODUCT.QUANTITY' => 0,   # товар в наличии
            'LID' => $site_id,          # сайт
            '!FUSER.USER.ID' => false,  # отбираем зарегистрированных пользователей для определения Email
            'FUSER.USER.ACTIVE' => 'Y', # отбираем активных пользователей
            '>FUSER.USER.ID' => $last_user_id, #для пошаговой выборки
        ),
        'select' => array(
            'FUSER_ID',
            'FUSER.USER.ID',
            'FUSER.USER.EMAIL',
            'FUSER.USER.NAME',
            'FUSER.USER.LAST_NAME',
        ),
        'group' => array(
            'FUSER_ID'
        ),
        'order' => array(
            'FUSER.USER.ID' => 'ASC' #для пошаговой выборки по пользователям
        ),
        'limit' => $users_in_one_step, #для пошаговой выборки
    ));

    if($debug)
        echo 'select '.$rsBasket->getSelectedRowsCount().' users, time: '.(microtime() - $start)."\n";

    $aUsers = array();
    while($arUser = $rsBasket->fetch()){
        $aUsers[$arUser['FUSER_ID']] = $arUser;

        $last_user_id = $arUser['SALE_BASKET_FUSER_USER_ID'];
    }

    if(empty($aUsers))
        $last_user_id = 0;

    $start = microtime();

    #=============================================
    # для каждого пользователя отберем отложенные
    # товары за последние 30 дней товары
    #=============================================

    foreach($aUsers as $fuser_id => $aUser)
    {
        if($debug)
            echo ' *** select for fuser_id = '.$fuser_id." *** \n";

        $rsBasket = \Bitrix\Sale\BasketTable::getList(array(
            'filter' => array(
                '>DATE_INSERT' => $date_from,
                'DELAY' => 'Y',             # отложенные
                '=ORDER_ID' => false,       # товар не в заказе
                '>PRODUCT.QUANTITY' => 0,   # товар в наличии
                'LID' => $site_id,          # сайт
                'FUSER_ID' => $fuser_id,    # отбираем для выбранного пользователя
            ),
            'select' => array(
                'NAME',
                'PRODUCT_ID',
                'PRODUCT.QUANTITY',
                'FUSER.USER.ID',
                ),
            ));

        if($debug)
            echo 'select '.$rsBasket->getSelectedRowsCount().' products for user '.$user_id.', time: '.(microtime() - $start)."\n";

        $arProducts = array();

        while($aBasket = $rsBasket->fetch())
        {
            $arProducts[$aBasket['PRODUCT_ID']] = array(
                'NAME' => $aBasket['NAME']
                );
        }

        $start = microtime();
        #=============================================
        # проверяем наличие товваров в заказах пользователя а последние 30 дней
        #=============================================
        $rsBasket = \Bitrix\Sale\BasketTable::getList(array(
            'filter' => array(
                '!ORDER_ID' => false,
                '>ORDER.DATE_INSERT' => FieldType\DateTime::createFromTimestamp(time() - 86400 * 30),
                'FUSER.USER.ID' => $user_id,
                'PRODUCT_ID' => array_keys($arProducts),
                'LID' => $site_id
            ),
            'select' => array(
                'PRODUCT_ID',
                'FUSER_ID',
                'LID',
            )
        ));

        if($debug)
            echo "check product in orders: ".(microtime() - $start)."\n";

        while($aBasket = $rsBasket->fetch())
            unset($arProducts[$aBasket]);

        #если среди отложенных все уже были заказаны за последние 30 дней
        if(count($arProducts) < 1)
            continue;

        #список для письма
        $product_list = '';
        foreach ($arProducts as $product)
            $product_list .= $product['NAME']."\n";

        #========================================================
        # текст письма размесмещается в почтовых шаблонах
        #========================================================

        #поля почтового события
        $aFields = array(
            'USER_NAME' => $aUser['SALE_BASKET_FUSER_USER_NAME'],
            'USER_LAST_NAME' => $aUser['SALE_BASKET_FUSER_USER_LAST_NAME'],
            'USER_EMAIL' => $aUser['SALE_BASKET_FUSER_USER_EMAIL'],
            'PRODUCT_LIST' => $product_list,
            );

        $start = microtime();
        \CEvent::Send('TEST_BASKET_DELAY_MESSAGE', $site_id, $aFields);

        #==============================
        # ДЛЯ ПРИМЕРА !!!!!!!!!!!!!
        #==============================
        $message = "Добрый день, #USER_NAME#\n\nВ вашем вишлисте хранятся товары: \n#PRODUCT_LIST#.";
        $message = str_replace(array('#USER_NAME#', '#PRODUCT_LIST#'), array(trim($aFields['USER_NAME'].' '.$aFields['USER_LAST_NAME']), $product_list), $message);
        mail($aUser['SALE_BASKET_FUSER_USER_EMAIL'], 'Посетите наш магазин снова', $message);

        if($debug)
            echo "send ".count($arProducts)." product from basket, ".(microtime() - $start)."\n";

    }

    if($debug)
        echo "script time: ".(microtime() - $start_total)."\n";

    #======================================================
    # сохраним послдениего обработанного пользователя
    #======================================================
    file_put_contents($data_file, $last_user_id);

    
}
else
{
    die('Module sale not installed');
}
?>
