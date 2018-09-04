<?php
require("config.php");
function printLog($text) { echo sprintf("[%s] %s", date("Y-m-d H:i:s"), $text) . "\n"; }
$mysqli=NULL;
function execSQL($sql, &$mysqli=NULL, $mode="fetch_assoc") {
	
	$sql_rows = is_string($sql) ? array($sql) : $sql;
	if (!$mysqli || $mysqli->connect_errno) { 
		$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME); 
		if ($mysqli->connect_errno) throw new Exception("Can't connect to DB: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error);
		printf("default charset: %s\n", $mysqli->character_set_name());
		/* изменение набора символов на utf8 */
		if (!$mysqli->set_charset("utf8")) {
		    printf("set charset utf8 error: %s\n", $mysqli->error);
		    exit();
		} else {
		    printf("current charset: %s\n", $mysqli->character_set_name());
		}
	}
	$result = array();
	foreach ($sql_rows as $idx => $sql) {
		$res = $mysqli->query($sql);
		if (!$res) {
			printLog("SQL ERROR: ". $mysqli->error . "\n executable SQL: " . $sql . "\n\n");
		}
		if (!is_bool($res)){
			if ($mode == "fetch_assoc") { 
				while($row = $res->fetch_assoc()) {
			        $result[] = $row;
			    }
			} elseif ($mode =="num_rows") { 
			    $result[] = $res->num_rows;
			} else { throw new Exception("Recieved unexpected mode (".$mode.") for result return: ".$sql ); }
		} else { $result[] = $res; }
	}
	// if (strpos($mode, "close_conn_after") != FALSE) { $mysqli->close(); }
	return count($result) == 1 ? $result[0] : $result;
}
array_map("unlink", glob(IMAGES_PATH.IMAGE_PATH_PREFIX."_*.jpg"));
var_dump(execSQL("DELETE FROM oc_manufacturer WHERE `name` NOT LIKE 'Cleo'"));
var_dump(execSQL("DELETE A.*
          from oc_product A
          left join oc_manufacturer B
            on B.manufacturer_id=A.manufacturer_id
         where B.manufacturer_id is NULL and A.manufacturer_id is not NULL;"));
var_dump(execSQL("DELETE A.*
          from oc_product_description A
          left join oc_product B
            on B.product_id=A.product_id
         where B.product_id is NULL and A.product_id is not NULL;"));
var_dump(execSQL("DELETE A.*
          from oc_product_to_category A
          left join oc_product B
            on B.product_id=A.product_id
         where B.product_id is NULL and A.product_id is not NULL;"));
var_dump(execSQL("DELETE A.*
          from oc_product_to_layout A
          left join oc_product B
            on B.product_id=A.product_id
         where B.product_id is NULL and A.product_id is not NULL;"));
var_dump(execSQL("DELETE A.*
          from oc_product_to_store A
          left join oc_product B
            on B.product_id=A.product_id
         where B.product_id is NULL and A.product_id is not NULL;"));
var_dump(execSQL("DELETE A.*
          from oc_bf_filter A
          left join oc_product B
            on B.product_id=A.product_id
         where B.product_id is NULL and A.product_id is not NULL;"));
var_dump(execSQL("DROP TABLE IF EXISTS oc_updater_idx"));
var_dump(execSQL("DROP TABLE IF EXISTS oc_updater"));
var_dump(execSQL("DELETE A.*
          from oc_product_image A
          left join oc_product B
            on B.product_id=A.product_id
         where B.product_id is NULL and A.product_id is not NULL;"));
var_dump(execSQL("DELETE A.*
          from oc_product_option A
          left join oc_product B
            on B.product_id=A.product_id
         where B.product_id is NULL and A.product_id is not NULL;"));
var_dump(execSQL("DELETE A.*
          from oc_product_option_value A
          left join oc_product B
            on B.product_id=A.product_id
         where B.product_id is NULL and A.product_id is not NULL;"));
var_dump(execSQL("DELETE A.*
          from oc_product_attribute A
          left join oc_product B
            on B.product_id=A.product_id
         where B.product_id is NULL and A.product_id is not NULL;"));
var_dump(execSQL("DELETE A.*
          from oc_product_filter A
          left join oc_product B
            on B.product_id=A.product_id
         where B.product_id is NULL and A.product_id is not NULL;"));
var_dump(execSQL("delete A.*
          from oc_filter A
          left join oc_product_filter B
            on B.filter_id=A.filter_id
         where B.filter_id is NULL and A.filter_id is not NULL;"));
var_dump(execSQL("delete A.*
          from oc_filter_description A
          left join oc_filter B
            on B.filter_id=A.filter_id
         where B.filter_id is NULL and A.filter_id is not NULL;"));
var_dump(execSQL("delete A.*
          from oc_bf_filter A
          left join oc_filter B
            on B.filter_id=A.filter_id
         where B.filter_id is NULL and A.filter_id is not NULL;"));
var_dump(execSQL("delete A.*
          from oc_attribute A
          left join oc_product_attribute B
            on B.attribute_id=A.attribute_id
         where B.attribute_id is NULL and A.attribute_id is not NULL;"));
var_dump(execSQL("delete A.*
          from oc_attribute_description A
          left join oc_attribute B
            on B.attribute_id=A.attribute_id
         where B.attribute_id is NULL and A.attribute_id is not NULL;"));
var_dump(execSQL("delete A.*
          from oc_bf_product_attribute_value A
          left join oc_attribute B
            on B.attribute_id=A.attribute_id
         where B.attribute_id is NULL and A.attribute_id is not NULL;"));
var_dump(execSQL("delete A.*
          from oc_bf_attribute_value A
          left join oc_attribute B
            on B.attribute_id=A.attribute_id
         where B.attribute_id is NULL and A.attribute_id is not NULL;"));
var_dump(execSQL("DELETE FROM oc_category WHERE category_id>".UNSORTED_CAT_ID.";"));
var_dump(execSQL("delete A.*
          from oc_category_description A
          left join oc_category B
            on B.category_id=A.category_id
         where B.category_id is NULL and A.category_id is not NULL;"));
var_dump(execSQL("delete A.*
          from oc_category_filter A
          left join oc_category B
            on B.category_id=A.category_id
         where B.category_id is NULL and A.category_id is not NULL;"));
var_dump(execSQL("delete A.*
          from oc_category_path A
          left join oc_category B
            on B.category_id=A.category_id
         where B.category_id is NULL and A.category_id is not NULL;"));
?>