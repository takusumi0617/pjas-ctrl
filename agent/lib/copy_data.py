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
except Exception as error:
    # Never echo SQLite exception text: it can contain SQL or data values.
    category = 'permission' if isinstance(error, PermissionError) else ('sqlite' if isinstance(error, sqlite3.Error) else 'io')
    print('PJAS_DATA_ERROR:' + category, file=sys.stderr)
    sys.exit(1)
