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
    try:
        m.protect(str(base), os.getuid(), os.getgid(), os.getuid(), 'public')
        raise AssertionError('hardlink accepted')
    except ValueError:
        pass
    assert stat.S_IMODE(outside.stat().st_mode) == 0o600
    print('ok hardlink rejection never changes the external file')
