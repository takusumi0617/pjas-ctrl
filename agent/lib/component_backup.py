#!/usr/bin/env python3
"""Stopped-engine snapshot/restore with no symlink traversal and bounded extraction."""
import json, os, pathlib, stat, sys, tarfile
mode, specfile, archive = sys.argv[1:]
spec = json.loads(pathlib.Path(specfile).read_text())
limit = 100000
max_bytes = 10 * 1024**3

def tree(root):
    global limit, max_bytes
    for current, dirs, files in os.walk(root, followlinks=False):
        for name in dirs + files:
            p = pathlib.Path(current) / name
            st = p.lstat()
            limit -= 1
            max_bytes -= st.st_size
            if limit < 0 or max_bytes < 0 or stat.S_ISLNK(st.st_mode) or not (stat.S_ISDIR(st.st_mode) or stat.S_ISREG(st.st_mode)) or st.st_nlink > 1 and stat.S_ISREG(st.st_mode):
                raise ValueError('unsafe storage entry')
            yield p

if mode == 'snapshot':
    with tarfile.open(archive, 'x:') as tar:
        for key, row in spec.items():
            root = pathlib.Path(row['path'])
            if root.is_symlink():
                raise ValueError('linked storage')
            paths = [root] + list(tree(root)) if root.is_dir() else [root]
            for p in paths:
                name = key + ('/' + str(p.relative_to(root)) if p != root else '')
                tar.add(p, arcname=name, recursive=False)
elif mode in ('validate', 'restore'):
    with tarfile.open(archive, 'r:') as tar:
        members = tar.getmembers()
        if len(members) > limit or sum(m.size for m in members) > max_bytes:
            raise ValueError('snapshot too large')
        seen = set()
        for m in members:
            p = pathlib.PurePosixPath(m.name)
            if p.is_absolute() or '..' in p.parts or not p.parts or str(p) != m.name.rstrip('/') or p.parts[0] not in spec or m.name in seen or not (m.isdir() or m.isfile()):
                raise ValueError('invalid snapshot entry')
            if spec[p.parts[0]].get('file') and len(p.parts) != 1:
                raise ValueError('invalid secret entry')
            row = spec[p.parts[0]]
            if m.uid not in (0, row['uid']) or m.gid not in (0, row['gid']):
                raise ValueError('unexpected snapshot ownership')
            seen.add(m.name)
        by_name = {m.name: m for m in members}
        for m in members:
            parent = str(pathlib.PurePosixPath(m.name).parent)
            if parent != '.' and (parent not in by_name or not by_name[parent].isdir()):
                raise ValueError('invalid snapshot hierarchy')
        if not set(spec).issubset(seen):
            raise ValueError('incomplete snapshot')
        # Validate all destinations before touching any state; processes remain stopped on errors.
        for key, row in spec.items():
            root = pathlib.Path(row['path'])
            if root.is_symlink():
                raise ValueError('linked destination')
            if root.is_dir():
                list(tree(root))
        if mode == 'restore':
            import shutil
            for key, row in spec.items():
                root = pathlib.Path(row['path'])
                if root.is_dir():
                    for child in root.iterdir():
                        if child.is_dir(): shutil.rmtree(child)
                        else: child.unlink()
                else:
                    root.unlink(missing_ok=True)
            for m in members:
                parts = pathlib.PurePosixPath(m.name).parts
                row = spec[parts[0]]
                dest = pathlib.Path(row['path']).joinpath(*parts[1:])
                if m.isdir():
                    dest.mkdir(parents=True, exist_ok=True, mode=0o700)
                else:
                    dest.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                    with dest.open('xb') as out, tar.extractfile(m) as inp:
                        shutil.copyfileobj(inp, out)
                # Preserve owner permissions/ownership, excluding special and shared bits.
                dest.chmod((m.mode & 0o700) if len(parts) > 1 else (0o600 if row.get('file') else 0o700))
                os.chown(dest, m.uid if len(parts) > 1 else row['uid'], m.gid if len(parts) > 1 else row['gid'])
else:
    raise ValueError('unknown operation')
