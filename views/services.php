<?php use Pjas\Http\{View,Auth}; ?>
<div class="row"><div><h1>サイト一覧</h1><p class="sub">公開状態と実行環境を、情報の鮮度とともに表示します。要対応のサイトを先に並べます。</p></div><span class="spacer"></span>
<?php if(Auth::can('owner')):?><a class="btn" href="/services/import">サーバーから一括登録</a><a class="btn p" href="/services/new">サイトを登録</a><?php endif;?></div>
<div class="card"><?php require __DIR__.'/partials/sites_table.php';?></div>
