# Script for importing goods from YML into OpenCart 3

## Environment
- Language: ** PHP 7.2 **
- Product source: ** YML supplier **
- DB: ** MySQL 5.6.39-83.1 **
- Web server: ** Apache / 2.2.15 (CentOS) **
- Database client version: ** libmysql - 5.5.35 **
- PHP extension: ** mysqli, Imagick **
- Server encoding: ** UTF-8 Unicode (utf8) **

## Short description
The script groups the supplier's offers by the same or similar pictures and forms products, options and option values ​​according to the following principles:
- 1 group of offers <=> 1 product
- 1 sentence <=> 1 product option value

The relationship between offers and products is due to the storage of vendor SKUs in the product's `meta_keyword` field.
A site moderator can manually rearrange product options by moving vendor offer SKUs from one product to another.
The comparison of categories is realized by the name and synonyms of the categories. Synonyms are stored in the category's `meta_keyword` field.
Unrecognized supplier categories and products belonging to these categories are loaded into the disabled category ** Unsorted upon import **.

## Algorithm description
1. Receiving data from the supplier in YML format, converting it into an associative array, where the key is md5 hash of the first picture of the offer, or very similar to the first picture, thus there is a primary grouping by identical and similar pictures.
2. To be able to compare the Russian text after simplifying it, in order to avoid cases with duplicated spaces, for example, data on goods, attributes, categories, filters, manufacturers, options and option values ​​are taken from the database and turned into "search indices", where indices is the result of the `str2idx` function (see below), indexes by ID are created for each entity, which store the data itself and separate indexes by name and synonyms (if any) that store ID indexes.
3. Comparison of the groups of offers received from the supplier and existing products and their options. The linking field here is `product [" meta_keyword "]`, into which, separated by semicolons, the SKUs of the offers that make up the product options, or the product itself, if there is only one corresponding offer for it. This allows the user to manually rearrange the product offers, so at this stage, it is necessary to accommodate changes from users.
4. Converting a group of offers into a product. Checking here:

 4.1 The presence of a valid category for the product, if one is not found, then the product, along with the supplier's category, is sent to the disabled category "Unsorted upon import". Later, the manager can indicate in the field `category [" meta_keyword "]` in the current category, separated by semicolons, the names of the supplier's categories, which will be regarded as synonyms during the next search for the current category, which will automatically match the categories.

 4.2 Availability of filters and filter groups matching the parameters available for each of the proposals. There are no filter groups matching the parameters, the latter are not created, and the filters themselves are not created either. If a suitable filter group is found, then if there are no suitable filters, they are created.

4.3 The presence of attributes and attribute groups, again suitable for the parameters. Unlike filters, both the groups and the attributes themselves are created if no suitable ones were found.

4.4 Availability of a product option suitable for the name. The name of a product option is made up of the names of differing parameters, for which everything that the field `,` is discarded. If there are several different parameters, for example, ** Size ** and ** Filler weight **, then they are written in one line through the separator `/`. The result is ** Filler Size / Weight **. If there is no suitable option, it is created.

4.5 Availability of option values ​​matching the parameter values. They are formed similarly to the names of options, but from the values ​​of different parameters. If absent, they are created.

4.6 Formation of the total price, according to the principle of the lowest. Each option, in turn, has a markup calculated according to the formula * offer price - total price *.

    4.7 Formation of the total balance by aggregating the balance for all proposals. Each option, in turn, retains information about the balances specific to it.

    4.8 Formation of a list of photographs. Photos of all proposals are sorted out, unique ones are selected.

## Abbreviations and definitions
- * prod * - product
- * opt * - option
- * val * - value
- * cat * - category
- * man * - manufacturer
- * idx * - index
- * desc * - description
- * cur * - current
- * Index * - a string and or a number cast to the type `string`, from which spaces have been 
    removed and the prefix" _ "has been added
    
