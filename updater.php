<?php
/* -= Developed by a.s.tseluyko@gmail.com =-
 -= А Л Г О Р И Т М =-
 1. Получение данных от поставщика в формате YML, конвертация в ассоциативный массив, где ключ - md5 hash первой картинки предложения, либо очень похожей на первую картинку, таким образом происходит первичная группировка по одинкаовым и схожим картинкам.
 2. Для получения возможности сопоставления русского текста после его упрощения, дабы избежать случаев с задвоением пробелов, например, то данные по товарам, атрибутам, категориям, фильтрам, производителям, опциям и значениям опций забираются из БД и превращаются в "поисковые индексы", где индексами является результат функции str2idx (см. ниже), на каждую сущность создаются индексы по ID, в которых хранятся сами данные и отдельные индексы по наименованию и синонимам (если есть), которые хранят индексы ID.
 3. Сопоставление полученных от поставщика групп предложений и существующих товаров и их опций. Связующее поле здесь product.meta_keyword, в которое через точку с запятой вписываются артикулы предложений, состовляющих опции товара, либо сам товар, если соответствующее предложение для него одно. Это позволяют пользователю вручную перегруппировать предложения по товарам, соответственно, на данном этапе, необходимо учесть изменения от пользователей.
 4. Конвертация группы предложений в товар. Здесь проверяется:
 	4.1 Наличие действующей категории для товара, если таковой не найдено, то товар, вместе с категорией поставщика отправляется в выключенную категорию "Неотсортированные при импорте". Позднее менеджер может указать в поле category.meta_keyword у действующей категории, через точку с запятой наименование категорий поставщика, что будет расцениваться как синонимы при следующем поиске действующей категории, что позволит для автоматически сопоставить категории.
 	4.2 Наличие фильтров и групп фильтров, подходящих к параметрам, имеющимся у каждого из предложений. Отстутствие подходящих к параметрам групп фильтров, последние не создаются, также не создаются и сами фильтры. Если же подхожящая группа фильтров найдена, то при отсутствии подходящих фильтров - они создаются.
	4.3 Наличие атрибутов и групп атрибутов, опять же подходящих к параметрам. В отличие от фильтров создаются и группы и сами атрибуты, если не были найдены подходящие.

 -=-=-=-=-=-=-=-=-=-=-
*/
// -= О П Ц И И =-
define("DB_HOST", "localhost"); //
define("DB_USER", "u0533387_satin"); // u0533387_satin
define("DB_PASS", "!vipSatin"); // !vipSatin
define("DB_NAME", "u0533387_test"); // u0533387_vipsatin
define("YML_URL_KINGSILK", "http://kingsilk-opt.ru/YMLexport.xml");
define("YML_URL_CLEO", "http://cleo-opt.ru/bitrix/catalog_export/yml_1.php");
define("IMAGES_PATH", getcwd() . "/www/test.vipsatin.ru/image/"); // end of this constant must be the "/"!!
define("IMAGE_PATH_PREFIX", "catalog/Products/"); // end of this constant must be the "/"!!
define("THRESHOLD_SIMILARITY_VALUE", 35); // пороговое значение сходства изображений
define("IMAGICK_METRIC", Imagick::METRIC_PEAKSIGNALTONOISERATIO); // Метрика сравнения изображений
define("UNSORTED_CAT_ID", 321);
define("PRODS_WTH_CAT_NAME_PREFIX_CAT_ID", [59]); // Идентификаторы категорий в наименовании товаров которых, в качестве префикса, должно присутствовать наименование самой категории
// Технические настройки скрипта
header('Content-Type: text/html; charset=utf-8');
ini_set('memory_limit', '-1');
// -=-=-=-=-=-=-=-

// -= Функции инкапсуляции технических аспектов =-
/* Сокращения и опеределния принятые в коде данного скрипта:
* prod - product
* opt - option
* val - value
* cat - category
* man - manufacturer
* idx - index
* desc - description
* cur - current 
* Индекс - строка и или число, привиденного к типу string, из которого убраны пробелы и добавлен префикс "_"*/
// Функция печати логов, добавляет "date n time now" и перенос строки
function printLog($text) { echo sprintf("[%s] %s", date("Y-m-d H:i:s"), $text) . "\n"; }
// Функция преобразования текста в ключ индекса, убирает пробелы, переводит в верхний регистр и добавляет префикс "_"
function str2idx($str) { return "_" . strtoupper( str_replace(' ', '', (string)$str) ); }
// Функция генерации ассоциативного массива индексов, использует str2idx
function genIdxs($array, $val_key, $idx_keys, $filter_func=NULL) {
	$idxs = [];
	foreach ($array as $item) {
		if (!$filter_func || $filter_func($item)){
			if (is_string($idx_keys)){
				foreach (preg_split("/\s?;\s?/", $item[$idx_keys]) as $idx)
					if ($idx)
						$idxs[str2idx($idx)] = str2idx((string)$item[$val_key]);
			} else
				foreach ($idx_keys as $idx_key)
					foreach (preg_split("/\s?;\s?/", $item[$idx_key]) as $idx)
						if ($idx)
							$idxs[str2idx($idx)] = str2idx((string)$item[$val_key]);
		}
	}
	return $idxs;
}
// Функция сравнения изображений
function compareImages($image1, $image2) {
	$compare_result = $image1->compareImages($image2, IMAGICK_METRIC);
	return (int)$compare_result[1] > THRESHOLD_SIMILARITY_VALUE;
}
// Функция исполнения SQL-запросов в БД, инкапсулирующая все ужасы взаимодействия с БД MySQL на PHP
function execSQL($sql, $mode="fetch_assoc") {
	$sql_rows = is_string($sql) ? array($sql) : $sql;
	if (!$GLOBALS["mysqli"] || $GLOBALS["mysqli"]->connect_errno) { 
		$GLOBALS["mysqli"] = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME); 
		if ($GLOBALS["mysqli"]->connect_errno) throw new Exception("Can't connect to DB: (" . $GLOBALS["mysqli"]->connect_errno . ") " . $GLOBALS["mysqli"]->connect_error);
		printf("default charset: %s\n", $GLOBALS["mysqli"]->character_set_name());
		/* изменение набора символов на utf8 */
		if (!$GLOBALS["mysqli"]->set_charset("utf8")) {
		    printf("set charset utf8 error: %s\n", $GLOBALS["mysqli"]->error);
		    exit();
		} else printf("current charset: %s\n", $GLOBALS["mysqli"]->character_set_name());
	}
	$result = array();
	foreach ($sql_rows as $idx => $sql) {
		$res = $GLOBALS["mysqli"]->query($sql);
		if (!$res) printLog("SQL ERROR: ". $GLOBALS["mysqli"]->error . "\n executable SQL: " . $sql . "\n\n");
		if (!is_bool($res)){
			if ($mode==="fetch_assoc") { 
				while($row = $res->fetch_assoc()) {
					reset($row);
					$key = str2idx($row[key($row)]);
					$result[$key] = $row;
			    }
			} elseif ($mode==="num_rows") $result[] = $res->num_rows;
			else throw new Exception("Recieved unexpected mode (".$mode.") for result return: ".$sql );
		} else $result[] = $res;
	}
	return count($result) === 1 ? $result[0] : $result;
}
// -=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-

// -= Старт работы скрипта =-
$start = microtime(true);
printLog("Updater script started");

// Инициализация глобальных переменных, счетчиков
$GLOBALS["mysqli"] = NULL;
$kingsilk_offers_count = 0;
$goods_added = 0;
$goods_updated = 0;
$goods_deleted = 0;
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
foreach ($yml_catalog->categories->category as $cat)
	$GLOBALS['cats_outer_idxs'][str2idx((string)$cat["id"])] = $cat;
// Группировка предложений поставщика по схожести картинок,
// формирование древовидного индекса по md5 хэшу картинок
$offers_groups_idxs = [];
foreach ($yml_catalog->offers->offer as $offer) {
	// Отсеиваем не опубликованные товары
	if ((string)$offer["available"] != "true")
		continue;
	$kingsilk_offers_count++;
	$img_url = (string)$offer->picture[0];
	$hash = "_" . strtoupper((string)hash_file("md5", $img_url));
	$image = new Imagick($img_url); // можно оптимизировать, перенеся создание картинки под следующий if, так как после if'a переменные не удаляются, то они будут доступны и далее
	$image->adaptiveResizeImage(32,32);
	// Если на данный момент в индексе нет идентичной картинки, то пытаемся найти похожую
	if (!array_key_exists($hash, $offers_groups_idxs))
		foreach ($offers_groups_idxs as $exists_hash => $offers_group)
			if (compareImages($image, $offers_group['image'])){
				$hash = $exists_hash;
				break;
			}
	// Если hash существует в индексе групп предложений, то добавляем в группу данное предложение
	if (array_key_exists($hash, $offers_groups_idxs))
		$offers_groups_idxs[$hash]["offers"] = $offer;
	// иначе создаем новую группу предложений
	else $offers_groups_idxs[$hash] = [
			"offers" => [ $offer ],
			"image" => $image
		];
}
printLog(
	"Built the index of KingSlik's offers:
	 - importing offers count: " . $kingsilk_offers_count . "
	 - offers group by compare images count: " . count($offers_groups_idxs)
);
// -=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=--=-=-=-=-=-=-=-=-=-=-=-

// -= Получение данных из БД, формирование индексов =-
// TODO добавить поддержку безконечного количества уровней вложенности
// Получение перечня категорий, формироване индекса по category ID, наименованию и синонимам
$GLOBALS['cats'] = execSQL("SELECT occd.`category_id` AS 'category_id',occd.`name` AS 'name', occd.`meta_keyword` AS 'meta_keyword', occ.`parent_id` AS 'parent_id' FROM `oc_category_description` AS occd LEFT JOIN `oc_category` AS occ ON occ.`category_id`=occd.`category_id` WHERE occd.`language_id`=1;");
if (!$GLOBALS['cats']) $GLOBALS['cats'] = [];
$GLOBALS['cats_idxs'] = genIdxs(
	$GLOBALS['cats'], "category_id", [ "name", "meta_keyword" ],
	function ($item) { return $item["parent_id"] == 0; }
);
// Категории второго уровня складываем индекс группируя по родительским категориям, чтобы не затереть категории с одинаковым наименованием или синонимами
foreach ($GLOBALS['cats'] as $cat_id_idx => $cat)
	if ($cat["parent_id"] == 0)
		$GLOBALS['cats_idxs'][$cat_id_idx] = genIdxs(
			$GLOBALS['cats'], "category_id", [ "name", "meta_keyword" ],
			function ($item) use ($cat_id_idx) { return str2idx($item["parent_id"]) === $cat_id_idx; }
		);
// В случае отсутствия категории "неотсортированные" - создаем
if (!array_key_exists(str2idx(UNSORTED_CAT_ID), $GLOBALS['cats'])) {
	$datetimenow = date("Y-m-d H:i:s");
	// Создание записи в таблице `oc_category`
	execSQL(
		"INSERT INTO `oc_category` (`category_id`,`image`,`top`,`column`,`status`,`date_added`,`date_modified`)
    	VALUES (".UNSORTED_CAT_ID.",'',1,1,0,'".$datetimenow."','".$datetimenow."');"
    );
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
if (!$GLOBALS['mans']) $GLOBALS['mans'] = [];
$GLOBALS['mans_idxs'] = genIdxs($GLOBALS['mans'], "manufacturer_id", "name");
// Получение ID производителя Кингсилк, создание, в случае его отстутсвия
$GLOBALS['kingsilk_man_id'] = array_key_exists("_КИНГСИЛК", $GLOBALS['mans_idxs']) 
				 ? $GLOBALS['mans_idxs']["_КИНГСИЛК"] 
				 : NULL;
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
if (!$GLOBALS['prods']) $GLOBALS['prods'] = [];
$GLOBALS['prods_idxs'] = genIdxs($GLOBALS['prods'], "product_id", "meta_keyword");
// Получение перечня опций товаров, формирование индекса по option ID и наименованиб
$GLOBALS['opts'] = execSQL("SELECT `option_id`,`name` FROM `oc_option_description` WHERE `language_id`=1;");
if (!$GLOBALS['opts']) $GLOBALS['opts'] = [];
$GLOBALS['opts_idxs'] = genIdxs($GLOBALS['opts'], "option_id", "name");
// Получение перечня значений опций товаров, формирование индекса по option value ID и наименованиям
$GLOBALS['opts_vals'] = execSQL("SELECT `option_value_id`,`name`,`option_id` FROM `oc_option_value_description` WHERE `language_id`=1;");
$GLOBALS['opts_vals_idxs'] = [];
// Группируем значения опций по индексам ID самих опций, чтобы индексы наименований не затерли значения от разных опций
foreach ($GLOBALS['opts'] as $opt_id_idx => $opt)
	$GLOBALS['opts_vals_idxs'][$opt_id_idx] = genIdxs(
		$GLOBALS['opts_vals'], "option_value_id", "name", 
	    function ($item) use ($opt_id_idx) { return str2idx($item["option_id"]) === $opt_id_idx; }
	);
// Получение перечня групп атрибутов, формирование индексов по attribute group ID и наименованию
$GLOBALS['attrs_groups'] = execSQL("SELECT `attribute_group_id`,`name` FROM `oc_attribute_group_description` WHERE `language_id`=1");
if (!$GLOBALS['attrs_groups']) $GLOBALS['attrs_groups'] = [];
$GLOBALS['attrs_groups_idxs'] = genIdxs($GLOBALS['attrs_groups'], "attribute_group_id", "name");
// Получение перечня атрибутов, формирование индексов по attribute ID и наименованию
$GLOBALS['attrs'] = execSQL("SELECT oca.`attribute_id` AS 'attribute_id', ocad.`name` AS 'name', oca.`attribute_group_id` AS 'attribute_group_id'
				  FROM `oc_attribute` AS oca LEFT JOIN `oc_attribute_description` AS ocad
				  ON oca.`attribute_id`=ocad.`attribute_id` WHERE ocad.`language_id`=1;");
if (!$GLOBALS['attrs']) $GLOBALS['attrs'] = [];
$GLOBALS['attrs_idxs'] = [];
// группировка по индексу идентификатора группы атрибутов, во избежании затирания индексов наименований атрибутов от разных групп
foreach ($GLOBALS['attrs_groups'] as $attrs_group_id_idx => $attrs_group)
	$GLOBALS['attrs_idxs'][$attrs_group_id_idx] = genIdxs(
		$GLOBALS['attrs'], "attribute_id", "name",
	    function ($item) use ($attrs_group_id_idx) { return str2idx($item["attribute_group_id"]) === $attrs_group_id_idx; }
	);
// Получение перечня групп фильтров, построение индекса по filter group ID и наименованию
$GLOBALS['filts_groups'] = execSQL("SELECT `filter_group_id`,`name` FROM `oc_filter_group_description` WHERE `language_id`=1");
if (!$GLOBALS['filts_groups']) $GLOBALS['filts_groups'] = [];
$GLOBALS['filts_groups_idxs'] = genIdxs($GLOBALS['filts_groups'], "filter_group_id", "name");
// Получение фильтров, построение индексов по filter ID и наименованию
$GLOBALS['filts'] = execSQL("SELECT `filter_id`,`filter_group_id`,`name` FROM `oc_filter_description` WHERE `language_id`=1");
if (!$GLOBALS['filts']) $GLOBALS['filts'] = [];
$GLOBALS['filts_idxs'] = [];
// группировка по индексу идентификатора фильтра, во избежании затирания индексов фильтров от разных групп
foreach ($GLOBALS['filts_groups'] as $filts_group_id_idx => $filts_group)
	$GLOBALS['filts_idxs'][$filts_group_id_idx] = genIdxs(
		$GLOBALS['filts'], "filter_id", "name",
	    function ($item) use ($filts_group_id_idx) { return str2idx($item["filter_group_id"]) === $filts_group_id_idx; }
	);

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
	if ($exists_prod) $prod = array_merge($exists_prod, $prod);
	$diff_count = 0;
	$params = [];
	foreach ($offers_group as $offer) {
		if (!$prod["name"])
			str_replace((string)$offer->article, "", (string)$offer->model);
		if (!$prod["description"])
			$prod["description"] = (string)$offer->description;
		$article = (string)$offer->article;
		$cat_outer = $GLOBALS['cats_outer_idxs'][str2idx((string)$offer->categoryId)];
		$cat_name = (string)$cat_outer;
		$cat_name_idx = str2idx($cat_name);
		$is_found_cat = FALSE;
		// Ищем подходящую категорию
		if (!array_key_exists($cat_name_idx, $GLOBALS['cats_idxs']))
			foreach ($GLOBALS['cats_idxs'] as $idx => $cats_group_idx)
				if (is_array($cats_group_idx) && array_key_exists($cat_name_idx, $cats_group_idx)){
					$is_found_cat = TRUE;
					$cat = $GLOBALS['cats'][$GLOBALS['cats_idxs'][$idx][$cat_name_idx]];
					$prod["cats"][] = $cat;
					if (in_array((int)$cat["category_id"], PRODS_WTH_CAT_NAME_PREFIX_CAT_ID) 
						&& strpos($cat["name"], $prod["name"]) === FALSE)
						$prod["name"] = $cat["name"] . " " . $prod["name"];
				}
		else{
			$is_found_cat = TRUE;
			$cat = $GLOBALS['cats'][$GLOBALS['cats_idxs'][$cat_name_idx]];
			$prod["cats"][] = $cat;
			if (in_array((int)$cat["category_id"], PRODS_WTH_CAT_NAME_PREFIX_CAT_ID) && strpos($cat["name"], $prod["name"]) === FALSE)
				$prod["name"] = $cat["name"] . " " . $prod["name"];
		}
		if (!$is_found_cat){
			var_dump("INSERT INTO `oc_category` (`parent_id`,`top`,`column`,`status`,`date_added`,`date_modified`)
				VALUES (".UNSORTED_CAT_ID.",1,1,0,'".date("Y-m-d H:i:s")."','".date("Y-m-d H:i:s")."')");
			var_dump(execSQL("INSERT INTO `oc_category` (`parent_id`,`top`,`column`,`status`,`date_added`,`date_modified`)
				VALUES (".UNSORTED_CAT_ID.",1,1,0,'".date("Y-m-d H:i:s")."','".date("Y-m-d H:i:s")."')"));
			$cat_id = (string)$GLOBALS["mysqli"]->insert_id;
			var_dump($cat_id);
			var_dump("356:
				INSERT INTO `oc_category_description` (`category_id`,`language_id`,`name`,`description`,`meta_title`,`meta_description`,`meta_keyword`)
				VALUES (".$cat_id.",1,'".$cat_name."','','".$cat_name."','','');");
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
			$prod["cats"][] = $GLOBALS['cats'][$cat_id_idx];
			$cats_add_count++;
		}
		// Собираем артикулы, для формирвоания значения поля meta_keyword
		$prod["articles"][] = $article;
		$articleA = preg_split("/\-/", $article);
		if (!$prod["article"]) $prod["article"] = $articleA;
		if (abs(count($prod["article"]) - count($articleA)) > 1)
			throw new Exception("ERROR: " . implode("-", $articleA) . " different length with " . implode("-", $prod["article"]) . " too much");
		// Сопоставляем артикулы, для вычисления общего
		foreach ($prod["article"] as $loop_idx => $article_part) {
			if ($loop_idx < count($articleA) && $article_part != "*" && $article_part != $articleA[$loop_idx]) {
				$diff_count++;
				$prod["article"][$loop_idx] = "*";
				if ($diff_count >= count($prod["article"])) {
					echo var_dump($offers_group);
					throw new Exception("ERROR: too much different parts of articles: " . implode("-", $articleA) . ", " . implode("-", $prod["article"]));
				}
			}
		}
		// Сопоставляем цену, для вычисления общей
		if (!$prod["price"] || $prod["price"] > (float)$offer->price) $prod["price"] = (float)$offer->price;
		// Агрегируем остаток по всем предложениям, чтобы сформировать общий остаток по товару
		$prod["quantity"] += (int)$offer->amount;
		// Перебираем картинки предложения и складываем уникальные в индекс общих картинок, которые впоследствии станет перечнем изображений товара
		foreach ($offer->picture as $_picture_url) {
			$picture_url = (string)$_picture_url;
			$hash = "_" . strtoupper((string)hash_file("md5", $picture_url));
			if (!array_key_exists($hash, $prod["images_idxs"])) {
				$cur_image = new Imagick($picture_url);
				$cur_image->adaptiveResizeImage(32,32);
				$is_unique = TRUE;
				foreach ($prod["images_idxs"] as $hash => $image)
					if (compareImages($cur_image, $image["imagick"])){
						$is_unique = FALSE;
						break;
					}
				if ($is_unique) $prod["images_idxs"][$hash] = [
					"imagick" => $cur_image, 
					"url" => $picture_url,
					"is_main" => !(bool)count($prod["images_idxs"])
				];
			}
		}
		// Генерируем массив параметров с целью вычислить различные у данной группы товаров
		foreach ($offer->param as $param) {
			$param_name = explode(",", (string)$param["name"])[0];
			$param_idx = str2idx($param_name);
			$param_value = (string)$param["name"];
			$param_value_idx = str2idx($param_value);
			if (!array_key_exists($param_idx, $params))
				$params[$param_idx] = [
					"name" => $param_name,
					"values" => [ $param_value_idx ]
				];
			elseif (!in_array($param_value_idx, $params[$param_idx]["values"]))
				$params[$param_idx]["values"][] = $param_value_idx;
		}
	}
	// Если предложений несколько, то проверяем существование подходящей опции, в ее отсутствии создаем новую
	if ($diff_count){
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
		} 
		$opt_id_idx = $GLOBALS['opts_idxs'][$opt_name_idx];
		$prod["opt"] = $GLOBALS['opts'][$opt_id_idx];
	}
	
	// Собираем массив значений опций, проверяем каждую на наличие, при отсутствуии оных - создаем
	// Параллельно собираем массив атрибутов и групп атрибутов, фильтров и их групп
	foreach ($offers_group as $offer) {
		// Формируем наименование значения опции, подходящее данному предложению
		$cur_opt_val_namesA = [];
		foreach ($offer->param as $param) {
			$param_full_name = (string)$param["name"];
			$param_name = explode(",", $param_full_name)[0];
			$param_full_idx = str2idx($param_full_name);
			$param_idx = str2idx($param_name);
			$param_value = (string)$param["name"];
			// Собираем наименование значения опции
			if ($diff_count && count($params[$param_idx]["values"]) > 1) $cur_opt_val_namesA[] = $param_value;
			// Ищем группу фильтров в общем индексе, если нет - пропускаем
			if (array_key_exists($param_full_idx, $GLOBALS['filts_groups_idxs']) && !(array_key_exists($param_full_idx, $prod["filts_groups"]))) {
				$prod["filts_groups"][$param_full_idx] = $GLOBALS['filts_groups'][$GLOBALS['filts_groups_idxs'][$param_full_idx]];
				$filts_group = $prod["filts_groups"][$param_full_idx];
				$filts_group_id_idx = $GLOBALS['filts_groups_idxs'][$param_full_idx];
				$param_value_idx = str2idx($param_value);
				// Ищем фильтр в глобальных индексах, если нет - создаем
				if (!array_key_exists($param_value_idx, $GLOBALS['filts_idxs'][$filts_group_id_idx])){
					execSQL("INSERT INTO `oc_filter` (`filter_group_id`,`sort_order`)
						VALUES (".$filts_group["filter_group_id"].",0);");
					$filt_id = (string)$GLOBALS["mysqli"]->insert_id;
					execSQL("INSERT INTO `oc_filter_description` (`filter_id`,`language_id`,`filter_group_id`,`name`)
						VALUES (".$filt_id.",1,".$filts_group["filter_group_id"].",'".$param_value."');");
					execSQL("INSERT INTO `oc_category_filter` (`category_id`,`filter_id`)
						VALUES (".$cat_id.",".$filt_id.");"); // ТУТ НУЖЕН $cat_id!!!
					$filt_id_idx = str2idx($filt_id);
					$GLOBALS['filts'][$filt_id_idx] = [
						"filter_group_id" => $filts_group["filter_group_id"],
						"name" => $param_value,
						"filter_id" => $filt_id
					];
					$GLOBALS['filts_idxs'][$filts_group_id_idx][$param_full_idx] = $filt_id_idx;
					$filts_add_count++;
				}
				// Проверяем есть ли данный фильтр в локальных списках конкретного товара, если нет добавляем
				if (!array_key_exists($param_full_idx, $prod["filts"]))
					$prod["filts"][$param_full_idx] = $GLOBALS['filts'][$GLOBALS['filts_idxs'][$filts_group_id_idx][$param_full_idx]];
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
				$GLOBALS['attrs'][$attr_id_idx] = [
					"attribute_id" => $attr_id,
					"attribute_group_id" => $attr_group["attribute_group_id"],
					"name" => $param_value,
					"attribute_value_id" => (string)$GLOBALS["mysqli"]->insert_id
				];
				$GLOBALS['attrs_idxs'][$attr_group_id_idx][$param_value_idx] = $attr_id_idx;
				$attrs_add_count++;
			}
			// Ищем атрибут в локально перечне атрибутов, если нет - добавляем
			if (!array_key_exists($param_value_idx, $prod["attrs"]))
				$prod["attrs"][$param_value_idx] = $GLOBALS['attrs'][$GLOBALS['attrs_idxs'][$attr_group_id_idx][$param_value_idx]];
		}
		if ($diff_count){
			$opt_val = [
				"name" => implode(" / ", $cur_opt_val_namesA),
				"quantity" => (int)$offer->amount,
				"price" => (float)$offer->price - $prod["price"],
				"option_id" => $prod["opt"]["option_id"]
			];
			// Проверяем существует ли подходящее значение опции, если нет - создаем
			$opt_val_name_idx = str2idx($opt_val["name"]);
			if (!array_key_exists($opt_val_name_idx, $GLOBALS['opts_vals_idxs'][$opt_id_idx])) {
				execSQL("INSERT INTO `oc_option_value` (`option_id`,`image`,`sort_order`)
						 VALUES (".$p_option_id.",'',0);");
				$opt_val["option_value_id"] = (string)$GLOBALS["mysqli"]->insert_id;
				execSQL("INSERT INTO `oc_option_value_description` (`option_value_id`,`language_id`,`option_id`,`name`)
					VALUES (".$opt_val["option_value_id"].",1,".$opt_val["option_id"].",'".$opt_val["name"]."')");
				$opt_val_id_idx = str2idx($opt_val["option_value_id"]);
				$GLOBALS['opts_vals'][$opt_val_id_idx] = $opt_val;
				$GLOBALS['opts_vals_idxs'][$opt_id_idx][$opt_val_name_idx] = $opt_val_id_idx;
				$opts_vals_add_count++;
			}
			// добавляем значение опции по ключу в виде индекса option_value_id само значение опции
			$prod["opt_vals"][$GLOBALS['opts_vals_idxs'][$opt_id_idx][$opt_val_name_idx]] = $GLOBALS['opts_vals'][$GLOBALS['opts_vals_idxs'][$opt_val_name_idx]];
		}
	}
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
			$prod_id_idx = str2idx($GLOBALS['prods_idxs'][$article_idx]);
			$found_prods_idxs[$prod_id_idx][] = $loop_idx;
		}
	}
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
			if (!$main_prod_id_idx || count($found_prods_idxs[$main_prod_id_idx]) < count($offers_loop_idxs)) 
				$main_prod_id_idx = $prod_id_idx;
		}
		foreach ($offers_group["offers"] as $offer) {
			$article_idx = str2idx((string)$offer->article); // Артикул, преобразованный в индекс
			if (array_key_exists($article_idx, $GLOBALS['prods_idxs']))
				$offers_groups2upd_prods[$GLOBALS['prods_idxs'][$article_idx]][] = $offer;
			else
				$offers_groups2upd_prods[$main_prod_id_idx][] = $offer;
		}
	}
}
$prods2updating = [];
foreach ($offers_groups2upd_prods as $prod_id_idx => $offers_group)
	$prods2updating[] = convertOffersGroup2Product($offers_group, $GLOBALS['prods'][$prod_id_idx]);
printLog(
	"Compare import and exists data, convert offers group to products:
	 - products to updating count: " . count($prods2updating) . "
	 - products to adding count: " . count($prods2adding) . "
	 - added options count: " . count($opts_add_count) . "
	 - added options values count: " . count($opts_vals_add_count) . "
	 - added filters count: " . count($filts_add_count) . "
	 - added categories count: " . count($cats_add_count) . "
	 - added attributes groups count: " . count($attrs_groups_add_count) . "
	 - added attributes count: " . count($attrs_add_count)
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
	$ss_id = $price && $quantity ? "7" : "5";
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
			 ".$ss_id.",
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
		if ($image["is_main"])
			execSQL("UPDATE `oc_product` SET `image`='".$image_path."' WHERE product_id=".$p_id.";");  
		else{
	    	execSQL("INSERT INTO `oc_product_image` (`product_id`, `image`, `product_option_value_id`)
	    		VALUES (".$p_id.", '".$image_path."', 0)");
	    	$prod_img_add_count++;
		}
	}
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
    }
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
		if (!$prod["opt_vals"]) throw new Exception("undefined 'opt_vals', but 'opt' is exists!");
		$prod_opt_add_count++;
		$sql_prod_opt_val = "";
		foreach ($prod["opt_vals"] as $opt_val){
			$sql_prod_opt_val .= "(".$prod["opt"]["product_option_id"].",".$p_id.",".$prod["opt"]["option_id"].",".$opt_val["option_value_id"].",".$opt_val["quantity"].",1,".$opt_val["price"].",'+',0,'+',0,'+'),";
			$prod_opt_val_add_count++;
		}
		if ($sql_prod_opt_val)
			execSQL("INSERT INTO `oc_product_option_value` (`product_option_id`,`product_id`,`option_id`,`option_value_id`,
															`quantity`,`subtract`,`price`,`price_prefix`,`points`,`points_prefix`,
															`weight`,`weight_prefix`) VALUES ".substr($opt_val_ins_sql, 0, -1).";");
    }
    // Создаем связки с фильтрами, если есть
    if ($prod["filts_groups"]){
    	$sql_prod_filt = "";
    	$sql_bf_filt = "";
    	if (!$prod["filts"]) throw new Exception("undefined product filters, but product filters groups is exists!");
    	foreach ($prod["filts"] as $filt){
    		$sql_prod_filt .= "(".$p_id.",".$filt["filter_id"]."),";
    		$sql_bf_filt .= "(".$p_id.",'f".$filt["filter_group_id"]."',".$filt["filter_id"].",1,0),";
    		$prod_filt_add_count++;
    	}
    	if ($sql_prod_filt)
    		execSQL("INSERT INTO `oc_product_filter` (`product_id`,`filter_id`) VALUES ".substr($sql_prod_filt, 0, -1).";");
    	if ($sql_bf_filt)
    		execSQL("INSERT INTO `oc_bf_filter` (`product_id`,`filter_group`,`filter_id`,`language_id`,`out_of_stock`)
							VALUES ".substr($sql_bf_filt, 0, -1).";");
    }
    // Создаем связку с атрибутами, если есть
    if ($prod["attrs_groups"]){
    	$sql_prod_attr = "";
    	$sql_bf_attr_val = "";
    	if (!$prod["attrs"]) throw new Exception("undefined product attributes, but product attributes groups is exists!");
    	foreach ($prod["attrs"] as $attr){
    		$sql_prod_attr .= "(".$p_id.",".$attr["attribute_id"].",1,''),";
    		$sql_bf_attr_val .= "(".$p_id.",".$attr["attribute_id"].",".$attr["attribute_value_id"].",1),";
    		$prod_attr_add_count++;
    	}
    	if ($sql_prod_attr)
    		execSQL("INSERT INTO `oc_product_attribute` (`product_id`,`attribute_id`,`language_id`,`text`) VALUES ".substr($sql_prod_attr, 0, -1).";");
    	if ($sql_bf_attr_val)
    		execSQL("INSERT INTO `oc_bf_product_attribute_value` (`product_id`,`attribute_id`,`attribute_value_id`,`language_id`)
					VALUES ".substr($sql_bf_attr_val, 0, -1).";");
    }
    $prods_add_count++;
}
printLog(
	"Products adding completed:
	 - products added count: " . count($prods_add_count) . "
	 - relationship products and attributes added count: " . count($prod_attr_add_count) . "
	 - relationship products and filters added count: " . count($prod_filt_add_count) . "
	 - relationship products and images added count: " . count($prod_img_add_count) . "
	 - relationship products and options added count: " . count($prod_opt_add_count) . "
	 - relationship products and options values added count: " . count($prod_opt_val_add_count) . "
	 - relationship products and categories added count: " . count($prod_cat_add_count)
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
	$ss_id = $price && $quantity ? "7" : "5";
	// Проверяем product ID
	$p_id = $prod["product_id"];
	if (!$p_id || $p_id == "0") throw new Exception("Recieved wrong product_id");
	// TODO проверять существующие изображения, добавлять только новые
	// Обновляем изображения
	execSQL("DELETE FROM `oc_product_image` WHERE `product_id`=".$p_id.";");
	foreach ($prod["images_idxs"] as $hash_idx => $image) {
		$image_path = IMAGE_PATH_PREFIX . $hash_idx . ".jpg";
		if (!file_exists($IMAGES_FULL_PATH . $hash_idx . ".jpg"))
			file_put_contents(
				$IMAGES_FULL_PATH . $hash_idx . ".jpg", 
				file_get_contents($image["url"])
			);
		if ($image["is_main"])
			execSQL("UPDATE `oc_product`
				SET `quantity`=".$prod["quantity"].",
					`stock_status_id`=".$ss_id.",
					`price`=".$prod["price"].",
					`date_modified`='".date("Y-m-d H:i:s")."',
					`image`='".$image_path."'
				WHERE `product_id`=".$p_id.";"); 
		else{
	    	execSQL("INSERT INTO `oc_product_image` (`product_id`, `image`, `product_option_value_id`)
	    		VALUES (".$p_id.", '".$image_path."', 0)");
	    	$prod_img_add_count++;
		}
	}
	// Обновляем описание товара
	execSQL("UPDATE `oc_product_description`
	    	 SET `name`='".$prod["name"]."',
	    	 	 `description`='".$prod["description"]."',
	    	 	 `meta_title`'".$prod["name"]."',
	    	 	 `meta_keyword`='".implode("; ",$prod["articles"])."'
    	 	 WHERE `product_id`=".$p_id." AND `language_id`=1;"
    );
    // Обновляем связки с категориями, забираем все имеющиеся, и добавляем только те, которых нет
    $prod_cats = execSQL("SELECT category_id FROM oc_product_to_category WHERE `product_id`=".$p_id.";");
    foreach ($prod["cats"] as $cat)
    	if (!array_key_exists(str2idx($cat["category_id"]), $prod_cats)){
			execSQL(
		    	"INSERT INTO `oc_product_to_category` (`product_id`,`category_id`)
		    	VALUES (".$p_id.",".$cat["category_id"].");"
		    );
    		$prod_cat_add_count++;
    	}
    // Проверяем наличие нескольких необходимых для отображения товара записей, создаем, если их нет
	$prod2layout = execSQL("SELECT product_id FROM oc_product_to_layout WHERE `product_id`=".$p_id.";", "num_rows");
	if (!$prod2layout){
		execSQL(
	    	"INSERT INTO `oc_product_to_layout` (`product_id`,`store_id`,`layout_id`)
	    	VALUES (".$p_id.",0,0);"
	    );
		$prod2layout_wrong[] = $p_id;
	}
	$prod2store = execSQL("SELECT product_id FROM oc_product_to_store WHERE `product_id`=".$p_id.";", "num_rows");
	if (!$prod2store){
	    execSQL(
	    	"INSERT INTO `oc_product_to_store` (`product_id`,`store_id`)
	    	VALUES (".$p_id.",0);"
	    );
	    $prod2store_wrong[] = $p_id;
	}
	$prod_bf_filter = execSQL("SELECT product_id FROM oc_bf_filter WHERE `product_id`=".$p_id." AND filter_group='m0';", "num_rows");
	if (!$prod_bf_filter){
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
    	execSQL("DELETE FROM oc_product_option_value WHERE WHERE `product_id`=".$p_id.";");
    	if ($prod_opts && !array_key_exists(str2idx($prod["opt"]["option_id"]), $prod_opts)){
    		execSQL("DELETE FROM oc_product_option WHERE `product_id`=".$p_id.";");
    		$prod_opts = FALSE;
    	}
    	if (!$prod_opts) {
	    	execSQL("INSERT INTO `oc_product_option` (`product_id`,`option_id`,`value`,`required`)
					VALUES (".$p_id.",".$prod["opt"]["option_id"].",'',1);");
	    	$prod["opt"]["product_option_id"] = (string)$GLOBALS["mysqli"]->insert_id;
	    	$prod_opt_add_count++;
    	} else
    		$prod["opt"]["product_option_id"] = $prod_opt["product_option_id"];
		if (!$prod["opt_vals"]) throw new Exception("undefined 'opt_vals', but 'opt' is exists!");

		$sql_prod_opt_val = "";
		foreach ($prod["opt_vals"] as $opt_val){
			$sql_prod_opt_val .= "(".$prod["opt"]["product_option_id"].",".$p_id.",".$prod["opt"]["option_id"].",".$opt_val["option_value_id"].",".$opt_val["quantity"].",1,".$opt_val["price"].",'+',0,'+',0,'+'),";
			$prod_opt_val_add_count++;
		}
		if ($sql_prod_opt_val)
			execSQL("INSERT INTO `oc_product_option_value` (`product_option_id`,`product_id`,`option_id`,`option_value_id`,
															`quantity`,`subtract`,`price`,`price_prefix`,`points`,`points_prefix`,
															`weight`,`weight_prefix`) VALUES ".substr($opt_val_ins_sql, 0, -1).";");
    } else {
    	// Если же их нет, то удаляем возможные "хвосты"
    	execSQL("DELETE FROM oc_product_option WHERE product_id=".$p_id.";");
    	execSQL("DELETE FROM oc_product_option_value WHERE product_id=".$p_id.";");
    }
    // Создаем связки с фильтрами, если есть
    if ($prod["filts_groups"]){
    	// TODO обновлять существующие свзяки c oc_product_filter и oc_bf_filter, а не удалять их каждый раз и создавать заново
    	execSQL("DELETE FROM oc_product_filter WHERE WHERE `product_id`=".$p_id.";");
    	execSQL("DELETE FROM oc_bf_filter WHERE WHERE `product_id`=".$p_id.";");
    	$sql_prod_filt = "";
    	$sql_bf_filt = "";
    	if (!$prod["filts"]) throw new Exception("undefined product filters, but product filters groups is exists!");
    	foreach ($prod["filts"] as $filt){
    		$sql_prod_filt .= "(".$p_id.",".$filt["filter_id"]."),";
    		$sql_bf_filt .= "(".$p_id.",'f".$filt["filter_group_id"]."',".$filt["filter_id"].",1,0),";
    		$prod_filt_add_count++;
    	}
    	if ($sql_prod_filt)
    		execSQL("INSERT INTO `oc_product_filter` (`product_id`,`filter_id`) VALUES ".substr($sql_prod_filt, 0, -1).";");
    	if ($sql_bf_filt)
    		execSQL("INSERT INTO `oc_bf_filter` (`product_id`,`filter_group`,`filter_id`,`language_id`,`out_of_stock`)
							VALUES ".substr($sql_bf_filt, 0, -1).";");
    } else {
    	// Если же их нет, то удаляем возможные "хвосты"
    	execSQL("DELETE FROM oc_product_filter WHERE product_id=".$p_id.";");
    	execSQL("DELETE FROM oc_bf_filter WHERE product_id=".$p_id.";");
    }
    // Создаем связку с атрибутами, если есть
    if ($prod["attrs_groups"]){
    	// TODO обновлять существующие свзяки c oc_product_filter и oc_bf_filter, а не удалять их каждый раз и создавать заново
    	execSQL("DELETE FROM oc_product_attribute WHERE WHERE `product_id`=".$p_id.";");
    	execSQL("DELETE FROM oc_bf_product_attribute_value WHERE WHERE `product_id`=".$p_id.";");
    	$sql_prod_attr = "";
    	$sql_bf_attr_val = "";
    	if (!$prod["attrs"]) throw new Exception("undefined product attributes, but product attributes groups is exists!");
    	foreach ($prod["attrs"] as $attr){
    		$sql_prod_attr .= "(".$p_id.",".$attr["attribute_id"].",1,''),";
    		$sql_bf_attr_val .= "(".$p_id.",".$attr["attribute_id"].",".$attr["attribute_value_id"].",1),";
    		$prod_attr_add_count++;
    	}
    	if ($sql_prod_attr)
    		execSQL("INSERT INTO `oc_product_attribute` (`product_id`,`attribute_id`,`language_id`,`text`) VALUES ".substr($sql_prod_attr, 0, -1).";");
    	if ($sql_bf_attr_val)
    		execSQL("INSERT INTO `oc_bf_product_attribute_value` (`product_id`,`attribute_id`,`attribute_value_id`,`language_id`)
					VALUES ".substr($sql_bf_attr_val, 0, -1).";");
    } else {
    	// Если же их нет, то удаляем возможные "хвосты"
    	execSQL("DELETE FROM oc_product_attribute WHERE product_id=".$p_id.";");
    	execSQL("DELETE FROM oc_bf_product_attribute_value WHERE product_id=".$p_id.";");
    }
	$prod_upd_count++;
}
printLog(
	"Products updating completed:
	 - products updated count: " . count($prod_upd_count) . "
	 - relationship products and attributes added count: " . count($prod_attr_add_count) . "
	 - relationship products and filters added count: " . count($prod_filt_add_count) . "
	 - relationship products and images added count: " . count($prod_img_add_count) . "
	 - relationship products and options added count: " . count($prod_opt_add_count) . "
	 - relationship products and options values added count: " . count($prod_opt_val_add_count) . "
	 - relationship products and categories added count: " . count($prod_cat_add_count) . "
	 - wrong relationship layout and product in product with IDs: " . implode(", ", $prod2layout_wrong) . "
	 - wrong relationship store and product in product with IDs: " . implode(", ", $prod2store_wrong) . "
	 - wrong relationship bf filter and product in product with IDs: " . implode(", ", $prod_bf_filter_wrong)
);
// -=-=-=-=-=-=-=-=-=-=-=-=-

printLog("Script completed by " . ((microtime(true) - $start) / 60) . " minutes");
?>