<?php
/* -= Developed by a.s.tseluyko@gmail.com =- */
// -= О П Ц И И =-
require("config.php");
// Технические настройки скрипта
header('Content-Type: text/html; charset=utf-8');
ini_set('memory_limit', '-1');
// -=-=-=-=-=-=-=-

// -= Функции инкапсуляции технических аспектов =-
// Функция печати логов, добавляет "date n time now" и перенос строки
function printLog($text) { echo sprintf("[%s] %s", date("Y-m-d H:i:s"), $text) . "\n"; }
// Функция преобразования текста в ключ индекса, убирает пробелы, переводит в верхний регистр и добавляет префикс "_"
function str2idx($str) { return "_" . strtoupper( str_replace(' ', '', (string)$str) ); }
// Функция генерации ассоциативного массива индексов, использует str2idx
function genIdxs($array, $val_key, $idx_keys, $filter_func=NULL) {
    $idxs = [];
    foreach ($array as $item) {
    	if ($filter_func && !$filter_func($item)) { continue; } 
    	if (is_string($idx_keys)){
    		foreach (preg_split("/\s?;\s?/", $item[$idx_keys]) as $idx) {
    			if ($idx) { $idxs[str2idx($idx)] = str2idx((string)$item[$val_key]); }
    		} unset($idx);
    	} else {
    		foreach ($idx_keys as $idx_key) {
    			foreach (preg_split("/\s?;\s?/", $item[$idx_key]) as $idx) {
    				if ($idx) { $idxs[str2idx($idx)] = str2idx((string)$item[$val_key]); }
    			}
    		} unset($idx_key);
    	}
    } unset($item);
    return $idxs;
}
// Функция сравнения изображений
function compareImages($image1, $image2) {
    $compare_result = $image1->compareImages($image2, IMAGICK_METRIC);
    return (int)$compare_result[1] > THRESHOLD_SIMILARITY_VALUE;
}
// Функция исполнения SQL-запросов в БД, инкапсулирующая все ужасы взаимодействия с БД MySQL на PHP
function execSQL($sql, $mode="fetch_assoc") {
    // Проверяем коннект к БД, в случае проблем - пытаемся переподключ
    if (!$GLOBALS["mysqli"] || $GLOBALS["mysqli"]->connect_errno) { 
    	$GLOBALS["mysqli"] = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME); 
    	if ($GLOBALS["mysqli"]->connect_errno) {
    		throw new Exception("Can't connect to DB: (".$GLOBALS["mysqli"]->connect_errno.") ".$GLOBALS["mysqli"]->connect_error);
    	}
    	printf("default charset: %s\n", $GLOBALS["mysqli"]->character_set_name());
    	/* изменение набора символов на utf8 */
    	if (!$GLOBALS["mysqli"]->set_charset("utf8")) {
    		throw new Exception("set charset utf8 error: %s\n", $GLOBALS["mysqli"]->error);
    	} else { printf("current charset: %s\n", $GLOBALS["mysqli"]->character_set_name()); }
    }
    $_result = $GLOBALS["mysqli"]->query($sql);
    if (!$_result) { printLog("SQL ERROR: ". $GLOBALS["mysqli"]->error . "\n executable SQL: " . $sql . "\n\n"); }
    if (is_bool($_result)) { return $_result; }
    elseif ($mode==="num_rows") { return $_result->num_rows; }
    elseif ($mode==="fetch_assoc") {
    	$result = [];
    	while($row = $_result->fetch_assoc()) {
    		reset($row);
    		$key = str2idx($row[key($row)]);
    		$result[$key] = $row;
        } unset($row);
        return $result;
    }
    throw new Exception("Recieved unexpected mode (".$mode.") or query result by execute SQL: ".$sql );
}
// -=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-

// -= Старт работы скрипта =-
$start = microtime(true);
printLog("Updater script started");
// Инициализация глобальных переменных, счетчиков
$GLOBALS["mysqli"] = NULL;
$kingsilk_offers_count = 0;
// Проверка хранилища фотографий
if (!is_dir(IMAGES_PATH)) throw new Exception("ERROR: images path not found!");
$IMAGES_FULL_PATH = IMAGES_PATH . IMAGE_PATH_PREFIX;
if (!is_dir($IMAGES_FULL_PATH)) mkdir($IMAGES_FULL_PATH);
// -=-=-=-=-=-=-=-=-=-=-=-=-=-

// -= Получение YML-данных от поставщика Кингсилк, формирование индексов =-
$yml_catalog = new SimpleXMLElement(
    file_get_contents(YML_URL_KINGSILK)
);
// Формирование индекса импортируемых категорий по id'шнику категории поставщика
$GLOBALS['cats_outer_idxs'] = [];
foreach ($yml_catalog->categories->category as $cat){
    $GLOBALS['cats_outer_idxs'][str2idx((string)$cat["id"])] = $cat;
} unset($cat);
// Группировка предложений поставщика по схожести картинок,
// формирование древовидного индекса по md5 хэшу картинок
$offers_groups_idxs = [];
foreach ($yml_catalog->offers->offer as $offer) {
    // Отсеиваем не опубликованные товары
    if ((string)$offer["available"] != "true"){
    	continue;
    }
    $kingsilk_offers_count++;
    $hash = NULL;
    $img_url = NULL;
    // Пытаемся получить hash одной из фотографий
    foreach ($offer->picture as $picture) {
    	$img_url = (string)$picture;
    	$hash = "_" . strtoupper((string)hash_file("md5", $img_url));
    	if ($hash) { break; } 
    } unset($picture);
    // Если не получается пропускаем это предложение и не импортируем его
    if (!$hash) {
    	printLog("Product with article ".(string)$offer->article.", url: ".(string)$offer->url." hasn't pictures or received bad request from pictures urls. This product skipped and will not be imported!");
    	continue;
    }
    $image = new Imagick($img_url); // можно оптимизировать, перенеся создание картинки под следующий if, так как после if'a переменные не удаляются, то они будут доступны и далее
    $image->adaptiveResizeImage(32,32);
    // Если на данный момент в индексе нет идентичной картинки, то пытаемся найти похожую
    if (!array_key_exists($hash, $offers_groups_idxs)) {
    	foreach ($offers_groups_idxs as $exists_hash => $offers_group){
    		if (compareImages($image, $offers_group['image'])){
    			$hash = $exists_hash;
    			break;
    		}
    	} unset($exists_hash, $offers_group);
    }
    // Если hash существует в индексе групп предложений, то добавляем в группу данное предложение
    if (array_key_exists($hash, $offers_groups_idxs)) {
    	$offers_groups_idxs[$hash]["offers"][] = $offer;
    // иначе создаем новую группу предложений
    } else {
    	$offers_groups_idxs[$hash] = [
    		"offers" => [ $offer ],
    		"image" => $image
    	];
    }
} unset($offer, $img_url, $hash, $image);
printLog(
    "Built the index of KingSlik's offers:
     - importing offers count: " . $kingsilk_offers_count . "
     - offers group by compare images count: " . count($offers_groups_idxs)
);
unset($kingsilk_offers_count, $yml_catalog, $offer, $img_url, $image);
// -=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=--=-=-=-=-=-=-=-=-=-=-=-

// -= Получение данных из БД, формирование индексов =-
/* TODO Сделать бэкап БД и в случае хоть наличия хоть одной ошибки при запросах к БД, восстановить БД по завершению скрипта!
 Пример реализации дампа и восстановления https://stackoverflow.com/questions/22217397/backup-and-restore-mysql-database-in-php
 При наличие ошибок SQL в данный момент скрипт не останавливается, см. строку 54 (фунцкия "execSQL"), а нужно прерывать выполнение скрипта,
 восстанавливать БД и завершать работу, логгируя данные события. */

// TODO добавить поддержку безконечного количества уровней вложенности
// Получение перечня категорий, формироване индекса по category ID, наименованию и синонимам
$GLOBALS['cats'] = execSQL("SELECT occd.`category_id`  AS 'category_id',
    							   occd.`name` 		   AS 'name', 
    							   occd.`meta_keyword` AS 'meta_keyword', 
    							   occ.`parent_id` 	   AS 'parent_id' 
    					    FROM `oc_category_description` AS occd 
    					    LEFT JOIN `oc_category` AS occ ON occ.`category_id`=occd.`category_id` 
    					    WHERE occd.`language_id`=1;");
$GLOBALS['cats_idxs'] = genIdxs(
    $GLOBALS['cats'], "category_id", [ "name", "meta_keyword" ],
    function ($item) { return $item["parent_id"] == 0; }
);
// Категории второго уровня складываем индекс группируя по родительским категориям, чтобы не затереть категории с одинаковым наименованием или синонимами
foreach ($GLOBALS['cats'] as $cat_id_idx => $cat){
    if ($cat["parent_id"] == 0){
    	$GLOBALS['cats_idxs'][$cat_id_idx] = genIdxs(
    		$GLOBALS['cats'], "category_id", [ "name", "meta_keyword" ],
    		function ($item) use ($cat_id_idx) { return str2idx($item["parent_id"]) === $cat_id_idx; }
    	);
    }
} unset($cat_id_idx, $cat);
// В случае отсутствия категории "неотсортированные" - создаем
if (!array_key_exists(str2idx(UNSORTED_CAT_ID), $GLOBALS['cats'])) {
    $datetimenow = date("Y-m-d H:i:s");
    // Создание записи в таблице `oc_category`
    execSQL(
    	"INSERT INTO `oc_category` (`category_id`,`image`,`top`,`column`,`status`,`date_added`,`date_modified`)
    	VALUES (".UNSORTED_CAT_ID.",'',1,1,0,'".$datetimenow."','".$datetimenow."');"
    );
    unset($datetimenow);
    // Создание записи в таблице `oc_category_description`
    execSQL(
    	"INSERT INTO `oc_category_description` (`category_id`,`language_id`,`name`,`description`,`meta_title`,`meta_description`,`meta_keyword`)
    	VALUES (".UNSORTED_CAT_ID.",1,'Неотсортированные при импорте','','Неотсортированные при импорте','','');"
    );
    // Создание записи в таблице `oc_category_path`
    execSQL("INSERT INTO `oc_category_path` (`category_id`,`path_id`,`level`)
    		VALUES (".UNSORTED_CAT_ID.",".UNSORTED_CAT_ID.",0);");
}

// Получение перечня производителей, формирование индекса по manufacturer ID и наименованию
$GLOBALS['mans'] = execSQL("SELECT `manufacturer_id`, `name` FROM oc_manufacturer");
$GLOBALS['mans_idxs'] = genIdxs($GLOBALS['mans'], "manufacturer_id", "name");
// Получение ID производителя Кингсилк, создание, в случае его отстутсвия
if (MAN_KINGSILK_ID){
    $GLOBALS['kingsilk_man_id'] = array_key_exists(str2idx(MAN_KINGSILK_ID), $GLOBALS['mans']) 
    			 ? MAN_KINGSILK_ID
    			 : NULL;
}

// В случае отсутствия производителя - создаем
if (!$GLOBALS['kingsilk_man_id']){
    // Создание записи в таблице `oc_manufacturer`
    execSQL(
    	"INSERT INTO `oc_manufacturer` (`name`,`image`,`sort_order`)
    	VALUES ('Кингсилк','',0);"
    );
    $GLOBALS['kingsilk_man_id'] = (string)$GLOBALS["mysqli"]->insert_id;
    // Создание записи в таблице `oc_manufacturer_to_store`
    execSQL(
    	"INSERT INTO `oc_manufacturer_to_store` (`manufacturer_id`,`store_id`)
    	VALUES (".$GLOBALS['kingsilk_man_id'].",0);"
    );
}
// Получение перечня товаров, формирование индекса по product ID и наименованию
$GLOBALS['prods'] = execSQL("SELECT p.product_id AS 'product_id', pd.meta_keyword AS 'meta_keyword'
    						 FROM oc_product AS p 
    						 LEFT JOIN oc_product_description AS pd ON p.product_id=pd.product_id 
    						 WHERE pd.language_id=1");
$GLOBALS['prods_idxs'] = genIdxs($GLOBALS['prods'], "product_id", "meta_keyword");
// Получение перечня опций товаров, формирование индекса по option ID и наименованиб
$GLOBALS['opts'] = execSQL("SELECT `option_id`,`name` FROM `oc_option_description` WHERE `language_id`=1;");
$GLOBALS['opts_idxs'] = genIdxs($GLOBALS['opts'], "option_id", "name");
// Получение перечня значений опций товаров, формирование индекса по option value ID и наименованиям
$GLOBALS['opts_vals'] = execSQL("SELECT `option_value_id`,`name`,`option_id` 
    							 FROM `oc_option_value_description` WHERE `language_id`=1;");
$GLOBALS['opts_vals_idxs'] = [];
// Группируем значения опций по индексам ID самих опций, чтобы индексы наименований не затерли значения от разных опций
foreach ($GLOBALS['opts'] as $opt_id_idx => $opt){
    $GLOBALS['opts_vals_idxs'][$opt_id_idx] = genIdxs(
    	$GLOBALS['opts_vals'], "option_value_id", "name", 
        function ($item) use ($opt_id_idx) { return str2idx($item["option_id"]) === $opt_id_idx; }
    );
} unset($opt_id_idx, $opt);
    
// Получение перечня групп атрибутов, формирование индексов по attribute group ID и наименованию
$GLOBALS['attrs_groups'] = execSQL("SELECT `attribute_group_id`,`name` FROM `oc_attribute_group_description` WHERE `language_id`=1");
$GLOBALS['attrs_groups_idxs'] = genIdxs($GLOBALS['attrs_groups'], "attribute_group_id", "name");
// Получение перечня атрибутов, формирование индексов по attribute ID и наименованию
$GLOBALS['attrs'] = execSQL("SELECT oca.`attribute_id` AS 'attribute_id', 
    								ocad.`name` AS 'name', 
    								oca.`attribute_group_id` AS 'attribute_group_id',
    								ocbav.`attribute_value_id` AS 'attribute_value_id'
    					     FROM `oc_attribute` AS oca 
    					     LEFT JOIN `oc_attribute_description` AS ocad
    					     LEFT JOIN `oc_bf_attribute_value` AS ocbav
    					     ON oca.`attribute_id`=ocad.`attribute_id` WHERE ocad.`language_id`=1 AND ocbav.`language_id`=1;");
$GLOBALS['attrs_idxs'] = [];
// группировка по индексу идентификатора группы атрибутов, во избежании затирания индексов наименований атрибутов от разных групп
foreach ($GLOBALS['attrs_groups'] as $attrs_group_id_idx => $attrs_group) {
    $GLOBALS['attrs_idxs'][$attrs_group_id_idx] = genIdxs(
    	$GLOBALS['attrs'], "attribute_id", "name",
        function ($item) use ($attrs_group_id_idx) { return str2idx($item["attribute_group_id"]) === $attrs_group_id_idx; }
    );
} unset($attrs_group_id_idx, $attrs_group);
    
// Получение перечня групп фильтров, построение индекса по filter group ID и наименованию
$GLOBALS['filts_groups'] = execSQL("SELECT `filter_group_id`,`name` FROM `oc_filter_group_description` WHERE `language_id`=1");
$GLOBALS['filts_groups_idxs'] = genIdxs($GLOBALS['filts_groups'], "filter_group_id", "name");
// Получение фильтров, построение индексов по filter ID и наименованию
$GLOBALS['filts'] = execSQL("SELECT `filter_id`,`filter_group_id`,`name` FROM `oc_filter_description` WHERE `language_id`=1");
$GLOBALS['filts_idxs'] = [];
// группировка по индексу идентификатора фильтра, во избежании затирания индексов фильтров от разных групп
foreach ($GLOBALS['filts_groups'] as $filts_group_id_idx => $filts_group){
    $GLOBALS['filts_idxs'][$filts_group_id_idx] = genIdxs(
    	$GLOBALS['filts'], "filter_id", "name",
        function ($item) use ($filts_group_id_idx) { return str2idx($item["filter_group_id"]) === $filts_group_id_idx; }
    );
} unset($filts_group_id_idx, $filts_group);
printLog(
    "Built the index of the exists data:
     - products count (indexes): " . count($GLOBALS['prods']) . " (" . count($GLOBALS['prods_idxs']) . ")
     - categories count (indexes): " . count($GLOBALS['cats']) . " (" . array_reduce($GLOBALS['cats_idxs'], function($acc, $item) { return !is_array($item) ? $acc + 1 : $acc + count($item); }, 0) . ")
     - options count (indexes): " . count($GLOBALS['opts']) . " (" . count($GLOBALS['opts_idxs']) . ")
     - options values count (indexes): " . count($GLOBALS['opts_vals']) . " (" . array_reduce($GLOBALS['opts_vals_idxs'], function($acc, $item) { return $acc + count($item); }, 0) . ")
     - attributes group count (indexes): " . count($GLOBALS['attrs_groups']) . " (" . count($GLOBALS['attrs_groups_idxs']) . ")
     - attributes count (indexes): " . count($GLOBALS['attrs']) . " (" . array_reduce($GLOBALS['attrs_idxs'], function($acc, $item) { return $acc + count($item); }, 0) . ")
     - filters group count (indexes): " . count($GLOBALS['filts_groups']) . " (" . count($GLOBALS['filts_groups_idxs']) . ")
     - filters count (indexes): " . count($GLOBALS['filts']) . " (" . array_reduce($GLOBALS['filts_idxs'], function($acc, $item) { return $acc + count($item); }, 0) . ")
     - unsorted category ID: " . UNSORTED_CAT_ID . "
     - Kingsilk manufacturer ID: " . $GLOBALS['kingsilk_man_id'] . "
     - manufacturers count (indexes): " . count($GLOBALS['mans']) . " (" . count($GLOBALS['mans_idxs']) . ")"
);
// -=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-

// -= Сопоставление товаров и групп предложений, перегруппировка предложений =-
$opts_add_count = 0;
$opts_vals_add_count = 0;
$attrs_groups_add_count = 0;
$attrs_add_count = 0;
$filts_add_count = 0;
$cats_add_count = 0;
// Функция конвертации групп предложений от поставщика в товар на сайте
function convertOffersGroup2Product($offers_group, $exists_prod=NULL) {
    global $opts_add_count;
    global $opts_vals_add_count;
    global $attrs_groups_add_count;
    global $attrs_add_count;
    global $filts_add_count;
    global $cats_add_count;
    $prod = [
    	"price" => NULL,
    	"quantity" => 0,
    	"article" => NULL,
    	"images_idxs" => [],
    	"opt" => NULL,
    	"opt_vals" => NULL,
    	"attrs_groups" => [],
    	"attrs" => [],
    	"filts" => [],
    	"filts_groups" => [],
    	"articles" => [],
    	"cats" => [],
    	"name" => NULL,
    	"description" => NULL
    ];
    if ($exists_prod) { $prod = array_merge($exists_prod, $prod); }
    $diff_count = 0;
    $params = [];
    $img_sort_order = 0;
    foreach ($offers_group as $offer) {
    	if (!$prod["name"]) { $prod["name"] = str_replace((string)$offer->article, "", (string)$offer->model); }
    	if (!$prod["description"]) { $prod["description"] = (string)$offer->description; }
    	$article = (string)$offer->article;
    	$cat_outer = $GLOBALS['cats_outer_idxs'][str2idx((string)$offer->categoryId)];
    	$cat_name = (string)$cat_outer;
    	$cat_name_idx = str2idx($cat_name);
    	$is_found_cat = FALSE;
    	// Ищем подходящую категорию
    	if (!array_key_exists($cat_name_idx, $prod["cats"])){
    		if (!array_key_exists($cat_name_idx, $GLOBALS['cats_idxs'])){
    			foreach ($GLOBALS['cats_idxs'] as $idx => $cats_group_idx){
    				if (is_array($cats_group_idx) && array_key_exists($cat_name_idx, $cats_group_idx)) {
    					$is_found_cat = TRUE;
    					$cat = $GLOBALS['cats'][$GLOBALS['cats_idxs'][$idx][$cat_name_idx]];
    					$prod["cats"][$cat_name_idx] = $cat;
    					if (in_array((int)$cat["category_id"], PRODS_WTH_CAT_NAME_PREFIX_CAT_ID) 
    						&& strpos($cat["name"], $prod["name"]) === FALSE) {
    						$prod["name"] = $cat["name"] . " " . $prod["name"];
    					}
    				}
    			} unset($idx, $cats_group_idx, $cat);
    		} else{
    			$is_found_cat = TRUE;
    			$cat = $GLOBALS['cats'][$GLOBALS['cats_idxs'][$cat_name_idx]];
    			$prod["cats"][$cat_name_idx] = $cat;
    			if (in_array((int)$cat["category_id"], PRODS_WTH_CAT_NAME_PREFIX_CAT_ID) && strpos($cat["name"], $prod["name"]) === FALSE)
    				$prod["name"] = $cat["name"] . " " . $prod["name"];
    			unset($cat);
    		}
    		if (!$is_found_cat){
    			execSQL("INSERT INTO `oc_category` (`parent_id`,`top`,`column`,`status`,`date_added`,`date_modified`)
    				VALUES (".UNSORTED_CAT_ID.",1,1,0,'".date("Y-m-d H:i:s")."','".date("Y-m-d H:i:s")."')");
    			$cat_id = (string)$GLOBALS["mysqli"]->insert_id;
    			execSQL("INSERT INTO `oc_category_description` (`category_id`,`language_id`,`name`,`description`,`meta_title`,`meta_description`,`meta_keyword`)
    				VALUES (".$cat_id.",1,'".$cat_name."','','".$cat_name."','','');");
    			execSQL("INSERT INTO `oc_category_path` (`category_id`,`path_id`,`level`)
    				VALUES (".$cat_id.",".UNSORTED_CAT_ID.",0), (".$cat_id.",".$cat_id.",1);");
    			$cat_id_idx = str2idx($cat_id);
    			$GLOBALS['cats'][$cat_id_idx] = [
    				"category_id" => $cat_id, 
    				"name" => $cat_name, 
    				"meta_keyword" => "",
    				"parent_id" => UNSORTED_CAT_ID
    			];
    			$GLOBALS['cats_idxs'][str2idx(UNSORTED_CAT_ID)][$cat_name_idx] = $cat_id_idx;
    			$prod["cats"][$cat_name_idx] = $GLOBALS['cats'][$cat_id_idx];
    			$cats_add_count++;
    			unset($cat_id_idx, $cat_id);
    		}
    	}
    	unset($is_found_cat);
    	// Собираем артикулы, для формирвоания значения поля meta_keyword
    	$prod["articles"][] = $article;
    	$articleA = preg_split("/\-/", $article);
    	if (!$prod["article"]) $prod["article"] = $articleA;
    	if (abs(count($prod["article"]) - count($articleA)) > 1){
    		throw new Exception("ERROR: ".implode("-", $articleA)." different length with ".implode("-", $prod["article"])." too much");
    	}
    	// Сопоставляем артикулы, для вычисления общего
    	foreach ($prod["article"] as $loop_idx => $article_part) {
    		if ($loop_idx < count($articleA) && $article_part != "*" && $article_part != $articleA[$loop_idx]) {
    			$diff_count++;
    			$prod["article"][$loop_idx] = "*";
    			if ($diff_count >= count($prod["article"])) {
    				throw new Exception("ERROR: too much different parts of articles: ".implode("-", $articleA).", ".implode("-", $prod["article"]));
    			}
    		}
    	} unset($loop_idx, $article_part);
    	unset($articleA);
    	// Сопоставляем цену, для вычисления общей
    	if (!$prod["price"] || $prod["price"] > (float)$offer->price) { $prod["price"] = (float)$offer->price; }
    	// Агрегируем остаток по всем предложениям, чтобы сформировать общий остаток по товару
    	$prod["quantity"] += (int)$offer->amount;
    	// Перебираем картинки предложения и складываем уникальные в индекс общих картинок, которые впоследствии станет перечнем изображений товара
    	foreach ($offer->picture as $_picture_url) {
    		$picture_url = (string)$_picture_url;
    		$hash = str2idx(hash_file("md5", $picture_url));
    		if (!array_key_exists($hash, $prod["images_idxs"])) {
    			$cur_image = new Imagick($picture_url);
    			$cur_image->adaptiveResizeImage(32,32);
    			$is_unique = TRUE;
    			foreach ($prod["images_idxs"] as $image){
    				if (compareImages($cur_image, $image["imagick"])){
    					$is_unique = FALSE;
    					break;
    				}
    			} unset($image);
    			if ($is_unique) {
    				$prod["images_idxs"][$hash] = [
    					"imagick" => $cur_image, 
    					"url" => $picture_url,
    					"sort_order" => $img_sort_order
    				];
    				$img_sort_order++;
    			}
    			unset($cur_image, $is_unique);
    		}
    	} unset($_picture_url, $picture_url, $hash);
    	// Генерируем массив параметров с целью вычислить различные у данной группы товаров
    	foreach ($offer->param as $param) {
    		$param_name = explode(",", (string)$param["name"])[0];
    		$param_idx = str2idx($param_name);
    		$param_value = (string)$param;
    		$param_value_idx = str2idx($param_value);
    		if (!array_key_exists($param_idx, $params)) {
    			$params[$param_idx] = [
    				"name" => $param_name,
    				"values" => [ $param_value_idx ]
    			];
    		} elseif (!in_array($param_value_idx, $params[$param_idx]["values"])){
    			$params[$param_idx]["values"][] = $param_value_idx;
    		}
    	} unset($param, $param_name, $param_idx, $param_value, $param_value_idx);
    } unset($offer);
    // Если предложений несколько, то проверяем существование подходящей опции, в ее отсутствии создаем новую
    if ($diff_count) {
    	$opt_name = array_reduce($params, function($acc, $item){
    		if (count($item["values"]) > 1) $acc .= $acc ? " / " . $item["name"] : $item["name"];
    		return $acc;
    	}, "");
    	$opt_name_idx = str2idx($opt_name);
    	if (!array_key_exists($opt_name_idx, $GLOBALS['opts_idxs'])) {
    		execSQL("INSERT INTO `oc_option` (`type`,`sort_order`) VALUES ('radio',0);");
    		$opt_id = (string)$GLOBALS["mysqli"]->insert_id;
    		execSQL("INSERT INTO `oc_option_description` (`option_id`,`language_id`,`name`)
    			VALUES (".$opt_id.",1,'".$opt_name."');");
    		$opt_id_idx = str2idx($opt_id);
    		$GLOBALS['opts'][$opt_id_idx] = array(
    			"option_id" => $opt_id, 
    			"name" => $opt_name
    		);
    		$GLOBALS['opts_idxs'][$opt_name_idx] = $opt_id_idx;
    		$GLOBALS['opts_vals_idxs'][$opt_id_idx] = [];
    		$opts_add_count++;
    		unset($opt_id_idx, $opt_id);
    	}
    	$prod["opt"] = $GLOBALS['opts'][ $GLOBALS['opts_idxs'][$opt_name_idx] ];
    	unset($opt_name, $opt_name_idx);
    }
    // Собираем массив значений опций, проверяем каждую на наличие, при отсутствуии оных - создаем
    // Параллельно собираем массив атрибутов и групп атрибутов, фильтров и их групп
    foreach ($offers_group as $offer) {
    	// Формируем наименование значения опции, подходящее данному предложению
    	$cur_opt_val_name = "";
    	foreach ($offer->param as $param) {
    		$param_full_name = (string)$param["name"];
    		$param_name = explode(",", $param_full_name)[0];
    		$param_full_idx = str2idx($param_full_name);
    		$param_idx = str2idx($param_name);
    		$param_value = (string)$param;
    		$param_value_idx = str2idx($param_value);
    		// Собираем наименование значения опции
    		if ($diff_count && (count($params[$param_idx]["values"]) > 1) && strpos($cur_opt_val_name, $param_value) === FALSE) {
    			$cur_opt_val_name .= $cur_opt_val_name ? " / " . $param_value : $param_value;
    		}
    		// Ищем группу фильтров в общем индексе, если нет - пропускаем
    		if (array_key_exists($param_full_idx, $GLOBALS['filts_groups_idxs']) && !(array_key_exists($param_full_idx, $prod["filts_groups"]))) {
    			$prod["filts_groups"][$param_full_idx] = $GLOBALS['filts_groups'][$GLOBALS['filts_groups_idxs'][$param_full_idx]];
    			$filts_group = $prod["filts_groups"][$param_full_idx];
    			$filts_group_id_idx = $GLOBALS['filts_groups_idxs'][$param_full_idx];
    			
    			// Ищем фильтр в глобальных индексах, если нет - создаем
    			if (!array_key_exists($param_value_idx, $GLOBALS['filts_idxs'][$filts_group_id_idx])){
    				execSQL("INSERT INTO `oc_filter` (`filter_group_id`,`sort_order`)
    					VALUES (".$filts_group["filter_group_id"].",0);");
    				$filt_id = (string)$GLOBALS["mysqli"]->insert_id;
    				execSQL("INSERT INTO `oc_filter_description` (`filter_id`,`language_id`,`filter_group_id`,`name`)
    					VALUES (".$filt_id.",1,".$filts_group["filter_group_id"].",'".$param_value."');");
    				foreach ($prod["cats"] as $cat)
    					execSQL("INSERT INTO `oc_category_filter` (`category_id`,`filter_id`)
    							 VALUES (".$cat["category_id"].",".$filt_id.");");
    				$filt_id_idx = str2idx($filt_id);
    				$GLOBALS['filts'][$filt_id_idx] = [
    					"filter_group_id" => $filts_group["filter_group_id"],
    					"name" => $param_value,
    					"filter_id" => $filt_id
    				];
    				$GLOBALS['filts_idxs'][$filts_group_id_idx][$param_value_idx] = $filt_id_idx;
    				$filts_add_count++;
    				unset($filt_id, $filt_id_idx);
    			}
    			// Проверяем есть ли данный фильтр в локальных списках конкретного товара, если нет добавляем
    			if (!array_key_exists($param_value_idx, $prod["filts"])){
    				$prod["filts"][$param_value_idx] = $GLOBALS['filts'][
    					$GLOBALS['filts_idxs'][$filts_group_id_idx][$param_value_idx]
    				];
    			}
    			unset($filts_group, $filts_group_id_idx);
    		}
    		// Ищем группу атрибутов в общем индексе, если нет - создаем
    		if (!array_key_exists($param_full_idx, $GLOBALS['attrs_groups_idxs'])){
    			execSQL("INSERT INTO `oc_attribute_group` (`sort_order`) VALUES (0);");
    			$attr_group_id = (string)$GLOBALS["mysqli"]->insert_id;
    			execSQL("INSERT INTO `oc_attribute_group_description` (`attribute_group_id`,`language_id`,`name`) 
    				VALUES (".$attr_group_id.",1,'".(string)$param["name"]."');");
    			$attr_group_id_idx = str2idx($attr_group_id);
    			$GLOBALS['attrs_groups_idxs'][$param_full_idx] = $attr_group_id_idx;
    			$GLOBALS['attrs_groups'][$attr_group_id_idx] = [
    				"name" => $param_name,
    				"attribute_group_id" => $attr_group_id
    			];
    			$GLOBALS['attrs_idxs'][$attr_group_id_idx] = [];
    			$attrs_groups_add_count++;
    			unset($attr_group_id, $attr_group_id_idx);
    		}
    		// Теперь ищем группу атрибутов в списке текущего товара, если нет - добавляем
    		if (!array_key_exists($param_full_idx, $prod["attrs_groups"])){
    			$prod["attrs_groups"][$param_full_idx] = $GLOBALS['attrs_groups'][$GLOBALS['attrs_groups_idxs'][$param_full_idx]];
    		}
    		// Ищем атрибут в общем индексе, если нет - создаем
    		$attr_group = $prod["attrs_groups"][$param_full_idx];
    		$attr_group_id_idx = $GLOBALS['attrs_groups_idxs'][$param_full_idx];
    		$param_value_idx = str2idx($param_value);
    		if (!array_key_exists($param_value_idx, $GLOBALS['attrs_idxs'][$attr_group_id_idx])) {
    			execSQL("INSERT INTO `oc_attribute` (`attribute_group_id`,`sort_order`)
    				VALUES (".$attr_group["attribute_group_id"].",0);");
    			$attr_id = (string)$GLOBALS["mysqli"]->insert_id;
    			execSQL("INSERT INTO `oc_attribute_description` (`attribute_id`,`language_id`,`name`)
    				VALUES (" . $attr_id . ",1,'" . $param_value . "');");
    			execSQL("INSERT INTO `oc_bf_attribute_value` (`attribute_id`,`language_id`,`value`,`sort_order`)
    				VALUES (".$attr_id.",1,'',0);");
    			$attr_id_idx = str2idx($attr_id);
    			$attr_val_id = (string)$GLOBALS["mysqli"]->insert_id;
    			if (!$attr_val_id && $attr_val_id !== 0) {
    				var_dump($attr_val_id, "INSERT INTO `oc_bf_attribute_value` (`attribute_id`,`language_id`,`value`,`sort_order`)
    				VALUES (".$attr_id.",1,'',0);");
    				throw new Exception("wrong attr_val_id");
    			}
    			$GLOBALS['attrs'][$attr_id_idx] = [
    				"attribute_id" => $attr_id,
    				"attribute_group_id" => $attr_group["attribute_group_id"],
    				"name" => $param_value,
    				"attribute_value_id" => $attr_val_id
    			];
    			$GLOBALS['attrs_idxs'][$attr_group_id_idx][$param_value_idx] = $attr_id_idx;
    			$attrs_add_count++;
    			unset($attr_id, $attr_id_idx, $attr_val_id);
    		}
    		// Ищем атрибут в локально перечне атрибутов, если нет - добавляем
    		if (!array_key_exists($param_value_idx, $prod["attrs"]))
    			$prod["attrs"][$param_value_idx] = $GLOBALS['attrs'][$GLOBALS['attrs_idxs'][$attr_group_id_idx][$param_value_idx]];
    	} unset(
    		$param, $param_full_name, $param_name, $param_full_idx, $param_idx, $param_value, 
    		$param_value_idx, $attr_group, $attr_group_id_idx, $param_value_idx
    	);
    	if ($diff_count){
    		$opt_val = [
    			"name" => $cur_opt_val_name,
    			"quantity" => (int)$offer->amount,
    			"price" => (float)$offer->price - $prod["price"],
    			"option_id" => $prod["opt"]["option_id"]
    		];
    		// Проверяем существует ли подходящее значение опции, если нет - создаем
    		$opt_val_name_idx = str2idx($opt_val["name"]);
    		$opt_id_idx = str2idx($opt_val["option_id"]);
    		if (!array_key_exists($opt_val_name_idx, $GLOBALS['opts_vals_idxs'][$opt_id_idx])) {
    			execSQL("INSERT INTO `oc_option_value` (`option_id`,`image`,`sort_order`)
    					 VALUES (".$opt_val["option_id"].",'',0);");
    			$opt_val["option_value_id"] = (string)$GLOBALS["mysqli"]->insert_id;
    			execSQL("INSERT INTO `oc_option_value_description` (`option_value_id`,`language_id`,`option_id`,`name`)
    				VALUES (".$opt_val["option_value_id"].",1,".$opt_val["option_id"].",'".$opt_val["name"]."')");
    			$opt_val_id_idx = str2idx($opt_val["option_value_id"]);
    			$GLOBALS['opts_vals'][$opt_val_id_idx] = $opt_val;
    			$GLOBALS['opts_vals_idxs'][$opt_id_idx][$opt_val_name_idx] = $opt_val_id_idx;
    			$opts_vals_add_count++;
    			unset($opt_val_id_idx);
    		}
    		// добавляем значение опции по ключу в виде индекса option_value_id само значение опции
    		$opt_val_id_idx = $GLOBALS['opts_vals_idxs'][$opt_id_idx][$opt_val_name_idx];
    		$prod["opt_vals"][$opt_val_id_idx] = array_merge($GLOBALS['opts_vals'][$opt_val_id_idx], $opt_val);
    		unset($opt_val, $opt_val_id_idx, $opt_val_name_idx);
    	}
    } unset($offer, $cur_opt_val_name);
    $prod["article"] = implode("-", $prod["article"]);
    return $prod;
}
$offers_groups2upd_prods = [];
$prods2adding = [];
foreach ($offers_groups_idxs as $hash => $offers_group) {
    $found_prods_idxs = [];
    // Ищем товары по индексам артикулов, запоминаем их индексы id'шников
    foreach ($offers_group["offers"] as $loop_idx => $offer) {
    	$article_idx = str2idx((string)$offer->article); // Артикул, преобразованный в индекс
    	if (array_key_exists($article_idx, $GLOBALS['prods_idxs'])){
    		$found_prods_idxs[ $GLOBALS['prods_idxs'][$article_idx] ][] = $loop_idx;
    	}
    } unset($loop_idx, $offer, $article_idx);
    // Если данной группе предложений соответствует один товар, то добавляем все предложения на обновление товара
    if (count($found_prods_idxs) === 1){
    	reset($found_prods_idxs);
    	$offers_groups2upd_prods[key($found_prods_idxs)] = $offers_group["offers"];
    // Если данной группе предложений не соответствует ни один товар, то конвертируем группу предложений в товар и добавляем в список товаров на добавление
    } elseif (count($found_prods_idxs) === 0){
    	$prods2adding[] = convertOffersGroup2Product($offers_group["offers"]);
    // Если же группе предложений соответствует несколько товаров, то оцениваем, какой товар чаще сопостовляется с предложениями, выбираем его заглавноего, все предложения, которые не сопоставились, добавляем к нему, остальные в те товары, которым они были сопоставлены
    } else {
    	$main_prod_id_idx = NULL;
    	foreach ($found_prods_idxs as $prod_id_idx => $offers_loop_idxs) {
    		if (!$main_prod_id_idx || ( count($found_prods_idxs[$main_prod_id_idx]) < count($offers_loop_idxs) )) {
    			$main_prod_id_idx = $prod_id_idx;
    		}
    	} unset($prod_id_idx, $offers_loop_idxs);
    	foreach ($offers_group["offers"] as $offer) {
    		$article_idx = str2idx((string)$offer->article); // Артикул, преобразованный в индекс
    		if (array_key_exists($article_idx, $GLOBALS['prods_idxs'])){
    			$offers_groups2upd_prods[$GLOBALS['prods_idxs'][$article_idx]][] = $offer;
    		} else {
    			$offers_groups2upd_prods[$main_prod_id_idx][] = $offer;
    		}
    		
    	} unset($offer, $article_idx);
    	unset($main_prod_id_idx);
    }
}
$prods2updating = [];
foreach ($offers_groups2upd_prods as $prod_id_idx => $offers_group){
    $prods2updating[] = convertOffersGroup2Product($offers_group, $GLOBALS['prods'][$prod_id_idx]);
} unset($prod_id_idx, $offers_group);
printLog(
    "Compare import and exists data, convert offers group to products:
     - products to updating count: " . count($prods2updating) . "
     - products to adding count: " . count($prods2adding) . "
     - added options count: " . $opts_add_count . "
     - added options values count: " . $opts_vals_add_count . "
     - added filters count: " . $filts_add_count . "
     - added categories count: " . $cats_add_count . "
     - added attributes groups count: " . $attrs_groups_add_count . "
     - added attributes count: " . $attrs_add_count
);
unset(
    $opts_add_count, $opts_vals_add_count, $filts_add_count,
    $cats_add_count, $attrs_groups_add_count, $attrs_add_count
);
// -=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-

// -= Создание товаров =-
$prods_add_count = 0;
$prod_attr_add_count = 0;
$prod_filt_add_count = 0;
$prod_img_add_count = 0;
$prod_opt_add_count = 0;
$prod_opt_val_add_count = 0;
$prod_cat_add_count = 0;
// TODO конкатенировать sql запросы insert, и выполнять единожды после перебора всех products
foreach ($prods2adding as $prod) {
    // Создаем сам товар, получаем его ID
    execSQL("INSERT INTO `oc_product` 
    		(`model`,
    	     `quantity`,
    	     `stock_status_id`,
    	     `manufacturer_id`,
    	     `price`,
    	     `date_available`,
    	     `date_added`,
    	     `date_modified`,
       		 `sku`,`upc`,`ean`,`jan`,`isbn`,`mpn`,`location`,`tax_class_id`,`weight_class_id`,`length_class_id`,`subtract`,`status`)
    		 VALUES 
    	    ('".$prod["article"]."',
    		 ".$prod["quantity"].",
    		 ".(($prod["price"] && $prod["quantity"]) ? "7" : "5").",
    		 ".$GLOBALS['kingsilk_man_id'].",
    		 ".$prod["price"].",
    		 '".date("Y-m-d")."',
    		 '".date("Y-m-d H:i:s")."',
    		 '".date("Y-m-d H:i:s")."',
    		 '','','','','','','',0,1,1,1,1);");
    $p_id = (string)$GLOBALS["mysqli"]->insert_id;
    if (!$p_id || $p_id == "0") throw new Exception("Recieved wrong product_id");
    // Сохраняем изображения
    foreach ($prod["images_idxs"] as $hash_idx => $image) {
    	$image_path = IMAGE_PATH_PREFIX . $hash_idx . ".jpg";
    	if (!file_exists($IMAGES_FULL_PATH . $hash_idx . ".jpg"))
    		file_put_contents(
    			$IMAGES_FULL_PATH . $hash_idx . ".jpg", 
    			file_get_contents($image["url"])
    		);
    	if ($image["sort_order"] === 0){
    		execSQL("UPDATE `oc_product` SET `image`='".$image_path."' WHERE product_id=".$p_id.";");  
    	} else {
        	execSQL("INSERT INTO `oc_product_image` (`product_id`, `image`, `product_option_value_id`, `sort_order`)
        		VALUES (".$p_id.", '".$image_path."', 0, ".$image["sort_order"].")");
        	$prod_img_add_count++;
    	}
    } unset($image, $image_path, $hash_idx);
    // Добавляем описание товара
    execSQL(
    	"INSERT INTO `oc_product_description` (`product_id`,
    										   `name`,
    								   		   `description`,
    								   		   `meta_title`,
    								   		   `meta_keyword`,
    										   `language_id`,`tag`,`meta_description`)
    	 VALUES (".$p_id.",
    	 		 '".$prod["name"]."',
    	 		 '".$prod["description"]."',
    	 		 '".$prod["name"]."',
    	 		 '".implode("; ",$prod["articles"])."',
    			 1,'','');"
    );
    // Добавляем связки с категориями
    foreach ($prod["cats"] as $cat){
    	execSQL(
        	"INSERT INTO `oc_product_to_category` (`product_id`,`category_id`)
        	VALUES (".$p_id.",".$cat["category_id"].");"
        );
    	$prod_cat_add_count++;
    } unset($cat);
    // Создаем еще несколько записей необходимых для отображения товара на сайте
    execSQL(
    	"INSERT INTO `oc_product_to_layout` (`product_id`,`store_id`,`layout_id`)
    	VALUES (".$p_id.",0,0);"
    );
    execSQL(
    	"INSERT INTO `oc_product_to_store` (`product_id`,`store_id`)
    	VALUES (".$p_id.",0);"
    );
    execSQL(
    	"INSERT INTO `oc_bf_filter` (`product_id`,`filter_group`,`filter_id`,`language_id`,`out_of_stock`)
    	VALUES (".$p_id.",'m0',".$GLOBALS['kingsilk_man_id'].",1,0);"
    );
    // Создаем связку с опцией и значениями опций, если есть
    if ($prod["opt"]) {
    	execSQL("INSERT INTO `oc_product_option` (`product_id`,`option_id`,`value`,`required`)
    			VALUES (".$p_id.",".$prod["opt"]["option_id"].",'',1);");
    	$prod["opt"]["product_option_id"] = (string)$GLOBALS["mysqli"]->insert_id;
    	if (!$prod["opt_vals"]) { throw new Exception("undefined 'opt_vals', but 'opt' is exists!"); }
    	$prod_opt_add_count++;
    	$sql_prod_opt_val = "";
    	foreach ($prod["opt_vals"] as $opt_val){
    		$sql_prod_opt_val .= "(".$prod["opt"]["product_option_id"].",".$p_id.",".$prod["opt"]["option_id"].",".$opt_val["option_value_id"].",".$opt_val["quantity"].",1,".$opt_val["price"].",'+',0,'+',0,'+'),";
    		$prod_opt_val_add_count++;
    	} unset($opt_val);
    	if ($sql_prod_opt_val){
    		execSQL("INSERT INTO `oc_product_option_value` (`product_option_id`,`product_id`,`option_id`,`option_value_id`,
    														`quantity`,`subtract`,`price`,`price_prefix`,`points`,`points_prefix`,
    														`weight`,`weight_prefix`) VALUES ".substr($sql_prod_opt_val, 0, -1).";");
    	}
    	unset($sql_prod_opt_val);
    }
    // Создаем связки с фильтрами, если есть
    if ($prod["filts_groups"]){
    	$sql_prod_filt = "";
    	$sql_bf_filt = "";
    	if (!$prod["filts"]) { throw new Exception("undefined product filters, but product filters groups is exists!"); }
    	foreach ($prod["filts"] as $filt){
    		$sql_prod_filt .= "(".$p_id.",".$filt["filter_id"]."),";
    		$sql_bf_filt .= "(".$p_id.",'f".$filt["filter_group_id"]."',".$filt["filter_id"].",1,0),";
    		$prod_filt_add_count++;
    	} unset($filt);
    	if ($sql_prod_filt){
    		execSQL("INSERT INTO `oc_product_filter` (`product_id`,`filter_id`) VALUES ".substr($sql_prod_filt, 0, -1).";");
    	}
    	if ($sql_bf_filt){
    		execSQL("INSERT INTO `oc_bf_filter` (`product_id`,`filter_group`,`filter_id`,`language_id`,`out_of_stock`)
    						VALUES ".substr($sql_bf_filt, 0, -1).";");
    	}
    	unset($sql_prod_filt, $sql_bf_filt);
    }
    // Создаем связку с атрибутами, если есть
    if ($prod["attrs_groups"]){
    	$sql_prod_attr = "";
    	$sql_bf_attr_val = "";
    	if (!$prod["attrs"]) { throw new Exception("undefined product attributes, but product attributes groups is exists!"); }
    	foreach ($prod["attrs"] as $attr){
    		$sql_prod_attr .= "(".$p_id.",".$attr["attribute_id"].",1,''),";
    		$sql_bf_attr_val .= "(".$p_id.",".$attr["attribute_id"].",".$attr["attribute_value_id"].",1),";
    		$prod_attr_add_count++;
    	} unset($attr);
    	if ($sql_prod_attr) {
    		execSQL("INSERT INTO `oc_product_attribute` (`product_id`,`attribute_id`,`language_id`,`text`) VALUES ".substr($sql_prod_attr, 0, -1).";");
    	}
    	if ($sql_bf_attr_val){
    		execSQL("INSERT INTO `oc_bf_product_attribute_value` (`product_id`,`attribute_id`,`attribute_value_id`,`language_id`)
    				VALUES ".substr($sql_bf_attr_val, 0, -1).";");
    	}
    	unset($sql_prod_attr, $sql_bf_attr_val);
    }
    $prods_add_count++;
} unset($prod);
printLog(
    "Products adding completed:
     - products added count: " . $prods_add_count . "
     - relationship products and attributes added count: " . $prod_attr_add_count . "
     - relationship products and filters added count: " . $prod_filt_add_count . "
     - relationship products and images added count: " . $prod_img_add_count . "
     - relationship products and options added count: " . $prod_opt_add_count . "
     - relationship products and options values added count: " . $prod_opt_val_add_count . "
     - relationship products and categories added count: " . $prod_cat_add_count
);
// -=-=-=-=-=-=-=-=-=-=-=-=-

// -= Обновление товаров =-
$prod_attr_add_count = 0;
$prod_filt_add_count = 0;
$prod_img_add_count = 0;
$prod_opt_add_count = 0;
$prod_opt_val_add_count = 0;
$prod_cat_add_count = 0;
$prod_upd_count = 0;
$prod2layout_wrong = [];
$prod2store_wrong = [];
$prod_bf_filter_wrong = [];
foreach ($prods2updating as $prod) {
    // Проверяем product ID
    $p_id = $prod["product_id"];
    if (!$p_id || $p_id == "0") throw new Exception("Recieved wrong product_id");
    // TODO проверять существующие изображения, добавлять только новые
    // Обновляем изображения
    execSQL("DELETE FROM `oc_product_image` WHERE `product_id`=".$p_id.";");
    foreach ($prod["images_idxs"] as $hash_idx => $image) {
    	$image_path = IMAGE_PATH_PREFIX . $hash_idx . ".jpg";
    	if (!file_exists($IMAGES_FULL_PATH . $hash_idx . ".jpg")){
    		file_put_contents(
    			$IMAGES_FULL_PATH . $hash_idx . ".jpg", 
    			file_get_contents($image["url"])
    		);
    	}
    	if ($image["sort_order" === 0]){
    		execSQL("UPDATE `oc_product`
    			SET `quantity`=".$prod["quantity"].",
    				`stock_status_id`=".(($prod["quantity"] && $prod["price"]) ? "7" : "5").",
    				`price`=".$prod["price"].",
    				`date_modified`='".date("Y-m-d H:i:s")."',
    				`image`='".$image_path."'
    			WHERE `product_id`=".$p_id.";"); 
    	} else {
        	execSQL("INSERT INTO `oc_product_image` (`product_id`, `image`, `product_option_value_id`, `sort_order`)
        		VALUES (".$p_id.", '".$image_path."', 0, ".$image["sort_order"].")");
        	$prod_img_add_count++;
    	}
    } unset($hash_idx, $image, $image_path);
    // Обновляем описание товара
    execSQL("UPDATE `oc_product_description`
        	 SET `name`='".$prod["name"]."',
        	 	 `description`='".$prod["description"]."',
        	 	 `meta_title`='".$prod["name"]."',
        	 	 `meta_keyword`='".implode("; ",$prod["articles"])."'
    	 	 WHERE `product_id`=".$p_id." AND `language_id`=1;"
    );
    // Обновляем связки с категориями, забираем все имеющиеся, и добавляем только те, которых нет
    $prod_cats = execSQL("SELECT category_id FROM oc_product_to_category WHERE `product_id`=".$p_id.";");
    foreach ($prod["cats"] as $cat){
    	if (!array_key_exists(str2idx($cat["category_id"]), $prod_cats)){
    		execSQL(
    	    	"INSERT INTO `oc_product_to_category` (`product_id`,`category_id`)
    	    	VALUES (".$p_id.",".$cat["category_id"].");"
    	    );
    		$prod_cat_add_count++;
    	}
    } unset($cat);
    unset($prod_cats);
    // Проверяем наличие нескольких необходимых для отображения товара записей, создаем, если их нет
    if ( !execSQL("SELECT product_id FROM oc_product_to_layout WHERE `product_id`=".$p_id.";", "num_rows") ){
    	execSQL(
        	"INSERT INTO `oc_product_to_layout` (`product_id`,`store_id`,`layout_id`)
        	VALUES (".$p_id.",0,0);"
        );
    	$prod2layout_wrong[] = $p_id;
    }
    if ( !execSQL("SELECT product_id FROM oc_product_to_store WHERE `product_id`=".$p_id.";", "num_rows") ){
        execSQL(
        	"INSERT INTO `oc_product_to_store` (`product_id`,`store_id`)
        	VALUES (".$p_id.",0);"
        );
        $prod2store_wrong[] = $p_id;
    }
    if ( !execSQL("SELECT product_id FROM oc_bf_filter WHERE `product_id`=".$p_id." AND filter_group='m0';", "num_rows") ){
        execSQL(
        	"INSERT INTO `oc_bf_filter` (`product_id`,`filter_group`,`filter_id`,`language_id`,`out_of_stock`)
        	VALUES (".$p_id.",'m0',".$GLOBALS['kingsilk_man_id'].",1,0);"
        );
        $prod_bf_filter_wrong[] = $p_id;
    }
    // Обновляем связку с опцией и значениями опции, если есть
    $prod_opts = execSQL("SELECT option_id, product_option_id FROM oc_product_option WHERE `product_id`=".$p_id.";");
    if ($prod["opt"]) {
    	// TODO обновлять существующие свзяки oc_product_option_value, а не удалять их каждый раз и создавать заново
    	execSQL("DELETE FROM oc_product_option_value WHERE `product_id`=".$p_id.";");
    	if ($prod_opts && !array_key_exists(str2idx($prod["opt"]["option_id"]), $prod_opts)){
    		execSQL("DELETE FROM oc_product_option WHERE `product_id`=".$p_id.";");
    		$prod_opts = NULL;
    	}
    	if (!$prod_opts) {
        	execSQL("INSERT INTO `oc_product_option` (`product_id`,`option_id`,`value`,`required`)
    				VALUES (".$p_id.",".$prod["opt"]["option_id"].",'',1);");
        	$prod["opt"]["product_option_id"] = (string)$GLOBALS["mysqli"]->insert_id;
        	$prod_opt_add_count++;
    	} else{
    		$prod["opt"]["product_option_id"] = $prod_opt["product_option_id"];
    	}
    	if (!$prod["opt_vals"]) throw new Exception("undefined 'opt_vals', but 'opt' is exists!");
    	$sql_prod_opt_val = "";
    	foreach ($prod["opt_vals"] as $opt_val){
    		$sql_prod_opt_val .= "(".$prod["opt"]["product_option_id"].",".$p_id.",".$prod["opt"]["option_id"].",".$opt_val["option_value_id"].",".$opt_val["quantity"].",1,".$opt_val["price"].",'+',0,'+',0,'+'),";
    		$prod_opt_val_add_count++;
    	} unset($opt_val);
    	if ($sql_prod_opt_val){
    		execSQL("INSERT INTO `oc_product_option_value` (`product_option_id`,`product_id`,`option_id`,`option_value_id`,
    														`quantity`,`subtract`,`price`,`price_prefix`,`points`,`points_prefix`,
    														`weight`,`weight_prefix`) VALUES ".substr($opt_val_ins_sql, 0, -1).";");
    	}
    	unset($sql_prod_opt_val);
    } else {
    	// Если же их нет, то удаляем возможные "хвосты"
    	execSQL("DELETE FROM oc_product_option WHERE product_id=".$p_id.";");
    	execSQL("DELETE FROM oc_product_option_value WHERE product_id=".$p_id.";");
    }
    unset($prod_opts);
    // Создаем связки с фильтрами, если есть
    execSQL("DELETE FROM oc_product_filter WHERE `product_id`=".$p_id.";");
    execSQL("DELETE FROM oc_bf_filter WHERE `product_id`=".$p_id.";");
    if ($prod["filts_groups"]) {
    	// TODO обновлять существующие свзяки c oc_product_filter и oc_bf_filter, а не удалять их каждый раз и создавать заново
    	$sql_prod_filt = "";
    	$sql_bf_filt = "";
    	if (!$prod["filts"]) { throw new Exception("undefined product filters, but product filters groups is exists!"); }
    	foreach ($prod["filts"] as $filt){
    		$sql_prod_filt .= "(".$p_id.",".$filt["filter_id"]."),";
    		$sql_bf_filt .= "(".$p_id.",'f".$filt["filter_group_id"]."',".$filt["filter_id"].",1,0),";
    		$prod_filt_add_count++;
    	} unset($filt);
    	if ($sql_prod_filt) {
    		execSQL("INSERT INTO `oc_product_filter` (`product_id`,`filter_id`) VALUES ".substr($sql_prod_filt, 0, -1).";");
    	}
    	if ($sql_bf_filt) {
    		execSQL("INSERT INTO `oc_bf_filter` (`product_id`,`filter_group`,`filter_id`,`language_id`,`out_of_stock`)
    						VALUES ".substr($sql_bf_filt, 0, -1).";");
    	}
    	unset($sql_prod_filt, $sql_bf_filt);
    }
    if ($prod["attrs_groups"]){
    	// TODO обновлять существующие свзяки c oc_product_filter и oc_bf_filter, а не удалять их каждый раз и создавать заново
    	$sql_prod_attr = "";
    	$sql_bf_attr_val = "";
    	if (!$prod["attrs"]) { throw new Exception("undefined product attributes, but product attributes groups is exists!"); }
    	foreach ($prod["attrs"] as $attr){
    		$sql_prod_attr .= "(".$p_id.",".$attr["attribute_id"].",1,''),";
    		$sql_bf_attr_val .= "(".$p_id.",".$attr["attribute_id"].",".$attr["attribute_value_id"].",1),";
    		$prod_attr_add_count++;
    	} unset($attr);
    	if ($sql_prod_attr){
    		execSQL("INSERT INTO `oc_product_attribute` (`product_id`,`attribute_id`,`language_id`,`text`) 
    				 VALUES ".substr($sql_prod_attr, 0, -1).";");
    	}
    	if ($sql_bf_attr_val){
    		execSQL("INSERT INTO `oc_bf_product_attribute_value` (`product_id`,`attribute_id`,`attribute_value_id`,`language_id`)
    				 VALUES ".substr($sql_bf_attr_val, 0, -1).";");
    	}
    	unset($sql_prod_attr, $sql_bf_attr_val);
    }
    $prod_upd_count++;
} unset($prod, $p_id);
printLog(
    "Products updating completed:
     - products updated count: " . $prod_upd_count . "
     - relationship products and attributes added count: " . $prod_attr_add_count . "
     - relationship products and filters added count: " . $prod_filt_add_count . "
     - relationship products and images added count: " . $prod_img_add_count . "
     - relationship products and options added count: " . $prod_opt_add_count . "
     - relationship products and options values added count: " . $prod_opt_val_add_count . "
     - relationship products and categories added count: " . $prod_cat_add_count . "
     - wrong relationship layout and product in product with IDs: " . implode(", ", $prod2layout_wrong) . "
     - wrong relationship store and product in product with IDs: " . implode(", ", $prod2store_wrong) . "
     - wrong relationship bf filter and product in product with IDs: " . implode(", ", $prod_bf_filter_wrong)
);
unset($prod_upd_count,$prod_attr_add_count,$prod_filt_add_count,$prod_img_add_count,
      $prod_opt_add_count,$prod_opt_val_add_count,$prod_cat_add_count, 
      $prod2layout_wrong, $prod2store_wrong, $prod_bf_filter_wrong);
// -=-=-=-=-=-=-=-=-=-=-=-=-

printLog("Script completed by " . ((microtime(true) - $start) / 60) . " minutes");
?>