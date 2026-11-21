<?php
declare(strict_types=1);

namespace Pjas\Http\Controllers;

use Pjas\Domain\{SecurityGateway, ServiceRepo};
use Pjas\Http\{Auth, View};
use Pjas\Support\{Audit, Config};

final class SecurityIntegrationController
{
    private const URL = '/server/security-events';

    public function index(): void
    {
        Auth::require('owner');
        $origin = (string)Config::get('pjas_security_origin', '');
        $session = $_SESSION['pjas_security'][$origin] ?? [];
        $data = ['title'=>'pjas-security', 'configured'=>$origin!=='', 'connected'=>(bool)$session,
            'events'=>[], 'defense'=>[], 'error'=>'', 'filters'=>[], 'next'=>null];
        if ($session && $origin !== '') {
            try {
                $api = new SecurityGateway($origin);
                $domains = [];
                $sites = [];
                foreach (ServiceRepo::all() as $site) {
                    $domain = strtolower(rtrim($site['domain'], '.'));
                    $domains[$domain] = array_key_exists($domain,$domains) ? null : $site['name'];
                    $sites[$site['name']] = $domain;
                }
                $filters = ['query'=>mb_substr(self::field($_GET,'query'),0,100),
                    'category'=>mb_substr(self::field($_GET,'category'),0,32),
                    'ip'=>substr(self::field($_GET,'ip'),0,64),
                    'severity'=>substr(self::field($_GET,'severity'),0,16),
                    'site'=>self::field($_GET,'site')];
                $site = $filters['site'];
                $query = $filters;
                unset($query['site']);
                if ($site !== '') {
                    if (!isset($sites[$site]) || ($domains[$sites[$site]] ?? null) !== $site) {
                        throw new \InvalidArgumentException('公開アドレスが一意に登録されたサイトを選択してください');
                    }
                    // The upstream can search the rendered vhost title, but has no site field.
                    $query['query'] = mb_substr('（'.$sites[$site],0,100);
                }
                $query['cursor'] = substr(self::field($_GET,'cursor'),0,512);
                $result = $api->request('events/search?'.http_build_query($query), null, $session);
                foreach ($result['rows'] ?? [] as $event) {
                    $event['site'] = SecurityGateway::siteForEvent($event, $domains);
                    if ($site !== '' && $event['site'] !== $site) continue;
                    if ($site !== '' && $filters['query'] !== '' && mb_stripos((string)$event['title'], $filters['query']) === false) continue;
                    $data['events'][] = $event;
                }
                $data['filters'] = $filters;
                $data['sites'] = $sites;
                $data['next'] = $result['next_cursor'] ?? null;
                $data['defense'] = $api->request('defense', null, $session);
            } catch (\InvalidArgumentException|\RuntimeException $error) {
                if ($error->getCode()===401) {
                    unset($_SESSION['pjas_security'][$origin]);
                    $data['connected'] = false;
                }
                $data['error'] = $error->getMessage();
            }
        }
        View::render('security_integration', $data);
    }

    public function action(): void
    {
        Auth::require('owner');
        $origin = (string)Config::get('pjas_security_origin', '');
        $session = $_SESSION['pjas_security'][$origin] ?? [];
        $action = self::field($_POST,'action');
        $target = '';
        $audit = [];
        $outcome = 'failed';
        $kind = 'err';
        try {
            $api = new SecurityGateway($origin);
            if ($action === 'login') {
                $credentials = ['username'=>self::field($_POST,'username'),
                    'password'=>self::field($_POST,'password'), 'code'=>self::field($_POST,'code')];
                if ($credentials['username']==='' || mb_strlen($credentials['username'])>64
                    || $credentials['password']==='' || mb_strlen($credentials['password'])>1024
                    || !preg_match('/\A[0-9]{6}\z/D', $credentials['code'])) {
                    throw new \InvalidArgumentException('ユーザー名、パスワードと認証コードを確認してください');
                }
                $api->request('auth/login', $credentials, $session, $login);
                $_SESSION['pjas_security'][$origin] = $login;
                $message = 'pjas-security に接続しました';
            } elseif ($action === 'logout') {
                unset($_SESSION['pjas_security'][$origin]);
                $api->request('auth/logout', [], $session);
                $message = 'pjas-security との接続を解除しました';
            } else {
                if (!$session) throw new \InvalidArgumentException('先に pjas-security に接続してください');
                if ($action === 'propose') {
                    $operation = self::field($_POST,'operation');
                    $ip = self::field($_POST,'ip');
                    $duration = filter_var(self::field($_POST,'duration'), FILTER_VALIDATE_INT);
                    $reason = trim(self::field($_POST,'reason'));
                    $evidence = self::field($_POST,'evidence_id');
                    if (!in_array($operation,['block','unblock'],true) || !filter_var($ip,FILTER_VALIDATE_IP)
                        || ($operation==='block' && ($duration===false || $duration<60 || $duration>3600))
                        || $reason==='' || mb_strlen($reason)>300 || !preg_match('/\A[A-Za-z0-9_-]{0,80}\z/D',$evidence)) {
                        throw new \InvalidArgumentException('IP、期間、理由、根拠IDを確認してください');
                    }
                    $target = $ip;
                    $audit = ['operation'=>$operation,'duration'=>$operation==='block'?$duration:0,'evidence_id'=>$evidence];
                    $result = $api->request('defense/proposals', ['action'=>$operation,'ip'=>$ip,'reason'=>$reason,
                        'duration'=>$audit['duration'],'evidence_id'=>$evidence], $session);
                    $audit['proposal_id'] = $result['id'] ?? '';
                    $message = '申請を作成しました。内容を確認して承認してください';
                } elseif (in_array($action,['approve','reject'],true)) {
                    $id = self::field($_POST,'proposal');
                    $digest = self::field($_POST,'digest');
                    if (!preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$id)
                        || !preg_match('/\A[a-f0-9]{64}\z/D',$digest)) throw new \InvalidArgumentException('申請を選び直してください');
                    $target = $id;
                    $audit = ['digest'=>$digest];
                    $body = ['digest'=>$digest];
                    if ($action==='approve') {
                        Auth::reauthenticate(self::field($_POST,'ctrl_password'), true);
                        $body += ['password'=>self::field($_POST,'password'), 'code'=>self::field($_POST,'code')];
                        if (!$body['password'] || !preg_match('/\A[0-9]{6}\z/D',$body['code'])) {
                            throw new \InvalidArgumentException('pjas-security のパスワードと認証コードが必要です');
                        }
                    }
                    Audit::log(Auth::actor(),'pjas-security.'.$action.'.request',$target,$audit);
                    $result = $api->request('defense/proposals/'.$id.'/'.$action, $body, $session);
                    $audit['sync'] = $result['sync']['status'] ?? '';
                    $message = $action==='approve'?'承認を記録しました。同期状態を確認してください':'申請を却下しました';
                } else {
                    throw new \InvalidArgumentException('操作を選び直してください');
                }
            }
            $outcome = 'ok';
            $kind = 'ok';
            if (($audit['sync'] ?? '')==='error') {
                $kind = 'err';
                $outcome = 'failed';
                $message = '承認は記録されましたが、防御設定を反映できませんでした。同期状態を確認してください';
            }
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            if ($error->getCode()===401) unset($_SESSION['pjas_security'][$origin]);
            $message = $error->getMessage();
        }
        if (in_array($action,['approve','reject','propose','login','logout'],true)) {
            Audit::log(Auth::actor(),'pjas-security.'.$action,$target,$audit,$outcome);
        }
        View::redirect(self::URL, $message, $kind);
    }

    private static function field(array $input, string $key): string
    {
        return is_string($input[$key] ?? null) ? $input[$key] : '';
    }
}
