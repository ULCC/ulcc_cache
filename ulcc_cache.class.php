<?php

require_once("../../config.php");
require_once('constants.php');

class ulcc_cache {

    /******************
     * Allows data to be saved into the cache.
     *
     *
     * @param $cid      the user assigned id for the cached data
     * @param $plugin   the name of the plugin saving the data
     * @param $data     the data being cached
     * @param $expiration the expiration time of the data. The may be set to a unix timestamp
     *                    which will give the data a type of ULCC_CACHE_EXPIRE and will mean it will not be
     *                    cleared until manually cleared or the data expires. If not set the data will be given a type of
     *                    ULCC_CACHE_TEMPORARY and will be given the default expiretime e.g 4 hours time.
     *                    Value can also be ULCC_CACHE_PERMANENT in which case the data will not be cleared from
     *                    cache unless it is manually cleared or the default data expiration period o after last modification is
     *                    reached.
     * @param $table    the name of the table that the data will be saved to
     * @param $misc
     */
    function cache_set($cid,$plugin,$data,$expiration=ULCC_CACHE_TEMPORARY,$table='ulcc_cache',$misc='') {

        global  $DB;

        $serialized =   0;

        //we can not save data structures straight into the db so we must serialize it
        if (is_object($data) || is_array($data))  {
            $data   =   serialize($data);
            $serialized =   1;
        }

        //find out if the data has been
        $currentrecord  =   $DB->get_record($table,array('cid'=>$cid,'plugin'=>$plugin));

        $cache   =  new stdClass();


        if (!empty($currentrecord)) $cache->id  =   $currentrecord->id;

        $cache->cid     =   $cid;
        $cache->plugin  =   $plugin;
        $cache->data    =   $data;

        if ($expiration != ULCC_CACHE_TEMPORARY && $expiration != ULCC_CACHE_PERMANENT) {
            //the user has set an expiration time
            $cache->type            =   ULCC_CACHE_EXPIRE;
            $cache->expiration      =   $expiration;
        }   else if ($expiration == ULCC_CACHE_TEMPORARY)   {
            //the user wants the item to be temporary but no expiration time has been ser
            $cache->type            =   ULCC_CACHE_TEMPORARY;
            $cache->expiration      =   time() + ULCC_CACHE_TEMP_EXPIRATION_TIME;
        } else {
            //the cache type is permanent = the user will delete from cache table (or will be deleted after 5 days)
            $cache->type            =   ULCC_CACHE_PERMANENT;
        }

        $cache->serialized  =   $serialized;
        $cache->misc        =   $misc;
        $cache->timecreated =   time();

        return (!empty($currentrecord)) ? $DB->update_record($table,$cache)  : $DB->insert_record($table,$cache)  ;
    }

    /**************************
     * Returns data that has been saved into the cache table if it is present
     *
     * @param $cid
     * @param $plugin
     * @param $table
     * @return mixed bool or cached data
     */
    function cache_get($cid,$plugin,$table='ulcc_cache') {

        global  $DB,$CFG;

        $sql            =   "SELECT     *
                             FROM       {$CFG->prefix}{$table}
                             WHERE      cid         =   :cid
                             AND        plugin      =   :plugin
                             AND        expiration  <   :expire";

        $cacherecord     =   $DB->get_record_sql($sql,array('cid'=>$cid,'plugin'=>$plugin,'expire'=>time()));

        if (!empty($cacherecord))   {
            if (!empty($cacherecord->serialized))   {
                $cacherecord->data    =     unserialize($cacherecord->data);
            }
        }

        return  (empty($cacherecord))   ? false  : $cacherecord->data;
    }






    /*****************************************
     * Allows all non permanent expired cached data to be flushed (deleted) from the
     * table with the given name
     *
     * @param string $table
     */
    function cache_flush($table='ulcc_cache')  {

        global  $DB,$CFG;

        $sql    =  "DELETE FROM {$CFG->prefix}{$table}
                    WHERE (type != :type
                    AND   expiration  <= :time)
                    OR    (type = :permtype
                    AND   timecreated  > :cacheexpiration)";

        $DB->execute($sql,array('type'=>ULCC_CACHE_PERMANENT,
                                'time'=>time(),
                                'permtype'=>ULCC_CACHE_PERMANENT,
                                'cacheexpiration'=>time() +ULCC_CACHE_PERM_EXPIRATION_TIME));
    }

    /******************************************
     * Removes the given cahced data with the given cid from the given table
     *
     * @param null $cid the cid of the data that we will be removing
     *                  (can be set to * with wildcard = true if you wanrt to remove all
     *                   non permanent data from the given table)
     * @param string $plugin the name of the plugin whose data will be cleared
     * @param string $table the name of the table that the data will be cleared from
     * @param bool $wildcard use wildcard if you want to remove all data with the cid appended to it
     */
    function  cache_clear_all($cid=NULL,$plugin=NULL, $table = 'ulcc_cache', $wildcard = FALSE) {
        global $DB,$CFG;

        if (empty($cid)) {
            $this->cache_flush($table);
        } else if (!empty($plugin)) {

            if ($wildcard)  {
                if ($cid == '*') {
                    $DB->execute("DELETE FROM {$CFG->prefix}{$table} WHERE type != :type AND plugin = :plugin",array('type'=>ULCC_CACHE_PERMANENT, 'plugin'=>$plugin));
                } else {
                    $DB->execute("DELETE FROM {$CFG->prefix}{$table} WHERE cid LIKE :cid% AND plugin = :plugin",array('cid'=>$cid,'plugin'=>$plugin));
                }
            } else {
                $DB->execute("DELETE FROM {$CFG->prefix}{$table} WHERE cid = :cid AND plugin = :plugin ",array('cid'=>$cid, 'plugin'=>$plugin));
            }
        }
    }


    /***************************************************
     * Allows the user to register an alternative table to store cached data in. When registering a table
     * the table must already exist in the moodle db and contain all fields that are in the ulcc_cache table.
     * Registering a table ensures that the ulcc_cache cron job will clear any expired temporary data from the
     * table
     *
     * @param $table     the name of the table that will be registered
     * @return bool      true if table register false if not
     */
    function cache_register($table)   {

        global  $DB;

        //before we insert the record lets check that the tablename does not already exist
        $record     =   $DB->get_record('ulcc_cacheregister',array('name'=>$table));

        $tableexist             =   false;
        $fieldsexit             =   array();
        $fields                 =   array('id','cid','plugin','data','type','expiration','serialize','misc','timecreated');
        //lets check that the table exists
        $tableexist      =   $DB->get_manager()->table_exists($table);


        if (!empty($tableexist)) {
            foreach($fields as $f)   {
                 $fieldsexit[]    =   $DB->get_manager()->field_exists($table,$f);
            }
        }

        if (empty($record) && !empty($tableexist) && !in_array($fieldsexit,false)) {
            $cachetable     =   new stdClass();
            $cachetable->name   =   $table;
            return $DB->insert_record('ulcc_cacheregister',$cachetable);
        } else  {
            return false;
        }
    }

    /*************************
     * The function called by the cron file to clear all expired temporary data
     */
    function    cache_cron()    {

        global      $DB;

        $cachetables    =       $DB->get_records('ulcc_cacheregister');

        $this->cache_clear_all('','','ulcc_cache');

        if (!empty($cachetables)) {
            foreach ($cachetables as $ct)   {
                //clear all temporary files from the table with the given name
                if ($DB->table_exists($ct->name))   {
                    $this->cache_clear_all('','',$ct->name);
                }
            }
        }
    }

}