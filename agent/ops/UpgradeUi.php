<?php
declare(strict_types=1);
namespace Pjas\Agentd\Ops;
use Pjas\Agentd\{Spec,OpException,Proc};
use Pjas\Shared\PhpRuntime;

/** Web can resume a prepared transaction, never supply commands, paths or DB payloads. */
final class UpgradeUi
{
    public function __construct(private array $cfg) {}
    public function activate(array $p): array
    {
        $name=Spec::name($p['service']??null);$id=$p['id']??'';
        if(!is_string($id)||!preg_match('/\A[a-f0-9]{24}\z/D',$id)||($p['confirm']??false)!==true)throw new OpException('移行IDと公開再開の確認が必要です');
        if((new \Pjas\Agentd\ControlStore($this->cfg))->read('retired-'.$name))throw new OpException('管理を終了したサイトは再開できません');
        $overview=(new SiteOverview($this->cfg))->listing(['sites'=>[$name]])['sites'][$name];$j=$overview['upgrade']??null;
        if(!$j||$j['id']!==$id||$j['site']!==$name||$j['phase']!=='prepared'||empty($j['paused']))throw new OpException('このサイトの停止中・準備済みの移行だけを再開できます');
        $result=Proc::run([PhpRuntime::cli('php'.PhpRuntime::controlVersion().'-fpm'),dirname(__DIR__,2).'/bin/pjas-site-upgrade','activate',$id,'--confirm-activation'],null,600);
        if($result['code']!==0)throw new OpException('公開前検査または再開に失敗しました。停止状態と移行結果を実行環境で確認してください');
        return ['id'=>$id,'site'=>$name,'phase'=>'active'];
    }
}
