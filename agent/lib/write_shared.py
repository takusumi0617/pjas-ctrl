#!/usr/bin/python3
"""Runs as the production account, never root. Input content arrives on inherited stdin."""
import os,sys,tempfile
try:
    root=os.path.realpath(sys.argv[1]);relative=sys.argv[2]
    path=os.path.join(root,relative);parent=os.path.realpath(os.path.dirname(path))
    if not parent.startswith(root+os.sep) or os.path.islink(path):raise ValueError()
    data=sys.stdin.buffer.read(256*1024+1)
    if len(data)>256*1024:raise ValueError()
    fd,tmp=tempfile.mkstemp(prefix='.pjas-write-',dir=parent)
    try:
        with os.fdopen(fd,'wb') as out:os.fchmod(out.fileno(),0o640);out.write(data)
        os.replace(tmp,path)
    finally:
        if os.path.lexists(tmp):os.unlink(tmp)
except Exception:
    print('共有ファイルを安全に書き込めません。保存先と権限を確認してください。',file=sys.stderr);sys.exit(1)
