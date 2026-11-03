#!/usr/bin/python3
"""Extract only ordinary files/directories into a private, empty directory."""
import os, stat, sys, tarfile, zipfile
from pathlib import PurePosixPath

def extract(source, target):
    total = count = 0
    seen = set()
    def entry(name, size, directory, stream):
        nonlocal total, count
        if '\\' in name or any(ord(c)<32 for c in name) or name.startswith('/') or '..' in name.split('/'):
            raise ValueError('unsafe archive path')
        parts = PurePosixPath(name).parts
        if not parts: return
        count += 1; total += size
        if count > 100000 or size < 0 or total > 4*1024**3: raise ValueError('archive limit exceeded')
        path = os.path.join(target, *parts)
        if directory:
            os.makedirs(path, mode=0o755, exist_ok=True)
            if not os.path.isdir(path): raise ValueError('conflicting archive entries')
            return
        if path in seen: raise ValueError('duplicate archive entry')
        seen.add(path)
        os.makedirs(os.path.dirname(path), mode=0o755, exist_ok=True)
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644)
        with os.fdopen(fd,'wb') as out:
            left = size
            while left:
                data = stream.read(min(left,1024*1024))
                if not data: raise ValueError('truncated archive')
                out.write(data); left -= len(data)
    if zipfile.is_zipfile(source):
        with zipfile.ZipFile(source) as archive:
            for item in archive.infolist():
                mode = item.external_attr >> 16
                if stat.S_IFMT(mode) not in (0, stat.S_IFREG, stat.S_IFDIR): raise ValueError('archive links and special files are forbidden')
                with archive.open(item) as stream: entry(item.filename,item.file_size,item.is_dir(),stream)
    else:
        with tarfile.open(source,'r:gz') as archive:
            for item in archive:
                if not (item.isfile() or item.isdir()): raise ValueError('archive links and special files are forbidden')
                stream = archive.extractfile(item) if item.isfile() else None
                try: entry(item.name,item.size,item.isdir(),stream)
                finally:
                    if stream: stream.close()
if __name__=='__main__':
    try: extract(sys.argv[1],sys.argv[2])
    except Exception:
        print('圧縮ファイルを安全に展開できません。リンク・特殊ファイル・重複パス・容量上限を確認してください。',file=sys.stderr)
        sys.exit(1)
