<?php

require_once("../../config.php");

require_once("ulcc_cache.class.php");

//instantiate the cache class
$cacheclass     =   new ulcc_cache();

$data   =   array('saveddata1','saveddata2');

var_dump($data);

//save data to the cache table
$cacheclass->cache_set('saveddata','datatest',$data);

$cacheclass->cache_set('saveddata2','datatest',$data,time()+320);

$cacheclass->cache_set('saveddata3','datatest',$data,ULCC_CACHE_PERMANENT);

//retrieve data from the cache table
$newdata   =   $cacheclass->cache_get('saveddata','datatest');

var_dump($newdata);

//remove data with the cid
$cacheclass->cache_clear_all('saveddata','datatest');

//remove all expired non permament data
$cacheclass->cache_clear_all();


?>