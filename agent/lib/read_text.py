#!/usr/bin/python3
import os,sys,stat
try:
    fd=os.open(sys.argv[1],os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    with os.fdopen(fd,'rb') as src:
        info=os.fstat(src.fileno())
        if not stat.S_ISREG(info.st_mode):raise ValueError()
        size=int(sys.argv[2])
        if len(sys.argv)>3:src.seek(max(0,info.st_size-size))
        elif info.st_size>size:raise ValueError()
        sys.stdout.buffer.write(src.read(size))
except Exception:
    print('ファイルを実行ユーザーの権限で読み取れません。',file=sys.stderr);sys.exit(1)
