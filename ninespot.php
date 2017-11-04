<?php
namespace Imos\Ninespot;

require_once('vendor/autoload.php');

define('BINARY', $argv[0]);
define('NINESPOT_INSTANCE', 'ninespot');

// Initialize default timezone.
function SetDefaultTimezone() {
  return (getenv('TZ') && date_default_timezone_set(getenv('TZ')) !== FALSE) ||
         (is_link('/etc/localtime') &&
          preg_match('%zoneinfo/(.*)$%', readlink('/etc/localtime'), $match) &&
          date_default_timezone_set($match[1]) !== FALSE) ||
         (file_exists('/etc/localtime') && 
          date_default_timezone_set(file_get_contents('/etc/localtime'))
              !== FALSE) &&
         date_default_timezone_set('UTC');
}

SetDefaultTimezone();

class Util {
  public static function GetMatched($pattern, $string) {
    if (!preg_match($pattern, $string, $match)) {
      throw new \Exception("'$string' does not match '$pattern'");
    }
    return $match[1];
  }

  public static function ParseSize($size) {
    if ($size == 'auto') { return NULL; }
    if (!preg_match('%^(\d{1,15})([kmg]?)b?(?:ytes)?$%i', $size, $match)) {
      return FALSE;
    }
    $base = intval($match[1]);
    switch (strtolower($match[2])) {
      case 'k': return $base * 1024;
      case 'm': return $base * 1024 * 1024;
      case 'g': return $base * 1024 * 1024 * 1024;
      default: return $base;
    }
  }
}

class Flag {
  public static function Get($key) {
    $flag = self::GetSingleton();
    return isset($flag->options[$key]) ? $flag->options[$key] : NULL;
  }

  public static function GetArguments() { return self::GetSingleton()->args; }
  public static function GetCommand() { return self::GetSingleton()->command; }

  function __construct() {
    $parser = new \Console_CommandLine([
        'description' => 'Run a command on an on-demand instance.',
        'version' => '0.0.1',
        'force_posix' => TRUE]);
    $parser->addArgument(
        'args',
        ['multiple' => TRUE,
         'optional' => TRUE,
         'description' => 'Command-line arguments to run on an instance.']);
    $parser->addOption('debug',
        ['short_name' => '-d',
         'long_name' => '--debug',
         'action' => 'StoreTrue',
         'default' => FALSE,
         'description' => 'Debug mode.']);
    $parser->addOption('instance',
        ['short_name' => '-i',
         'long_name' => '--instance',
         'action' => 'StoreString',
         'default' => 'default',
         'description' => 'Instance name to manage.']);
    $parser->addOption('dry_run',
        ['short_name' => '-n',
         'long_name' => '--dry-run',
         'action' => 'StoreTrue',
         'default' => FALSE,
         'description' => 'Dry-run mode.']);
    $build = $parser->addCommand(
        'build',
        ['description' => 'build a disk image.']);
    $build->addOption('force',
        ['short_name' => '-f',
         'long_name' => '--force',
         'action' => 'StoreTrue',
         'default' => FALSE,
         'description' => 'Forcefully build an image.']);
    $build->addOption('disk',
        ['short_name' => '-d',
         'long_name' => '--disk',
         'action' => 'StoreString',
         'default' => 'auto',
         'description' => 'Disk size.']);
    $build->addOption('image',
        ['short_name' => '-i',
         'long_name' => '--image',
         'action' => 'StoreString',
         'default' => 'ubuntu',
         'description' => 'Disk image.']);
    $build->addOption('zone',
        ['short_name' => '-z',
         'long_name' => '--zone',
         'action' => 'StoreString',
         'default' => 'default']);
    $destroy = $parser->addCommand(
        'destroy',
        ['description' => 'Delete an instance and a disk image.']);
    $start = $parser->addCommand(
        'start',
        ['description' => 'Attach a machine.']);
    $start->addOption('cpu',
        ['short_name' => '-c',
         'long_name' => '--cpu',
         'action' => 'StoreInt',
         'default' => 0,
         'description' => 'Number of CPUs.']);
    $start->addOption('memory',
        ['short_name' => '-m',
         'long_name' => '--memory',
         'action' => 'StoreString',
         'default' => '0B',
         'description' => 'Memory size.']);
    $start->addOption('gpu',
        ['short_name' => '-g',
         'long_name' => '--gpu',
         'action' => 'StoreInt',
         'default' => 0,
         'description' => 'Number of GPUs.']);
    $start->addOption('preemptible',
        ['short_name' => '-p',
         'long_name' => '--preemptible',
         'action' => 'StoreTrue',
         'default' => FALSE,
         'description' => 'Request a preemptible machine.']);
    $stop = $parser->addCommand(
        'stop',
        ['description' => 'Detach a machine.']);
    $sleep = $parser->addCommand(
        'sleep',
        ['description' => 'Sleep a machine.']);
    try {
      $result = $parser->parse();
      $this->args = $result->args['args'];
      $this->options = $result->options;
      if ($result->command_name !== FALSE) {
        $this->command = $result->command_name;
        if (isset($result->command->args['args'])) {
          $this->args = array_merge(
              $this->args, $result->command->args['args']);
        }
        $this->options += $result->command->options;
      }
      Log::Debug('Command: ' . json_encode($this->command));
      Log::Debug('Args: ' . json_encode($this->args));
      Log::Debug('Options: ' . json_encode($this->options));
    } catch (\Exception $e) {
      Log::Fatal($e->getMessage());
    }
  }

  protected static function GetSingleton() {
    static $singleton = NULL;
    if (is_null($singleton)) {
      $singleton = new self;
    }
    return $singleton;
  }

  private $command = FALSE;
  private $args = [];
  private $options = [];
}

class Log {
  public static function Debug($message)
      { self::GetSingleton()->Emit(0, $message); }
  public static function Info($message)
      { self::GetSingleton()->Emit(1, $message); }
  public static function Warning($message)
      { self::GetSingleton()->Emit(2, $message); }
  public static function Error($message)
      { self::GetSingleton()->Emit(3, $message); }
  public static function Fatal($message)
      { self::GetSingleton()->Emit(4, $message); }

  protected function Emit($log_level, $message) {
    if ($log_level < $this->log_level) { return; }
    if ($this->log_level == 0) {
      $backtrace = debug_backtrace(0, 10);
      list($micros, $seconds) =
          array_map('floatval', explode(' ', microtime()));
      $message = date('md H:i:s', $seconds) . '.' .
                 sprintf("%06d", floor($micros * 1e6)) . ' ' .
                 getmypid() . ' ' .
                 basename($backtrace[1]['file']) .
                 ':' . $backtrace[1]['line'] . '] ' . $message;
      $message = ['I', 'I', 'W', 'E', 'F'][$log_level] . $message;
    }
    switch ($log_level) {
      case 0: $this->climate->info($message); break;
      case 1: $this->climate->info($message); break;
      case 2: $this->climate->warning($message); break;
      case 3: $this->climate->error($message); break;
      case 4: $this->climate->error($message); exit(1);
    }
  }

  function __construct() {
    $this->climate = new \League\CLImate\CLImate;
  }

  protected static function GetSingleton() {
    static $singleton = NULL;
    if (is_null($singleton)) {
      $singleton = new self;
      if (Flag::GetCommand() === FALSE) {
        $singleton->log_level = 2;
      }
      if (Flag::Get('debug')) {
        $singleton->log_level = 0;
      }
    }
    return $singleton;
  }

  private $climate = NULL;
  private $log_level = 1;
}

class Cache extends \SQLite3 {
  public static function Get($key, $expiration = 3600, $jitter = 0.5) {
    $cache_util = self::GetSingleton();
    $stmt = $cache_util->prepare(
        'SELECT * FROM cache WHERE cache_id = :cache_id');
    $stmt->bindValue(':cache_id', $key, SQLITE3_TEXT);
    $result = $stmt->execute()->fetchArray();
    if ($result === FALSE || $result['cache_expiration'] < time() ||
        $result['cache_created'] +
        rand(ceil($expiration * (1 - $jitter)), $expiration) < time()) {
      return NULL;
    }
    return json_decode($result['cache_data'], TRUE);
  }

  public static function Set($key, $value, $expiration = 24 * 60 * 60) {
    $cache_util = self::GetSingleton();
    if (is_null($value)) {
      $stmt = $cache_util->prepare(
          'DELETE FROM cache WHERE cache_id = :cache_id');
      $stmt->bindValue(':cache_id', $key, SQLITE3_TEXT);
      $stmt->execute();
    } else {
      $stmt = $cache_util->prepare('
          REPLACE INTO cache(
              cache_id, cache_data, cache_expiration, cache_created)
          VALUES(:cache_id, :cache_data, :cache_expiration, :cache_created)');
      $stmt->bindValue(':cache_id', $key, SQLITE3_TEXT);
      $stmt->bindValue(':cache_data', json_encode($value), SQLITE3_BLOB);
      $stmt->bindValue(
          ':cache_expiration', time() + $expiration, SQLITE3_INTEGER);
      $stmt->bindValue(':cache_created', time(), SQLITE3_INTEGER);
      $stmt->execute();
    }
  }

  function __construct() {
    $path = getenv('HOME') . '/.cache/ninespot.db';
    @mkdir(dirname($path), 0755, TRUE);
    $this->open($path);
    $this->exec('CREATE TABLE IF NOT EXISTS cache(
                     cache_id TEXT PRIMARY KEY,
                     cache_data BLOB,
                     cache_expiration INTEGER,
                     cache_created INTEGER)');
    $this->exec('CREATE INDEX IF NOT EXISTS
                     cache_expiration ON cache(cache_expiration)');
    $stmt = $this->prepare(
        'DELETE FROM cache WHERE cache_expiration < :current_time');
    $stmt->bindValue(':current_time', time(), SQLITE3_INTEGER);
    $stmt->execute();
  }

  protected static function GetSingleton() {
    static $singleton = NULL;
    if (is_null($singleton)) $singleton = new self;
    return $singleton;
  }
}

class Gcloud {
  public static function Execute($command, $dry_run = FALSE) {
    if (is_array($command)) {
      $command = implode(' ', array_map('escapeshellarg', $command));
    }
    $return = -1;
    if ($dry_run) {
      echo "gcloud $command\n";
      return NULL;
    }
    Log::Debug("Executing gcloud command: gcloud $command");
    exec("gcloud --format=json $command", $output, $return);
    if ($return != 0) {
      throw new \Exception("Failed to execute command: $command\n");
    }
    if ($output == '') {
      return NULL;
    }
    return json_decode(implode("\n", $output), TRUE);
  }

  public static function ListImages() {
    $result = [];
    foreach (self::ListImagesInternal() as $image) {
      $result[$image['name']] = [
          'name' => $image['name'],
          'project' =>
              Util::GetMatched('%projects/([^/]+)%', $image['selfLink']),
          'size' => intval($image['diskSizeGb']) * 1024 * 1024 * 1024];
      if (isset($image['family'])) {
        $result[$image['family']] = $result[$image['name']];
      }
    }
    ksort($result);
    $versions = [];
    foreach ($result as $name => $value) {
      if (preg_match('%^(\w+)-(\d+)(|-lts|-stable)$%',
                     $name, $match)) {
        $version = intval($match[2]);
        if (strlen($match[3]) > 0) { $version += 1000000; }
        if (isset($versions[$match[1]]) && $versions[$match[1]] > $version) {
          continue;
        }
        $result[$match[1]] = $value;
        $versions[$match[1]] = $version;
      }
    }
    ksort($result);
    return $result;
  }

  public static function ListZones() {
    $result = [];
    foreach (self::ListRegionsInternal() as $region) {
      foreach ($region['zones'] as $zone_address) {
        $zone = Util::GetMatched('%zones/([^/]+)%', $zone_address);
        $result[$zone] = ['name' => $zone];
      }
    }
    ksort($result);
    foreach ($result as $name => $value) {
      if (preg_match('%^(.*)-a$%', $name, $match)) {
        $result[$match[1]] = $value;
      }
    }
    return $result;
  }

  public static function ListMachineTypes($zone) {
    $result = [];
    foreach (self::ListMachineTypesInternal() as $machine_type) {
      if ($machine_type['zone'] != $zone) continue;
      $result[$machine_type['name']] = [
          'name' => $machine_type['name'],
          'cpu' => $machine_type['isSharedCpu']
                       ? 0 : $machine_type['guestCpus'],
          'memory' => $machine_type['memoryMb'] * 1024 * 1024,
          'score' => $machine_type['guestCpus'] * 0.0333174 +
                     $machine_type['memoryMb'] / 1024 * 0.004446];
    }
    uasort($result, function($a, $b) {
      $d = $a['score'] - $b['score'];
      if ($d > 0) return 1;
      if ($d < 0) return -1;
      return 0;
    });
    return $result;
  }

  public static function GetZone($instance, $expiration = 60 * 60) {
    $zone = Cache::Get('instances/' . $instance . ':zone', $expiration);
    if (is_null($zone)) {
      $disks = self::Execute([
          'compute', 'disks', 'list', '--filter=name~' . $instance]);
      if (count($disks) > 1) {
        Log::Fatal('Instance "' . $instance . '" is duplicated.');
      } else if (count($disks) == 1) {
        $zone = Util::GetMatched('%/zones/([^/]+)%', $disks[0]['zone']);
      }
      self::SetZone($instance, $zone);
    }
    return $zone;
  }

  public static function SetZone($instance, $zone = NULL) {
    self::ClearFeed($instance);
    Cache::Set('instances/' . $instance . ':zone', $zone, 60 * 60);
  }

  public static function Feed($instance) {
    if (Flag::Get('dry_run')) return TRUE;
    $cache_key = 'instances/' . $instance . ':feed';
    if (Cache::Get($cache_key, 120) === TRUE) {
      return TRUE;
    }
    Log::Info('Feeding a machine: ' . $instance);
    exec('gcloud compute --quiet ssh ' . $instance .
             ' --ssh-flag=-q --zone=' . self::GetZone($instance) .
             ' -- sudo touch /var/run/ninespot.lock >/dev/null 2>/dev/null',
         $output, $return);
    if ($return == 0) {
      Cache::Set($cache_key, TRUE, 300);
    } else {
      Log::Info('Failed to feed a machine: ' . $instance);
    }
    return $return == 0;
  }

  public static function ClearFeed($instance) {
    if (Flag::Get('dry_run')) return;
    Cache::Set('instances/' . $instance . ':feed', NULL);
  }

  public static function MachineExists($instance) {
    if (Flag::Get('dry_run')) return TRUE;
    $instances = self::Execute(
        ['compute', 'instances', 'list', '--filter=name~' . $instance]);
    return count($instances) > 0;
  }

// private:
  private static function ListImagesInternal() {
    return self::GetCacheOrExecute(
        'list-images', ['compute', 'images', 'list'], 7 * 24 * 60 * 60);
  }

  private static function ListRegionsInternal() {
    return self::GetCacheOrExecute(
        'list-regions', ['compute', 'regions', 'list'], 7 * 24 * 60 * 60);
  }

  private static function ListMachineTypesInternal() {
    return self::GetCacheOrExecute(
        'list-machine-types', ['compute', 'machine-types', 'list'],
        7 * 24 * 60 * 60);
  }

  private static function GetCacheOrExecute(
      $key, $command, $expiration = 3600) {
    $data = Cache::Get($key, $expiration);
    if ($data === NULL) {
      $data = self::Execute($command);
      Cache::Set($key, $data, $expiration * 2);
    }
    return $data;
  }
}

class NinespotBuild {
  public function __construct() {
    Log::Debug('Build mode.');
    $this->instance = Flag::Get('instance');
    Log::Info('Instance name is ' . $this->instance . '.');
    $this->image = $this->GetImage();
    Log::Info('Image template is ' .
              $this->image['project'] . '/' . $this->image['name'] . '.');
    $this->zone = $this->GetZone();
    Log::Info('Zone is ' . $this->zone['name'] . '.');
    $this->disk_size = $this->GetDiskSize();
    Log::Info('Disk size is ' .
              ceil($this->disk_size / 1024 / 1024 / 1024) . 'GB.');
    Log::Debug('Build parameters: ' . json_encode($this));
  }

  public function Execute() {
    $existing_zone = Gcloud::GetZone($this->instance, 0);
    if (!is_null($existing_zone)) {
      if ($existing_zone == $this->zone['name']) {
        Log::Fatal('Disk already exists: ' . $this->instance);
      } else {
        Log::Fatal(
            'Disk already exists in a different zone: ' . $existing_zone);
      }
    }
    try {
      Gcloud::Execute(
          ['compute', 'disks', 'create', $this->instance,
           '--type=pd-ssd', '--image=' . $this->image['name'],
           '--image-project=' . $this->image['project'],
           '--zone=' . $this->zone['name'],
           '--size=' . ceil($this->disk_size / 1024 / 1024 / 1024) . 'GB'],
          Flag::Get('dry_run'));
    } catch (\Exception $e) {
      Log::Fatal('Failed to build a disk: ' . $this->instance);
    }
    if (!Flag::Get('dry_run')) {
      Gcloud::SetZone($this->instance, $this->zone['name']);
    }
    return 0;
  }

// private:
  private function GetImage() {
    $images = Gcloud::ListImages();
    if (!isset($images[Flag::Get('image')])) {
      Log::Info('image must be one of: ' .
                implode(', ', array_keys($images)));
      Log::Fatal('No such image: ' . Flag::Get('image'));
    }
    return $images[Flag::Get('image')];
  }

  private function GetZone() {
    $zones = Gcloud::ListZones();
    if (!isset($zones[Flag::Get('zone')])) {
      Log::Info('zone must be one of: ' .
                implode(', ', array_keys($zones)));
      Log::Fatal('No such zone: ' . Flag::Get('zone'));
    }
    return $zones[Flag::Get('zone')];
  }

  private function GetDiskSize() {
    $size = Util::ParseSize(Flag::Get('disk'));
    if (is_null($size)) {
      Log::Info(
          'Disk size is automatically determined by the template image size.');
      return $this->image['size'];
    }
    if ($size < $this->image['size']) {
      Log::Fatal('Disk size is smaller than the template image size: ' .
                 $size . ' bytes < ' . $this->image['size'] . ' bytes.');
    }
    return $size;
  }

  public $instance = NULL;
  public $image = NULL;
  public $zone = NULL;
  public $disk_size = 0;
}

class NinespotDestroy {
  public function __construct() {
    Log::Debug('Destroy mode.');
    $this->instance = Flag::Get('instance');
  }

  public function Execute() {
    $zone = Gcloud::GetZone($this->instance);
    try {
      Gcloud::Execute(
          ['compute', 'disks', 'delete', '--quiet',
           $this->instance, '--zone=' . $zone],
          Flag::Get('dry_run'));
    } catch (\Exception $e) {
      Log::Fatal('Failed to destroy a disk: ' . $this->instance);
    }
    if (!Flag::Get('dry_run')) {
      Gcloud::SetZone($this->instance, NULL);
    }
  }

  public $instance = NULL;
}

class NinespotStart {
  public function __construct() {
    Log::Debug('Start mode.');
    $this->instance = Flag::Get('instance');
    Log::Info('Instance name is ' . $this->instance . '.');
    $this->is_preemptible = Flag::Get('is_preemptible');
    Log::Info('Instance is ' .
              ($this->is_preemptible ? 'preemptible' : 'not preemptible') .
              '.');
    $this->cpu = Flag::Get('cpu');
    Log::Info('Minimum number of CPUs is ' . $this->cpu . '.');
    $this->memory = $this->GetMemorySize();
    Log::Info('Minimum size of memory is ' . $this->memory . ' bytes.');
    $this->gpu = Flag::Get('gpu');
    Log::Info('Number of GPUs is ' . $this->gpu . '.');
    $this->zone = Gcloud::GetZone($this->instance);
    Log::Info('Instance\'s zone is ' . $this->zone . '.');
    $this->machine_type = $this->GetMachineType($this->zone);
    Log::Info('Selected machine type is ' . $this->machine_type['name'] .
              ' (# of CPUs: ' . $this->machine_type['cpu'] .
              ', Memory: ' . round($this->machine_type['memory']
                                       / 1024 / 1024 /1024, 3) . 'GB).');
  }

  public function Execute() {
    try {
      $startup_script = <<<EOM
touch /var/run/ninespot.lock
echo 'SHELL=/bin/sh' > /etc/cron.d/ninespot
echo 'PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin' >> /etc/cron.d/ninespot
echo '* * * * * root if find /var/run/crond.pid -mmin +10 | grep /var/run/crond.pid && find /var/run/ninespot.lock -mmin +10 | grep /var/run/ninespot.lock; then shutdown now; fi' >> /etc/cron.d/ninespot
EOM;
      $args = ['compute', 'instances', 'create', $this->instance];
      $args[] = '--zone=' . $this->zone;
      $args[] = '--machine-type=' . $this->machine_type['name'];
      if ($this->is_preemptible) {
        $args[] = '--preemptible';
      }
      $args[] = '--metadata=startup-script=' . $startup_script;
      $args[] = '--disk=name=' . $this->instance .
                ',device-name=' . $this->instance . ',mode=rw,boot=yes';
      if ($this->gpu > 0) {
        $args[] = '--accelerator=type=nvidia-tesla-k80,count=' . $this->gpu;
        $args[] = '--maintenance-policy=TERMINATE';
      }
      Gcloud::Execute($args, Flag::Get('dry_run'));
    } catch (\Exception $e) {
      Log::Fatal('Failed to create a machine: ' . $this->instance);
    }
    if (!Flag::Get('dry_run')) {
      Gcloud::SetZone($this->instance, NULL);
    }
  }

  private function GetMachineType($zone) {
    foreach (Gcloud::ListMachineTypes($zone) as $machine_type) {
      if ($machine_type['cpu'] >= $this->cpu &&
          $machine_type['memory'] >= $this->memory) {
        return $machine_type;
      }
    }
    return NULL;
  }

  private function GetMemorySize() {
    $size = Util::ParseSize(Flag::Get('memory'));
    return is_null($size) ? 0 : $size;
  }

  public $instance = NULL;
  public $cpu = 0;
  public $memory = 0;
  public $gpu = 0;
  public $zone = NULL;
}

class NinespotStop {
  public function __construct() {
    Log::Debug('Stop mode.');
    $this->instance = Flag::Get('instance');
    Log::Info('Instance name is ' . $this->instance . '.');
    $this->zone = Gcloud::GetZone($this->instance);
    Log::Info('Instance\'s zone is ' . $this->zone . '.');
  }

  public function Execute() {
    try {
      Gcloud::ClearFeed($this->instance);
      Gcloud::Execute(
          ['compute', 'instances', 'delete', '--quiet', $this->instance,
           '--zone=' . $this->zone, '--keep-disks=all'],
          Flag::Get('dry_run'));
      Gcloud::ClearFeed($this->instance);
    } catch (\Exception $e) {
      Log::Fatal('Failed to delete a machine: ' . $this->instance);
    }
  }

  public $instance = NULL;
  public $zone = NULL;
}

class NinespotSleep {
  public function __construct() {
    Log::Debug('Sleep mode.');
    $this->instance = Flag::Get('instance');
    Log::Info('Instance name is ' . $this->instance . '.');
    $this->zone = Gcloud::GetZone($this->instance);
    Log::Info('Instance\'s zone is ' . $this->zone . '.');
  }

  public function Execute() {
    try {
      Gcloud::ClearFeed($this->instance);
      Gcloud::Execute(
          ['compute', 'instances', 'stop', '--quiet', $this->instance,
           '--zone=' . $this->zone],
          Flag::Get('dry_run'));
      Gcloud::ClearFeed($this->instance);
    } catch (\Exception $e) {
      Log::Fatal('Failed to sleep a machine: ' . $this->instance);
    }
  }

  public $instance = NULL;
  public $zone = NULL;
}

class NinespotRun {
  public function __construct() {
    Log::Debug('Run mode.');
    $this->instance = Flag::Get('instance');
    Log::Info('Instance name is ' . $this->instance . '.');
    $this->zone = Gcloud::GetZone($this->instance);
    Log::Info('Instance\'s zone is ' . $this->zone . '.');
  }

  public function Execute() {
    if (!Gcloud::Feed($this->instance)) {
      if (!Gcloud::MachineExists($this->instance)) {
        Log::Fatal('No such machine exists: ' . $this->instance);
      }
      $success = FALSE;
      for ($i = 0; $i < 2; $i++) {
        $this->Start();
        if (Gcloud::Feed($this->instance)) {
          $success = TRUE;
          break;
        }
        sleep(10);
      }
      if (!$success) {
        Log::Fatal('Failed to boot a machine: ' . $this->instance);
      }
    }
    $ppid = getmypid();
    $pid = pcntl_fork();
    if ($pid < 0) {
      Log::Fatal('Failed to fork.');
    }
    if ($pid == 0) {
      fclose(STDIN);
      Log::Debug('Child process started.');
      while (posix_kill($ppid, 0)) {
        Gcloud::Feed($this->instance);
        sleep(10);
      }
      exit(0);
    }
    try {
      $command = array_merge(
          ['gcloud', 'compute', 'ssh', $this->instance,
           '--ssh-flag=-q',
           '--zone=' . $this->zone],
          count(Flag::GetArguments()) > 0
              ? ['--', implode(' ', Flag::GetArguments())] : []);
      if (Flag::Get('dry_run')) {
        echo implode(' ', array_map('escapeshellarg', $command)) . "\n";
      } else {
        Log::Debug('Executing: ' .
                   implode(' ', array_map('escapeshellarg', $command)));
        pcntl_exec('/usr/bin/env', $command);
      }
    } catch (\Exception $e) {
      Log::Fatal('Failed to run a command on: ' . $this->instance);
    }
  }

  public function Start() {
    Log::Info('Booting a machine: ' . $this->instance);
    exec('gcloud compute --quiet instances start ' . $this->instance .
             ' --zone=' . $this->zone . ' 2>/dev/null',
         $output, $return);
    return $return == 0;
  }

  public $instance = NULL;
  public $zone = NULL;
}

class Ninespot {
  public static function Main() {
    switch (Flag::GetCommand()) {
      case 'build': return (new NinespotBuild())->Execute();
      case 'destroy': return (new NinespotDestroy())->Execute();
      case 'start': return (new NinespotStart())->Execute();
      case 'stop': return (new NinespotStop())->Execute();
      case 'sleep': return (new NinespotSleep())->Execute();
      default: return (new NinespotRun())->Execute();
    }
  }
}

exit(Ninespot::Main());
