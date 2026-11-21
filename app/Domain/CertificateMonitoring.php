<?php
declare(strict_types=1);

namespace Pjas\Domain;

use Pjas\Agent\{AgentClient, AgentConnectionException, AgentException};

final class CertificateMonitoring
{
    public static function check(array $site, bool $notify): void
    {
        if (empty($site['tls'])) return;

        $id = (int)$site['id'];
        try {
            $certificate = AgentClient::call('ops.certificate', ['service'=>Deployer::spec($site)], 'monitor');
            $ok = $certificate['valid'] && $certificate['days'] >= 30;
            $detail = '残り'.$certificate['days'].'日'.(!$certificate['matches'] ? '・公開アドレスと不一致' : '');
        } catch (AgentConnectionException $error) {
            throw $error;
        } catch (AgentException) {
            $ok = false;
            $detail = '証明書を読み取れません';
        }
        Alerting::check('certificate:'.$id, $id, $ok, $site['name'].' の証明書: '.$detail, $notify);
    }

    public static function renew(array $site, bool $notify): void
    {
        $id = (int)$site['id'];
        try {
            $result = AgentClient::call('tls.renew', ['service'=>$site['name']], 'monitor');
        } catch (AgentConnectionException $error) {
            throw $error;
        } catch (AgentException $error) {
            Alerting::check('certificate-renew:'.$id, $id, false, $site['name'].' の証明書更新に失敗しました: '.$error->getMessage(), $notify);
            return;
        }
        if (!empty($result['verified'])) {
            Alerting::check('certificate-renew:'.$id, $id, true, $site['name'].' の証明書更新を確認しました', $notify);
        }
    }
}
