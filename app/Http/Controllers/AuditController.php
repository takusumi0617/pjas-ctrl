<?php
declare(strict_types=1);

namespace Pjas\Http\Controllers;

use Pjas\Http\View;
use Pjas\Support\Db;

final class AuditController
{
    public function index(): void
    {
        $q      = trim((string) ($_GET['q'] ?? ''));
        $action = trim((string) ($_GET['action'] ?? ''));

        $sql  = 'SELECT * FROM audit_log WHERE 1=1';
        $args = [];
        if ($q !== '') {
            $sql .= ' AND (actor LIKE ? OR target LIKE ?)';
            $args[] = "%{$q}%";
            $args[] = "%{$q}%";
        }
        if ($action !== '') {
            $sql .= ' AND action LIKE ?';
            $args[] = "{$action}%";
        }
        if(!\Pjas\Http\Auth::can('owner')){
            $names=array_column(\Pjas\Domain\ServiceRepo::all(),'name');
            $sql.=' AND (actor=?'.($names?' OR target IN ('.implode(',',array_fill(0,count($names),'?')).')':'').')';$args[]=\Pjas\Http\Auth::actor();$args=array_merge($args,$names);
        }
        $sql .= ' ORDER BY id DESC LIMIT 200';

        View::render('audit', [
            'title'  => '操作の記録',
            'rows'   => Db::all($sql, $args),
            'q'      => $q,
            'action' => $action,
        ]);
    }
}
