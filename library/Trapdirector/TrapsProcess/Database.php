<?php

namespace Trapdirector;

use Trapdirector\Logging;

use Exception;
use PDO;
use PDOException;

class Database
{

    // Databases
    protected $trapDB=null; //< trap database
    protected $idoDB=null; //< ido database
    public $trapDBType; //< Type of database for traps (mysql, pgsql)
    public $idoDBType; //< Type of database for ido (mysql, pgsql)
    
    protected $trapDSN; //< trap database connection params
    protected $trapUsername; //< trap database connection params
    protected $trapPass; //< trap database connection params
    public $dbPrefix; //< database tables prefix
    
    protected $idoSet; //< bool true is ido database set
    protected $idoDSN; //< trap database connection params
    protected $idoUsername; //< trap database connection params
    protected $idoPass; //< trap database connection params
    
    // Logging function
    
    protected $logging; //< logging class
    
    /**
     * @param Logging $logClass : where to log
     * @param array $dbParam : array of named params  type,host,dbname,username,[port],[password]
     */
    function __construct($logClass,$dbParam,$dbPrefix)
    {
        $this->logging=$logClass;
        $this->dbPrefix=$dbPrefix;
        
        $this->trapDSN=$this->setupDSN($dbParam);
        $this->trapUsername = $dbParam['username'];
        $this->trapPass = (array_key_exists('password', $dbParam)) ? $dbParam['password']:'';
        $this->trapDBType=$dbParam['db'];
        $this->logging->log('DSN : '.$this->trapDSN. ';user '.$this->trapUsername.' / prefix : '. $this->dbPrefix,INFO);
        $this->db_connect_trap();
        
    }
    
    /**
     * Setup and connect to IDO database
     * @param array $dbParam : array of named params
     */
    public function setupIDO($dbParam)
    {
        $this->idoDSN=$this->setupDSN($dbParam);
        $this->idoUsername = $dbParam['username'];
        $this->idoPass = (array_key_exists('password', $dbParam)) ? $dbParam['password']:'';
        $this->logging->log('DSN : '.$this->idoDSN. ';user '.$this->idoUsername,INFO);
        $this->idoDBType=$dbParam['db'];
        $this->db_connect_ido();
    }
    
    /**
     * Connect to IDO database
     * @return \PDO
     */
    public function db_connect_ido()
    {
        if ($this->idoDB != null) {
            // Check if connection is still alive
            try {
                $this->idoDB->query('select 1')->fetchColumn();
                return $this->idoDB;
            } catch (Exception $e) {
                // select 1 failed, try to reconnect.
                $this->logging->log('Database IDO connection lost, reconnecting',WARN);
            }
        }
        try {
            $this->idoDB = new PDO($this->idoDSN,$this->idoUsername,$this->idoPass);
        } catch (PDOException $e) {
            $this->logging->log('Connection failed to IDO : ' . $e->getMessage(),ERROR,'');
        }
        return $this->idoDB;
    }
    
    /**
     * Connect to Trap database
     * @return \PDO
     */
    public function db_connect_trap()
    {
        
        if ($this->trapDB != null) {
            // Check if connection is still alive
            try {
                $this->trapDB->query('select 1')->fetchColumn();
                return $this->trapDB;
            } catch (Exception $e) {
                // select 1 failed, try to reconnect.
                $this->logging->log('Database connection lost, reconnecting',WARN);
            }           
        }       
        try {
            $this->trapDB = new PDO($this->trapDSN,$this->trapUsername,$this->trapPass);
        } catch (PDOException $e) {
            $this->logging->log('Connection failed : ' . $e->getMessage(),ERROR,'');
        }
        return $this->trapDB;
    }
    
    /**
     * Setup dsn and check parameters
     * @param array $configElmt
     * @return string
     */
    protected function setupDSN($configElmt)  
    {
        if (!array_key_exists('db',$configElmt) ||
            !array_key_exists('host',$configElmt) ||
            !array_key_exists('dbname',$configElmt) ||
            !array_key_exists('username',$configElmt))
        {
            $this->logging->log('Missing DB params',ERROR);
            return ''; 
        }
        
        //	$dsn = 'mysql:dbname=traps;host=127.0.0.1';
        $dsn= $configElmt['db'].':dbname='.$configElmt['dbname'].';host='.$configElmt['host'];
        
        if (array_key_exists('port', $configElmt))
        {
            $dsn .= ';port='.$configElmt['port'];
        }
        return $dsn;
    }

    
    /** Create database schema
     *	@param $schema_file	string File to read schema from
     *	@param $table_prefix string to replace #PREFIX# in schema file by this
     */
    public function create_schema($schema_file,$table_prefix)
    {
        //Read data from snmptrapd from stdin
        $input_stream=fopen($schema_file, 'r');
        
        if ($input_stream=== false)
        {
            $this->logging->log("Error reading schema !",ERROR,'');
            return;
        }
        $newline='';
        $cur_table='';
        $cur_table_array=array();
        $db_conn=$this->db_connect_trap();
        
        while (($line=fgets($input_stream)) !== false)
        {
            $newline.=chop(preg_replace('/#PREFIX#/',$table_prefix,$line));
            if (preg_match('/; *$/', $newline))
            {
                $sql= $newline;
                if ($db_conn->query($sql) === false) {
                    $this->logging->log('Error create schema : '.$sql,ERROR,'');
                    return;
                }
                if (preg_match('/^ *CREATE TABLE ([^ ]+)/',$newline,$cur_table_array))
                {
                    $cur_table='table '.$cur_table_array[1];
                }
                else
                {
                    $cur_table='secret SQL stuff :-)';
                }
                $this->logging->log('Creating : ' . $cur_table,INFO );
                $newline='';
            }
        }
        
        $sql= $newline;
        if ($sql != '' )
        {
            if ($db_conn->query($sql) === false) {
                $this->logging->log('Error create schema : '.$sql,ERROR,'');
                return;
            }
        }
        $this->logging->log('Schema created',INFO);
    }
    
    /**
     * Update database schema from current (as set in db) to $target_version
     *     @param $prefix string file prefix of sql update File
     *     @param $target_version int target db version number
     *     @param $table_prefix string to replace #PREFIX# in schema file by this
     *     @param bool $getmsg : only get messages from version upgrades
     *     @return string : if $getmsg=true, return messages.
     */
    public function update_schema($prefix,$target_version,$table_prefix,$getmsg=false)
    {
        // Get current db number
        $db_conn=$this->db_connect_trap();
        $sql='SELECT id,value from '.$this->dbPrefix.'db_config WHERE name=\'db_version\' ';
        $this->logging->log('SQL query : '.$sql,DEBUG );
        if (($ret_code=$db_conn->query($sql)) === false) {
            $this->logging->log('Cannot get db version. Query : ' . $sql,2,'');
            return 'ERROR';
        }
        $version=$ret_code->fetchAll();
        $cur_version=$version[0]['value'];
        $db_version_id=$version[0]['id'];
        
        if ($this->trapDBType == 'pgsql')
        {
            $prefix .= 'update_pgsql/schema_';
        }
        else
        {
            $prefix .= 'update_sql/schema_';
        }
        //echo "version all :\n";print_r($version);echo " \n $cur_ver \n";
        if ($getmsg === true)
        {
            $message='';
            $this->logging->log('getting message for upgrade',DEBUG );
            while($cur_version<$target_version)
            {
                $cur_version++;
                $updateFile=$prefix.'v'.($cur_version-1).'_v'.$cur_version.'.sql';
                $input_stream=fopen($updateFile, 'r');
                if ($input_stream=== false)
                {
                    $this->logging->log("Error reading update file ". $updateFile,2,'');
                    return 'ERROR';
                }
                do { $line=fgets($input_stream); }
                while ($line !== false && !preg_match('/#MESSAGE/',$line));
                if ($line === false)
                {
                    $this->logging->log("No message in file ". $updateFile,2,'');
                    return 'ERROR';
                }
                $message .= ($cur_version-1) . '->' . $cur_version. ' : ' . preg_replace('/#MESSAGE : /','',$line)."\n";
            }
            return $message;
        }
        while($cur_version<$target_version)
        { // tODO : execute pre & post scripts
            $cur_version++;
            $this->logging->log('Updating to version : ' .$cur_version ,INFO );
            $updateFile=$prefix.'v'.($cur_version-1).'_v'.$cur_version.'.sql';
            $input_stream=fopen($updateFile, 'r');
            if ($input_stream=== false)
            {
                $this->logging->log("Error reading update file ". $updateFile,2,'');
                return 'ERROR';
            }
            $newline='';
            $db_conn=$this->db_connect_trap();
            $db_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            while (($line=fgets($input_stream)) !== false)
            {
                if (preg_match('/^#/', $line)) continue; // ignore comment lines
                $newline.=chop(preg_replace('/#PREFIX#/',$table_prefix,$line));
                if (preg_match('/; *$/', $newline))
                {
                    $sql_req=$db_conn->prepare($newline);
                    if ($sql_req->execute() === false) {
                        $this->logging->log('Error create schema : '.$newline,1,'');
                    }
                    $cur_table_array=array();
                    if (preg_match('/^ *([^ ]+) TABLE ([^ ]+)/',$newline,$cur_table_array))
                    {
                        $cur_table=$cur_table_array[1] . ' SQL table '.$cur_table_array[2];
                    }
                    else
                    {
                        $cur_table='secret SQL stuff :-)';
                        //$cur_table=$newline;
                    }
                    $this->logging->log('Doing : ' . $cur_table,INFO );
                    
                    $newline='';
                }
            }
            fclose($input_stream);
            
            //$sql= $newline;
            //if ($db_conn->query($sql) === false) {
            //    $this->logging->log('Error updating schema : '.$sql,1,'');
            //}
            
            $sql='UPDATE '.$this->dbPrefix.'db_config SET value='.$cur_version.' WHERE ( id = '.$db_version_id.' )';
            $this->logging->log('SQL query : '.$sql,DEBUG );
            if ($db_conn->query($sql) === false) {
                $this->logging->log('Cannot update db version. Query : ' . $sql,2);
                return 'ERROR';
            }
            
            $this->logging->log('Schema updated to version : '.$cur_version ,INFO);
        }
    }
    
    
}