<?php
declare(strict_types=1);
namespace Pjas\Agentd\Ops;
use Pjas\Agentd\{Spec,Paths,JobLog,OpException};
final class AsyncTasks
{
    public function __construct(private array $cfg,private Paths $paths){}
    public function start(array $params): array
    {
        $kind=(string)($params['kind']??'');$id=(int)($params['job_id']??0);$spec=Spec::service($params['service']??null);
        if($id<=0||!in_array($kind,['backup','data-restore','preview'],true))throw new OpException('作業の種類・番号が不正です');
        $pid=pcntl_fork();if($pid<0)throw new OpException('作業プログラムを起動できません');
        if($pid===0){posix_setsid();$log=new JobLog($id,$this->cfg['job_dir']);$log->set('service',$spec['name']);$log->line(['backup'=>'共有データ・DBのバックアップを始めます','data-restore'=>'バックアップからデータを復元します','preview'=>'本番から分離した試験公開を準備します'][$kind]);
            try{$result=match($kind){'backup'=>(new Backups($this->cfg,$this->paths))->create($params),'data-restore'=>(new Backups($this->cfg,$this->paths))->restore($params),'preview'=>(new Preview($this->cfg,$this->paths))->publish($params)};
                unset($result['password']);$log->set('result',$result);$log->finish('succeeded','作業が完了しました。サイトの運用画面で結果を確認できます');
            }catch(\Throwable $e){$log->finish('failed',$e->getMessage());}exit(0);
        }
        return ['job_id'=>$id,'pid'=>$pid];
    }
}
