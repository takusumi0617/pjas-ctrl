<?php
declare(strict_types=1);
foreach (['OpException','Spec','Proc','ApacheHistory'] as $f) require dirname(__DIR__).'/agent/lib/'.$f.'.php';
use Pjas\Agentd\ApacheHistory;
use Pjas\Agentd\OpException;
use Pjas\Agentd\Proc;
use Pjas\Shared\SiteErrors;
function check(bool $ok,string $label):void { if(!$ok)throw new RuntimeException($label);echo "ok {$label}\n"; }
function rejects(callable $fn):void {try{$fn();}catch(OpException){return;}throw new RuntimeException('拒否されませんでした');}
$root=sys_get_temp_dir().'/pjas-history-test-'.bin2hex(random_bytes(6));mkdir($root);
try {
 $history=new ApacheHistory(['apache_sites'=>$root]);
 check($history->listing('demo')===[]&&!file_exists($root.'/.pjas-history'),'履歴表示だけではリポジトリを作らない');
 $empty=$history->save('new-site',null,'before','system');
 $state=json_decode(Proc::must(['/usr/bin/git','-C',$root.'/.pjas-history/new-site','show',$empty.':state.json']),true);
 check($state['present']===false,'初回作成前はconf未存在として履歴に記録');
 $old="<VirtualHost *:443>\nServerName demo.example.com\nProxyPass /ws ws://127.0.0.1:8081\n</VirtualHost>\n";
 $before=$history->save('demo',$old,'before','owner@example.com');
 $repo=$root.'/.pjas-history/demo';
 check((fileperms($root.'/.pjas-history')&0777)===0700&&(fileperms($repo)&0777)===0700,'Git履歴を管理者専用の権限で保存');
 check(trim(Proc::must(['/usr/bin/git','-C',$repo,'show',$before.':apache.conf']))===trim($old),'Git履歴から反映前のconfを取り出せる');
 $new=SiteErrors::decorate($old);
 ApacheHistory::assertErrorOnly($old,$new);
 rejects(fn()=>ApacheHistory::assertErrorOnly($old,str_replace('ProxyPass /ws ws://127.0.0.1:8081', '',$new)));
 rejects(fn()=>ApacheHistory::assertErrorOnly($old,str_replace('*:443','*:80',$new)));
 echo "ok 443の削除やProxyPassの削除をGit差分で拒否\n";
 $applied=$history->save('demo',$new,'applied','owner@example.com');
 check($before!==$applied&&count($history->listing('demo'))===2,'反映前と反映後を別コミットで記録');
 check($history->save('demo',$new,'before','other@example.com')===$applied,'同じconfを繰り返し保存しても不要なコミットを作らない');
 // 保存先の作業ファイルだけが壊れた場合にHEADと取り違えない。
 file_put_contents($repo.'/apache.conf',$old);
 file_put_contents($repo.'/state.json',json_encode(['present'=>true]));
 $repaired=$history->save('demo',$old,'before','owner@example.com');
 check($repaired!==$applied&&str_contains(Proc::must(['/usr/bin/git','-C',$repo,'show',$repaired.':apache.conf']),'ProxyPass /ws'),'未コミットの作業ファイルを保存済みと誤認しない');
 $longOld="start\nend\n";
 $longNew="start\n".implode("\n",array_map(static fn($n)=>'new line '.$n,range(1,40)))."\nend\n";
 $diff=ApacheHistory::diff($longOld,$longNew);
 check(count(array_filter($diff,static fn($r)=>$r[0]==='-'))===0&&count(array_filter($diff,static fn($r)=>$r[0]==='+'))===40,'8行を超える追加も既存行の削除と誤表示しない');
 check(ApacheHistory::diff('line',"line\n")!==[],'末尾の改行だけの変更も検出');
 check(ApacheHistory::diff($old,$old)===[],'同じconfの差分は空');
 // 周囲のGit設定やフックからの任意プログラム実行を防ぐ。
 $hook=$repo.'/.git/hooks/pre-commit';mkdir(dirname($hook));
 file_put_contents($hook,"#!/bin/sh\ntouch '".$root."/hook-ran'\nexit 1\n");chmod($hook,0700);
 $history->save('demo',$new."\n# manual edit\n",'before','owner@example.com');
 check(!file_exists($root.'/hook-ran'),'Gitフックを実行しない');
 rejects(fn()=>$history->save('../other',$old,'before','x'));
 chmod($repo,0755);rejects(fn()=>$history->save('demo',$old,'before','x'));chmod($repo,0700);
 echo "ok 保存先の権限不正とパス逸脱を拒否\n";
} finally {
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);
}
