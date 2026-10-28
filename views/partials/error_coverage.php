<?php
use Pjas\Http\View;
use Pjas\Shared\SiteErrors;
$coverageLabels = [
    'standard' => ['ctrlの最新ページ', 't-ok'],
    'custom' => ['独自・旧版の指定', 't-ok'],
    'missing' => ['この範囲に指定なし', 't-warn'],
    'scoped' => ['範囲限定・条件付きのみ', 't-warn'],
    'default' => ['Apacheの既定表示', 't-warn'],
];
?>
<div class="card" style="margin-bottom:16px">
  <div class="card-h">エラーページの設定チェック</div>
  <div class="card-b">
    <p class="hint">80・443などのVirtualHostごとに、対象の<?= count(SiteErrors::MESSAGES) ?>コードを行単位で確認します。「指定あり」はページの動作確認を意味しません。Include先・.htaccess・アプリの応答は確認対象外です。</p>
    <div class="f2">
      <?php foreach (['current'=>'現在の設定', 'planned'=>'更新後の設定'] as $stage => $stageLabel): ?>
        <section aria-label="<?= $stageLabel ?>のエラーページ確認">
          <h2><?= $stageLabel ?></h2>
          <?php if (empty($error_coverage[$stage])): ?><p class="muted">確認できるVirtualHostがありません。</p><?php endif; ?>
          <?php foreach ($error_coverage[$stage] as $host): ?>
            <details class="coverage-host">
              <summary><span class="mono"><?= View::e($host['address']) ?></span> · <?= View::e($host['server_name'] ?: 'サイト名の指定なし') ?>
                <span class="tag <?= $host['covered'] === count(SiteErrors::MESSAGES) ? 't-ok' : 't-warn' ?>"><?= $host['covered'] ?>/<?= count(SiteErrors::MESSAGES) ?> 指定あり</span>
              </summary>
              <table>
                <thead><tr><th>コード</th><th>設定の状態</th></tr></thead>
                <tbody>
                  <?php foreach ($host['codes'] as $code => $entry): [$label, $class] = $coverageLabels[$entry['status']]; ?>
                    <tr><td><span class="mono"><?= $code ?></span></td><td><span class="tag <?= $class ?>"><?= $label ?></span>
                      <?php if ($entry['scoped'] && $entry['status'] !== 'scoped'): ?><small class="muted">範囲別・条件付きの上書きあり</small><?php endif; ?>
                    </td></tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </details>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>
    </div>
  </div>
</div>
