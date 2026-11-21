<?php
declare(strict_types=1);
namespace Pjas\Domain;
use Pjas\Agent\AgentClient;
use Pjas\Support\Db;
final class ComponentMonitoring
{
    public static function check(array $site,array $policy): void
    {
        $id=(int)$site['id'];
        try {
            AgentClient::call('components.maintain',['service'=>$site['name']],'monitor');
            CertificateMonitoring::renew($site, (bool)$policy['notifications']);
            $s=AgentClient::call('components.status',['service'=>$site['name']],'monitor');
            Alerting::check('components:'.$id,$id,!empty($s['configured']),$site['name'].' の依存環境・権限・秘密情報の状態',$policy['notifications']);
            foreach ($s['components'] as $component=>$row) {
                Alerting::check('component:'.$id.':'.$component,$id,!empty($row['active']),$site['name'].' / '.$component.' の稼働状態',$policy['notifications']);
                Alerting::check('component-secret:'.$id.':'.$component,$id,empty($row['restart_required']),$site['name'].' / '.$component.' の秘密情報更新後の再起動要否',$policy['notifications']);
                foreach ($row['checks']??[] as $probe=>$ok) Alerting::check('probe:'.$id.':'.$component.':'.$probe,$id,$ok,$site['name'].' / '.$component.' / '.$probe,$policy['notifications']);
            }
            if (isset($s['backup_fresh'])) Alerting::check('component-backup:'.$id,$id,$s['backup_fresh'],$site['name'].' の整合性バックアップの鮮度',$policy['notifications']);
            Db::run("UPDATE component_sites SET phase=?,observed_at=datetime('now') WHERE service_id=?",[$s['phase'],$id]);
        } catch (\Throwable) { Alerting::check('components:'.$id,$id,false,$site['name'].' のコンポーネント監視に失敗しました',$policy['notifications']); }
    }
}
