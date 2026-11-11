#!/usr/bin/env python3
"""Extract an immutable Git archive into an empty, root-owned release; no links/devices."""
import os, pathlib, sys, tarfile
archive, target = sys.argv[1:]
root = pathlib.Path(target)
if root.is_symlink() or not root.is_dir() or any(root.iterdir()):
    raise ValueError('release must be an empty real directory')
with tarfile.open(archive, 'r:') as tar:
    members = tar.getmembers()
    if len(members) > 50000 or sum(m.size for m in members) > 1024**3:
        raise ValueError('archive limits exceeded')
    seen = set()
    for m in members:
        p = pathlib.PurePosixPath(m.name)
        if p.is_absolute() or '..' in p.parts or not p.parts or any(x in ('.git', 'shared', 'releases', 'current') for x in p.parts[:1]):
            raise ValueError('invalid archive path')
        if m.name in seen or not (m.isfile() or m.isdir()):
            raise ValueError('duplicate or non-regular archive entry')
        seen.add(m.name)
    for m in members:
        dest = root.joinpath(*pathlib.PurePosixPath(m.name).parts)
        if m.isdir():
            dest.mkdir(parents=True, exist_ok=True, mode=0o755)
        else:
            dest.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
            with dest.open('xb') as out, tar.extractfile(m) as inp:
                import shutil
                shutil.copyfileobj(inp, out)
            dest.chmod(0o755 if m.mode & 0o111 else 0o644)
