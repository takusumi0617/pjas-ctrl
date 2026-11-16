<?php
declare(strict_types=1);
require dirname(__DIR__).'/agent/lib/OpException.php';
require dirname(__DIR__).'/agent/lib/Spec.php';
require dirname(__DIR__).'/app/Domain/Hooks.php';
require dirname(__DIR__).'/app/Domain/ScheduledTaskRepo.php';
require dirname(__DIR__).'/app/Domain/PjasManifest.php';
use Pjas\Agentd\Spec;
use Pjas\Domain\Hooks;
$n=0;
function check(bool $ok,string $label): void { global $n; if(!$ok) throw new RuntimeException($label); $n++; echo "PASS $label\n"; }
function rejects(callable $f,string $label): void { try {$f();} catch(Pjas\Agentd\OpException $e) { check(true,$label);return; } throw new RuntimeException($label); }
foreach(['8.1','8.2','8.3','8.4','8.5','8.6'] as $version) {
    $runtime='php'.$version.'-fpm'; $php='/usr/bin/php'.$version;
    check(Spec::runtimeHook(['composer','install','--no-dev','-o','--no-interaction'],$runtime)===[$php,'/usr/bin/composer','install','--no-dev','-o','--no-interaction'],'legacy Composer uses '.$version);
    check(Spec::runtimeHook(['php','artisan','migrate','--force'],$runtime)===[$php,'artisan','migrate','--force'],'legacy Artisan uses '.$version);
    check(Spec::runtimeHook(['npm','run','build'],$runtime)===['npm','run','build'],'npm unchanged for '.$version);
    $explicit=$php.' /usr/bin/composer install --no-dev -o --no-interaction';
    check(Hooks::isAllowed($explicit)&&Spec::runtimeHook(Spec::hook($explicit),$runtime)===explode(' ',$explicit),'explicit Composer allowed in both validators for '.$version);
    check(Hooks::isAllowed($php.' artisan config:cache'),'explicit Artisan accepted by web for '.$version);
    check(Spec::runtimeHook(Spec::hook($php.' artisan config:cache'),$runtime)===[$php,'artisan','config:cache'],'explicit Artisan accepted by agent for '.$version);
}
foreach(['/usr/bin/php8.5 -r evil','/usr/bin/php8.5 /tmp/composer install','/usr/bin/php8.5 artisan migrate --force;id','/usr/bin/php8.5 artisan migrate --force --path=/tmp/evil','/tmp/php8.5 artisan config:cache'] as $bad) {
    check(!Hooks::isAllowed($bad),'web rejects unsafe hook');
    rejects(fn()=>Spec::hook($bad),'agent rejects unsafe hook');
}
rejects(fn()=>Spec::runtimeHook(['/usr/bin/php8.3','artisan','config:cache'],'php8.5-fpm'),'mismatched runtime rejected');
rejects(fn()=>Spec::runtimeHook(['php','artisan','config:cache'],'../../evil'),'invalid runtime rejected');
rejects(fn()=>Spec::runtimeHook(['php','artisan','migrate --force'],'php8.5-fpm'),'noncanonical argument rejected');
$input=['name'=>'example','domain'=>'example.test','runtime'=>'php8.5-fpm','hooks'=>['composer install --no-dev -o --no-interaction','/usr/bin/php8.5 artisan config:cache']];
$once=Spec::service($input); check(Spec::service($once)===$once,'saved hook arrays survive revalidation');
if(isset($argv[1])) {
    $m=Pjas\Domain\PjasManifest::parse(file_get_contents($argv[1]));
    foreach($m['prepare'] as $cmd) Spec::runtimeHook(Spec::hook($cmd),$m['site']['runtime']);
    check(true,'Gamebank manifest accepted by web and agent');
}
check(str_contains(file_get_contents(dirname(__DIR__).'/agent/ops/Deploy.php'),'Spec::runtimeHook($argv, $spec[\'runtime\'])'),'deploy resolves runtime before execution');
echo "$n checks passed.\n";