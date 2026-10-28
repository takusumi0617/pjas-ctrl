<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Domain/GitHubRepository.php';
require dirname(__DIR__).'/app/Domain/RegistrationSource.php';
use Pjas\Domain\RegistrationSource;
function check(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); echo 'ok '.$label."\n"; }
$input=['source'=>'git','github_repo'=>'owner/repo','manifest_token'=>'old-token','manifest_json'=>'invalid'];
check(!RegistrationSource::read($input)['manifest']&&RegistrationSource::read($input)['token']==='','GitHubでもチェックしなければ.pjasと以前のtokenを無視');
$input['read_manifest']=1;
check(RegistrationSource::read($input)['manifest']&&RegistrationSource::read($input)['token']==='old-token','.pjasの読み込みを明示的に選択可能');
$input['source']='upload';
$selection=RegistrationSource::read($input);
check($selection['repo']===''&&!$selection['manifest']&&$selection['token']==='','ZIPを選べば残ったGitHubの入力をサーバー側でも無視');
check(RegistrationSource::read([])['type']==='upload','新規登録の初期選択は圧縮ファイル');
try { RegistrationSource::read(['source'=>'unknown']); throw new RuntimeException('不正な取得元を許可'); } catch (InvalidArgumentException) { echo "ok 不正な取得元を拒否\n"; }
