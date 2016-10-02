<?php

namespace Console;

use \Symfony\Component\Console\Command\Command;
use \Symfony\Component\Console\Input\InputInterface;
use \Symfony\Component\Console\Input\InputArgument;
use \Symfony\Component\Console\Input\InputOption;
use \Symfony\Component\Console\Output\OutputInterface;

class Symlink extends Command
{
    protected function configure()
    {
        $this->setName('site:symlink')->setDescription('Symlinks the site');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeLn("<info>Linking files...</info>");
        $this->symlink();
    }

    public function symlink()
    {
        $target = WWW_ROOT;
        $mapper = new \Installer\Mapper(ANAHITA_ROOT, $target);

        $patterns = array(
          '#^(components|templates|libraries|media)/([^/]+)/.+#' => '\1/\2',
          '#^(cli)/.+#' => 'cli',
          '#^plugins/([^/]+)/([^/]+)/.+#' => 'plugins/\1/\2',
          '#^includes/.+#' => '\1/includes',
          '#^(vendors|migration)/.+#' => '',
          '#^configuration\.php-dist#' => '',
          '#^htaccess.txt#' => '',
        );

        $patterns['#^installation/.+#'] = '';
        $mapper->addCrawlMap('vendor/anahita-platform', $patterns);
        $mapper->addCrawlMap('vendor/nooku', $patterns);
        $mapper->addCrawlMap('src', $patterns);
        $mapper->symlink();
        $mapper->getMap('vendor/anahita-platform/index.php', 'index.php')->copy();
        $mapper->getMap('vendor/anahita-platform/htaccess.txt', '.htaccess')->copy();

        @mkdir($target.'/tmp', 0755);
        @mkdir($target.'/cache', 0755);
        @mkdir($target.'/log', 0755);

        $vendorLink = new \Installer\Map(COMPOSER_VENDOR_DIR, WWW_ROOT.'/vendor');
        $vendorLink->symlink();
    }
}

$config = new Config(WWW_ROOT);

if ($config->isConfigured()) {
	 $console->addCommands(array(new Symlink()));
}

class Create extends Command
{
    protected $_input;
    protected $_output;

    protected function configure()
    {
        $this->setName('site:init')
        ->setDescription('Initializes the site by linking necessary files, setting up the database and creating an admin user')
        ->setDefinition(array(
            new InputOption('database-dump',null, InputOption::VALUE_OPTIONAL,'Use a database dump to initilaize the data'),
            new InputOption('database-name',null, InputOption::VALUE_REQUIRED,'Database name'),
            new InputOption('database-user',null, InputOption::VALUE_REQUIRED,'Database username'),
            new InputOption('database-password',null, InputOption::VALUE_REQUIRED,'Database password'),
            new InputOption('database-host',null, InputOption::VALUE_OPTIONAL,'Database host'),
            new InputOption('database-port',null, InputOption::VALUE_OPTIONAL,'Database port'),
            new InputOption('database-prefix',null, InputOption::VALUE_OPTIONAL,'Database prefix'),
            new InputOption('drop-database',null, InputOption::VALUE_NONE,'Drop existing database. <error>Use this command with care, as it will wipe off all exsiting data</error>'),
            new InputOption('admin-password',null, InputOption::VALUE_OPTIONAL,'The admin password. This is only done for the first time installation'),
            new InputOption('admin-email',null, InputOption::VALUE_OPTIONAL,'The admin email. This is only done for the first time installation')
        ));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->_input = $input; $this->_output = $output;
        $this->_symlink();
        $this->_configure();
    }

    protected function _symlink()
    {
        $this->_output->writeLn("<info>Linking files...</info>");
        $symlink = new Symlink();
        $symlink->symlink();
    }

    protected function _configure()
    {
        $dialog = $this->getHelperSet()->get('dialog');
        $output = $this->_output;
        $input  = $this->_input;

        $prompt = function($key, $text, $default = null, $error = null) use ($dialog, $output, $input)
        {
            $result = $input->getOption($key);

            if (empty($result) && !$input->getOption('no-interaction')) {

                if (!empty($default)) {
                    $text .= '(default: '.$default.') ';
                }

                while(strlen($result = $dialog->ask($output,'<info>'.$text.'</info>', $default)) == 0);

            } elseif (empty($result)) {

                $result = $default;

                if (empty($result)) {
                    $output->writeLn('<error>'.$error.'</error>');
                    exit(1);
                }
            }

            return $result;
        };

        $config = new Config(WWW_ROOT);
        $info = $config->getDatabaseInfo();
        $config->setDatabaseInfo(array(
            'name'     => $prompt('database-name', 'Enter the name of the database? ',@$info['name'],'Please enter the database name'),
            'user'     => $prompt('database-user', 'Enter the database user? ',@$info['user'],'Please enter the database user'),
            'password' => $prompt('database-password', 'Enter the database password? ',@$info['password'],'Please enter the database password'),
            'host'     => $prompt('database-host', 'Enter the database host address? ', '127.0.0.1',@$info['host']),
            'port'     => $prompt('database-port', 'Enter the database port? ','3306',@$info['port']),
            'prefix'   => $prompt('database-prefix', 'Enter a prefix for the tables in the database? ','an_',@$info['prefix'])
        ));

        define('DS', DIRECTORY_SEPARATOR);
        define('_ANEXEC', 1);
        define('ANPATH_BASE', WWW_ROOT);
        define('ANPATH_ROOT', ANPATH_BASE );
        define('ANPATH_SITE', ANPATH_ROOT);
        define('ANPATH_CONFIGURATION', ANPATH_ROOT );
        define('ANPATH_LIBRARIES', ANPATH_ROOT.'/libraries');
        define('ANPATH_PLUGINS', ANPATH_ROOT.'/plugins');
        define('ANPATH_INSTALLATION', ANPATH_ROOT.'/installation');
        define('ANPATH_THEMES', ANPATH_BASE.'/templates');
        define('ANPATH_CACHE', ANPATH_BASE.'/cache' );

        include_once (ANPATH_LIBRARIES . '/anahita-platform/import.php');
        require_once 'Console/Installer/Helper.php';

        $output->writeLn('<info>connecting to database...</info>');

        $errors  = array();
        $database = $config->getDatabaseInfo();

        /*
        $db = \AnInstallationHelper::getDBO(
                  'mysqli',
                  $database['host'].':'.$database['port'],
                  $database['user'],
                  $database['password'],
                  $database['name'],
                  $database['prefix'],
                  false
              );
        */

        $db = KService::get('anahita:database.adapeter.mysqli');

        if ($db instanceof \JException) {
            $output->writeLn('<error>'.$db->toString().'</error>');
            exit(1);
        }

        $db_exists = \AnInstallationHelper::databaseExists($db, $database['name']);
        $dump_file = null;

        if ( $input->getOption('database-dump') ) {
            $dump_file = realpath($input->getOption('database-dump'));
        }

        if ($db_exists && $input->getOption('drop-database')) {

            $output->writeLn('<fg=red>Dropping existing database...</fg=red>');

            \AnInstallationHelper::deleteDatabase(
              $db,
              $database['name'],
              $database['prefix'],
              $errors
            );

            $db_exists = false;
        }

        if (!$db_exists) {
            $output->writeLn('<info>Creating new database...</info>');

            \AnInstallationHelper::createDatabase($db, $database['name'],true);

            $db->select($database['name']);

            $sql_files = $dump_file ? array($dump_file) :
                    array_map(function($file){
                        return $file = ANAHITA_ROOT."/vendor/anahita-platform/installation/sql/$file";
                    }, array("schema.sql","data.sql"));

            $output->writeLn('<info>Populating database...</info>');

            array_walk($sql_files, function($file) use($db) {
                \AnInstallationHelper::populateDatabase($db, $file, $errors);
            });
        }

        $config->secret = bin2hex(openssl_random_pseudo_bytes(32));
        $config->save();

        $output->writeLn("<info>Congratulations you're done.</info>");

        if (!$db_exists && !$dump_file) {
            $output->writeLn("<info>The first person who registers for an account becomes the Super Administrator. Point your browser to http://yoursite/people/signup and create a new account.</info>");
        }
    }
}

$console->addCommands(array(new Create()));

if (!$console->isInitialized()) {
    return;
}

// $console
// ->register('site:update')
// ->setDescription('Provides one command that performs updading all the packages, running migrations and re-linking all the files')
// ->setDefinition(array())
// ->setCode(function (InputInterface $input, OutputInterface $output) use ($console) {

// });
$console
->register('site:configuration')
->setDescription('Provides the ability to set some of the site configuration through command line')
->setDefinition(array(
    new InputOption('enable-debug','',   InputOption::VALUE_NONE, 'Turn on the debug'),
    new InputOption('disable-debug','',   InputOption::VALUE_NONE, 'Turn off the debug'),
    new InputOption('new-secret','',   InputOption::VALUE_NONE, 'Generates a new secret'),
    new InputOption('url-rewrite','',   InputOption::VALUE_REQUIRED, 'Enable or disable url rewrite'),
    new InputOption('set-value','s', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Setting key value pair',array()),
))
->setCode(function (InputInterface $input, OutputInterface $output) use ($console) {
    $config = new Config(WWW_ROOT);

    if (!$config->isConfigured()) {
        $output->writeLn("<error>You need to initialize the site first by typing php anahita.php site:init</error>");
        exit(1);
    }

    $set = function($name) use ($console, $config, $input)
    {
        $args = func_get_args();
        foreach($args as $arg) {
           $value = $input->getOption($arg);
           if ( strlen($value) > 0 ) {
               $arg   = str_replace('-','_',$arg);
               $config->$arg = $value;
           }
        }
    };

    $set('url-rewrite');

    if ($input->getOption('enable-debug')) {
        $config->enableDebug();
    } elseif ( $input->getOption('disable-debug') ) {
        $config->disableDebug();
    }

    if ($input->getOption('new-secret')) {
        $console->loadFramework();
        $config->set('secret', bin2hex(openssl_random_pseudo_bytes(32)));
    }

    if ($input->getOption('set-value')) {
        $values = $input->getOption('set-value');
        foreach ($values as $value) {
            $parts = explode('=',$value);
            $parts = array_map('trim', $parts);
            if ( count($parts) == 2 ) {
                $config->set($parts[0], $parts[1]);
            }
        }
    }

    $config->save();
});
