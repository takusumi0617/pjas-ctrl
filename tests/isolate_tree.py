#!/usr/bin/env python3
import importlib.util
import os
from pathlib import Path
import stat
import tempfile
import sys
sys.dont_write_bytecode = True

spec = importlib.util.spec_from_file_location('isolate_tree', Path(__file__).parents[1] / 'agent/lib/isolate_tree.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    base = root / 'site'
    public = base / 'releases/20261116-010101/public'
    public.mkdir(parents=True)
    shared = base / 'shared'
    (shared / 'uploads').mkdir(parents=True)
    (shared / 'uploads/a.txt').write_text('public upload')
    (shared / '.env').write_text('private')
    (public / 'index.php').write_text('<?php echo "ok";')
    (public / 'uploads').symlink_to(shared / 'uploads', target_is_directory=True)
    outside = root / 'outside'
    outside.write_text('unchanged')
    outside.chmod(0o600)
    (public / 'escape').symlink_to(outside)
    m.protect(str(base), os.getuid(), os.getgid(), os.getuid(), 'public')
    assert stat.S_IMODE(outside.stat().st_mode) == 0o600
    assert outside.read_text() == 'unchanged'
    assert stat.S_IMODE((shared / '.env').stat().st_mode) == 0o600
    assert stat.S_IMODE((public / 'index.php').stat().st_mode) & 7 == 0
    assert f'user:{os.getuid()}:r--' in __import__('subprocess').check_output(['getfacl', '-cnp', str(shared / 'uploads/a.txt')], text=True)
    assert f'user:{os.getuid()}' not in __import__('subprocess').check_output(['getfacl', '-cnp', str(shared / '.env')], text=True)
    print('ok descriptor traversal preserves external symlinks and grants only public shared ACLs')
    os.link(outside, public / 'hardlink')
    before = stat.S_IMODE(base.stat().st_mode)
    findings = m.check_tree(str(base))
    assert any(x['kind'] == 'hardlink' and x['path'].endswith('/hardlink') for x in findings)
    assert stat.S_IMODE(base.stat().st_mode) == before
    try:
        m.protect(str(base), os.getuid(), os.getgid(), os.getuid(), 'public')
        raise AssertionError('hardlink accepted')
    except ValueError:
        pass
    assert stat.S_IMODE(outside.stat().st_mode) == 0o600
    print('ok hardlink rejection never changes the external file')

    fifo = shared / 'pipe'
    os.mkfifo(fifo)
    assert any(x['kind'] == 'special' and x['path'] == 'shared/pipe' for x in m.check_tree(str(base)))
    print('ok read-only preflight reports hardlink and FIFO paths without opening or mutating them')

with tempfile.TemporaryDirectory() as tmp:
    base = Path(tmp) / 'site'
    one = base / 'releases/20261028-021040/node_modules/esbuild/bin/esbuild'
    two = base / 'releases/20261028-021040/node_modules/@esbuild/linux-arm64/bin/esbuild'
    one.parent.mkdir(parents=True)
    two.parent.mkdir(parents=True)
    one.write_bytes(b'executable payload')
    one.chmod(0o755)
    os.link(one, two)
    before = one.stat()
    result = m.split_internal_links(str(base))
    assert len(result) == 2 and one.stat().st_ino != two.stat().st_ino
    for p in (one, two):
        assert p.read_bytes() == b'executable payload' and p.stat().st_nlink == 1
        assert stat.S_IMODE(p.stat().st_mode) == 0o755
        assert p.stat().st_mtime_ns == before.st_mtime_ns
        assert (p.stat().st_uid, p.stat().st_gid) == (before.st_uid, before.st_gid)
    assert m.split_internal_links(str(base)) == []
    print('ok esbuild internal aliases become independent files with bytes and executable metadata preserved')
    (base / 'shared').mkdir()
    m.protect(str(base), os.getuid(), os.getgid(), os.getuid(), 'public')
    assert stat.S_IMODE(one.stat().st_mode) == 0o750
    print('ok isolation retains executable permission without write permission for the site group')
    outside = Path(tmp) / 'outside'
    os.link(one, outside)
    try:
        m.split_internal_links(str(base))
        raise AssertionError('external hardlink accepted')
    except ValueError:
        pass
    assert one.stat().st_ino == outside.stat().st_ino and one.stat().st_nlink == 2
    print('ok external aliases remain untouched and are refused')
