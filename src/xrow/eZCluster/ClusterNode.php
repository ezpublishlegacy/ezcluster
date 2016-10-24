<?php
namespace xrow\eZCluster;

use \SimpleXMLElement;
use \ezcTemplate;
use \stdClass;
use xrow\eZCluster\Resources\db;
use xrow\eZCluster\Resources\instance;
use xrow\eZCluster\Resources;
use Ssh;
use \ezcDbFactory;
use \ezcDbInstance;
use xrow\eZCluster\Resources\environment;

class ClusterNode extends Resources\instance
{

    const IS_ALIVE = "eZ Publish is alive";

    const SOLR_CONFIG_FILE = '/etc/sysconfig/ezfind';

    const CLUSTER_CONFIG_FILE = '/etc/cluster/cluster.conf';

    const CRONTAB_FILE = '/tmp/crontab.ec2-user';

    const HTTP_CONFIG_FILE = '/etc/httpd/sites/environment.conf';

    const EXPORTS_FILE = '/etc/exports';

    const SECURITY_GROUP = 'ezcluster';

    const PLACEMENT_GROUP = 'ezcluster';

    const MYSQL_DIR = "/var/lib/mysql";

    const LOG_DIR = "/var/log/ezcluster";
    
    const SOLR_MASTER_DIR = "/mnt/storage/ezfind";

    const SOLR_SLAVE_DIR = "/mnt/ephemeral/ezfind";

    const CRON_DEFAULT_MEMORY_LIMIT = "512M";
    // place in SDK
    public $dir;

    public static $config;

    private static $ec2;

    function __construct($id = null)
    {
        $this->dir = realpath(dirname(__FILE__) . '/../');
        if ($id === null) {
            $this->id = CloudInfo::getInstanceID();
        } else {
            $this->id = $id;
        }
        
        if (file_exists(CloudSDK::CONFIG_FILE)) {
            $str = file_get_contents(CloudSDK::CONFIG_FILE);
            $str = str_replace("xmlns=", "ns=", $str);
            self::$config = new \SimpleXMLElement($str);
        }
    }

    public function csr()
    {
        echo "Creating certificate signing request \n";
        system('openssl genrsa 4096 > /tmp/private.pem');
        system('openssl req -new -key /tmp/private.pem -out /tmp/csr.pem');
        system('openssl x509 -req -days 3650 -in /tmp/csr.pem -signkey /tmp/private.pem -out /tmp/certificate.pem');
        echo "Private key:\n";
        echo file_get_contents('/tmp/private.pem');
        echo "Certificate signing request:\n";
        echo file_get_contents('/tmp/csr.pem');
        echo "Self-signed Certificate:\n";
        echo file_get_contents('/tmp/certificate.pem');
        unlink('/tmp/private.pem');
        unlink('/tmp/csr.pem');
        unlink('/tmp/certificate.pem');
    }

    public function setupDatabase()
    {
        $xp = "/aws/cluster/environment/rds";
        $result = self::$config->xpath($xp);
        if (is_array($result) and count($result) > 0) {
            $masterdsn = (string) $result[0]['dsn'];
            $masterdetails = eZCluster/Resources/db::translateDSN($masterdsn);
        }
        
        $xp = "/aws/cluster/instance[role = 'database' and @name='" . $this->name() . "'] | /aws/cluster/instance[role = 'dev' and @name='" . $this->name() . "']";
        
        $result = self::$config->xpath($xp);
        if (is_array($result) and count($result) > 0) {
            $masterdsn = 'mysql://root@localhost';
            $masterdetails = ezcDbFactory::parseDSN($masterdsn);
        }
        if (! isset($masterdetails)) {
            return false;
        }
        try {
            $dbmaster = ezcDbFactory::create($masterdetails);
        } catch (\Exception $e) {
            return false;
        }
        ezcDbInstance::set($dbmaster);
        
        if ($dbmaster) {
            $xp = "/aws/cluster/environment/database[@dsn]";
            $result = self::$config->xpath($xp);
            
            foreach ($result as $db) {
                db::initDB((string) $db['dsn'], $dbmaster);
            }
            $xp = "/aws/cluster/environment/storage[@dsn]";
            $result = self::$config->xpath($xp);
            foreach ($result as $db) {
                db::initDB((string) $db['dsn'], $dbmaster);
            }
        }
    }

    public function copyDataFromSource($name = null, $copydb = true, $copydata = true)
    {
        $environment = new Resources\environment($name);
        
        if (! isset($environment->environment->datasource)) {
            throw new \Exception("datasource not defeined for $name");
        }
        $connection = parse_url($environment->environment->datasource['connection']);
        $configuration = new Ssh\Configuration($connection['host']);
        $authentication = null;
        if (isset($connection['pass'])) {
            $authentication = new Ssh\Authentication\Password($connection['user'], $connection['pass']);
        } elseif (file_exists ( "/root/.ssh/id_rsa" ) and file_exists ( "/root/.ssh/id_rsa.pub" ))
         {
            $authentication = new Ssh\Authentication\PublicKeyFile($connection['user'], '/root/.ssh/id_rsa.pub', '/root/.ssh/id_rsa');
        }
        else {
            throw new \Exception("Key or password not given for $name");
        }
        $session = new Ssh\Session($configuration, $authentication);
        if ($copydb) {
            if (isset($environment->environment->datasource->database['dsn'])) {
                db::migrateDatabase((string) $environment->environment->datasource->database['dsn'], (string) $environment->environment->database["dsn"], $session);
            }
            if (isset($environment->environment->datasource->storage['dsn'])) {
                db::migrateDatabase((string) $environment->environment->datasource->storage['dsn'], (string) $environment->environment->storage["dsn"], $session, "scope in ( 'image', 'binaryfile' )");
            }
        }
        $storagepath = $environment->getStoragePath();
        $sourcestoragepath = $environment->getSourceStoragePath();
        
        $excludesRsync = '';
        $excludes = array(
            "cache/**",
            "autoload/**"
        );
        foreach ($excludes as $exclude) {
            $excludesRsync .= ' --exclude=' . escapeshellarg($exclude) . ' ';
        }
        if ($storagepath and $sourcestoragepath and $copydata) {
            if (! is_dir($storagepath)) {
                ClusterTools::mkdir($storagepath, CloudSDK::USER, 0777);
            }
            $command = 'rsync -avztr --no-p --no-t --no-super --no-o --no-g --delete-excluded ';
            if ( !isset( $connection['pass'] ) ){
                $command .= '--rsh="ssh -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -i ~/.ssh/id_rsa -p 22" ';
            }else{
                $command .= '--rsh="/usr/bin/sshpass -p '.$connection['pass'].' ssh -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -l '.$connection['user'].'"';
            }
            $command .= $excludesRsync . ' ' . "{$connection['user']}@{$connection['host']}:{$sourcestoragepath}/ {$storagepath}/";
            $environment->run( $command, array(), $environment->dir );
        }
        if (file_exists($environment->dir . "/" . "bin/php/ezcache.php")) {
            $environment->run("php bin/php/ezcache.php --clear-all", array(), $environment->dir );
        } elseif (file_exists($environment->dir . "/" . "ezpublish/console")) {
            $environment->run("php ezpublish/console --env=prod cache:clear", array(), $environment->dir );
            $environment->run("php ezpublish/console --env=dev cache:clear", array(), $environment->dir );
        }
    }

    public function getInstances()
    {
        $instances = array();
        $result = self::$config->xpath("/aws/cluster[ @lb = '" . Resources\lb::current() . "' ]/instance");
        
        if (isset($result)) {
            $instances = array();
            foreach ($result as $node) {
                $instance = instance::byName((string) $node['name']);
                if ($instance->describe()->instanceState->name == 'running') {
                    $instances[] = $instance;
                }
            }
        }
        return $instances;
    }

    public function sync()
    {
        foreach (self::getInstances() as $instance) {
            if ($instance->id != $this->id) {
                echo "rsync root@" . $instance->ip() . ":/tmp/test.txt /tmp/text.txt\n";
                system();
            }
        }
    }

    public static function getSolrMasterHost()
    {
        return false;
    }

    public function setupHTTP()
    {
        $xp = "/aws/cluster/environment";
        $result = self::$config->xpath($xp);
        foreach ($result as $env) {
            $environment = new environment((string)$env["name"]);
            $vhosts[] = $environment->getVHost();
            $environment->createHTTPVariablesFile();
        }
        $t = new ezcTemplate();
        $t->configuration = CloudSDK::$ezcTemplateConfiguration;
        $t->send->vhosts = $vhosts;
        
        // Process the template and print it.
        if (! is_dir(dirname(self::HTTP_CONFIG_FILE))) {
            mkdir(dirname(self::HTTP_CONFIG_FILE), 0755, true);
        }
        file_put_contents(self::HTTP_CONFIG_FILE, $t->process('vhost.ezt'));
        if (is_link("/var/www/html/index.php")) {
            unlink("/var/www/html/index.php");
        }
        symlink("/usr/share/ezcluster/src/probe/index.php", "/var/www/html/index.php");
    }

    public function setupMounts()
    {
        $xp = "/aws/cluster/environment";
        ClusterTools::mkdir("/nfs", CloudSDK::USER, 0777);
        $result = self::$config->xpath($xp);
        file_put_contents("/etc/auto.master", "/nfs    /etc/auto.ezcluster\n
+auto.master\n");
        chmod("/etc/auto.master", 0755);
        chown("/etc/auto.master", CloudSDK::USER);
        chgrp("/etc/auto.master", CloudSDK::GROUP);
        $mounts = array();
        if (is_array($result)) {
            foreach ($result as $environment) {
                $name = (string) $environment['name'];
                if (isset($environment->storage['mount'])) {
                    $mount = (string) $environment->storage['mount'];
                    if (strpos($mount, "nfs://") === 0) {
                        ClusterTools::mkdir("/nfs/{$name}", CloudSDK::USER, 0777);
                        $parts = parse_url($mount);
                        system("mount -t nfs4 -o rw {$parts['host']}:{$parts['path']} /nfs/{$name}");
                        $mounts[] = "{$name} -fstype=nfs,rw {$parts['host']}:{$parts['path']}";
                    }
                }
            }
        }
        // idn`t get it working
        // ile_put_contents( "/etc/auto.ezcluster", join( $mounts, "\n" ) );
        // hmod( "/etc/auto.ezcluster", 0755 );
        // hown( "/etc/auto.ezcluster", CloudSDK::USER );
        // hgrp( "/etc/auto.ezcluster", CloudSDK::USER );
        // ystem("/etc/init.d/autofs start");
    }

    public function setupCrons()
    {
        if (! self::$config) {
            return false;
        }

        $crondata = "1 * * * * rm -Rf /var/www/sites/*/ezpublish/cache/dev/profiler/\n";

        $xp = "/aws/cluster/instance[role = 'admin' and @name='" . $this->name() . "']";
        $instance = self::$config->xpath($xp);

        $pathprefix = "/var/log/ezcluster";
        ClusterTools::mkdir( self::LOG_DIR, "root", 0777);

        if (is_array($instance) and count($instance) > 0) {
            $xp = "/aws/cluster/environment[cron]";
            $environments = self::$config->xpath($xp);
            $environmentCrondata = "";

            foreach ($environments as $env) {
                $path = CloudSDK::SITES_ROOT . '/' . (string) $env['name'];
                $crons = $env->xpath('cron');
                $environmentCrondata .= self::setupCronData($crons, $path);
            }
            $crondata .= $environmentCrondata;
        }

        if (is_array($instance) and count($instance) > 0) {
            $xp = "/aws/cluster/instance[@name='" . $this->name() . "']/cron";
            $crons = self::$config->xpath($xp);
            $crondata .= self::setupCronData($crons);
        }

        if (isset($crondata)) {
            echo "Setup crontab " . self::CRONTAB_FILE . " for " . CloudSDK::USER . "\n";
            $vars = "PATH=" . CloudSDK::path() . "\n\n";
            file_put_contents(self::CRONTAB_FILE, $vars . $crondata);
            $cmd = "crontab -u " . CloudSDK::USER . " " . self::CRONTAB_FILE;
            system($cmd);
            unlink(self::CRONTAB_FILE);
        }
    }
    private function setupCronData($crons, $path = false)
    {
        $crondata = "";
        foreach ($crons as $cron) {
            if ((string) $cron['timing'])
            {
                $log = self::getLogfileName($cron);

                if ((string) $cron['group'] and $path !== false) {
                    $memory_limit = (isset($cron['memory_limit'])) ? (string) $cron['memory_limit'] : self::CRON_DEFAULT_MEMORY_LIMIT;
                    $crondata .= (string) $cron['timing'] . " cd $path && php -d memory_limit=$memory_limit ezpublish/console ezpublish:legacy:script runcronjobs.php " . (string) $cron['group'] . " >> $log 2>&1\n";
                }
                elseif ((string) $cron['cmd'] and $path !== false) {
                    $crondata .= (string) $cron['timing'] . " cd $path && " . (string) $cron['cmd'] . " >> $log 2>&1\n";
                }
                elseif ((string) $cron['cmd']) {
                    $crondata .= (string) $cron['timing'] . " cd " . CloudSDK::SITES_ROOT . " && " . (string) $cron['cmd'] . " >> $log 2>&1\n";
                }
            }
            else
            {
                throw new \Exception("At least one cron is missing an timing attribute!");
            }
        }
        return $crondata;
    }
    
    private function getLogfileName($cron)
    {
        if((string) $cron['name'])
        {
            $log = self::LOG_DIR . '/' . (string) $cron['name'] . ".cron.log";
        }
        elseif ((string) $cron['group'])
        {
            $log = self::LOG_DIR . '/' . $cron['group'] . ".cron.log";
        }
        elseif ((string) $cron['cmd'])
        {
            $log = self::LOG_DIR . '/' . crc32($cron['cmd']) . ".cron.log";
        }
        return $log;
    }

    public function startServices()
    {
        $this->setupMounts();
        $dir = "/mnt/storage";
        if (! file_exists($dir)) {
            mkdir($dir, 0777, true);
            chown($dir, "root");
            chgrp($dir, "root");
        }
        $this->setupDatabase();
        $this->setupCrons();
        $this->setupHTTP();
    }

    public function stopServices()
    {
        system('crontab -u ec2-user -r');
        system('umount -f /mnt/nas');
        system('systemctl stop autofs');
    }
}
