#!/usr/bin/python3
"""Read data as the production account; the parent owns stdout's destination."""
import os,sys,stat,shutil,sqlite3,tempfile,urllib.parse
try:
    path=sys.argv[1]
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    with os.fdopen(fd,'rb') as source:
        if not stat.S_ISREG(os.fstat(source.fileno()).st_mode):raise ValueError()
        sqlite=source.read(16)==b'SQLite format 3\0';source.seek(0)
        if not sqlite:shutil.copyfileobj(source,sys.stdout.buffer,1024*1024)
    if sqlite:
        with tempfile.TemporaryDirectory(prefix='pjas-snapshot-') as work:
            snapshot=os.path.join(work,'data.sqlite')
            src=sqlite3.connect('file:'+urllib.parse.quote(path,safe='/')+'?mode=ro',uri=True,timeout=10)
            dst=sqlite3.connect(snapshot)
            try:src.backup(dst)
            finally:dst.close();src.close()
            with open(snapshot,'rb') as source:shutil.copyfileobj(source,sys.stdout.buffer,1024*1024)
except Exception:
    print('共有データを実行ユーザーの権限で読み取れません。保存先と権限を確認してください。',file=sys.stderr);sys.exit(1)
