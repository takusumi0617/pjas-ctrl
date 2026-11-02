<?php
declare(strict_types=1);

namespace Pjas\Http\Controllers;

use Pjas\Http\Auth;
use Pjas\Http\View;

final class AuthController
{
    public function showLogin(): void
    {
        if (Auth::user() !== null) {
            View::redirect('/');
        }
        View::render('login', ['error' => null], true);
    }

    public function login(): void
    {
        $email = (string) ($_POST['email'] ?? '');
        $pass  = (string) ($_POST['password'] ?? '');
        if (!Auth::attempt($email, $pass)) {
            usleep(400_000);
            http_response_code(401);
            View::render('login', ['error' => 'メールアドレスまたはパスワードが違います'], true);
            return;
        }
        View::redirect(Auth::pending()?'/login/verify':'/');
    }

    public function showVerify(): void { if(!Auth::pending())View::redirect('/login');View::render('verify_login',['error'=>null],true); }
    public function verify(): void { if(!Auth::completeSecondFactor((string)($_POST['code']??''))){View::render('verify_login',['error'=>'コードを確認してください。認証アプリの新しい6桁コード、または未使用の復旧コードを入力します。'],true);return;}View::redirect('/'); }

    public function logout(): void
    {
        Auth::logout();
        View::redirect('/login');
    }
}
