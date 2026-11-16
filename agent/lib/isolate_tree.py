#!/usr/bin/env python3
"""Set ownership/modes/ACLs through descriptors; never chmod through site links."""
import os
import re
import stat
import struct
import sys


def acl(fd, owner, group, apache, access):
    entries = [(1, owner, 0xffffffff)]
    if access:
        entries.append((2, access, apache))
    entries.extend([(4, group, 0xffffffff)])
    if access:
        entries.append((16, group | access, 0xffffffff))
    entries.append((32, 0, 0xffffffff))
    for key in ('system.posix_acl_access', 'system.posix_acl_default'):
        try:
            os.removexattr(fd, key)
        except OSError as exc:
            if exc.errno not in (61, 93):
                raise
    if access:
        os.setxattr(fd, 'system.posix_acl_access', struct.pack('<I', 2) + b''.join(struct.pack('<HHI', *e) for e in entries))


def check_tree(base):
    """Read-only inventory. Never open special files or follow symlinks."""
    findings = []
    flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_DIRECTORY | os.O_CLOEXEC

    def scan(fd, relative):
        for name in os.listdir(fd):
            path = relative + '/' + name if relative else name
            info = os.stat(name, dir_fd=fd, follow_symlinks=False)
            if stat.S_ISLNK(info.st_mode):
                continue
            if stat.S_ISDIR(info.st_mode):
                child = os.open(name, flags, dir_fd=fd)
                try:
                    opened = os.fstat(child)
                    if (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino):
                        raise ValueError('tree changed during inspection: ' + repr(path))
                    scan(child, path)
                finally:
                    os.close(child)
            elif stat.S_ISREG(info.st_mode):
                if info.st_nlink != 1:
                    findings.append({'kind': 'hardlink', 'path': path, 'links': info.st_nlink})
            else:
                findings.append({'kind': 'special', 'path': path, 'type': stat.S_IFMT(info.st_mode)})

    fd = os.open(base, flags)
    try:
        scan(fd, '')
    finally:
        os.close(fd)
    return findings


def protect(base, uid, gid, apache, docroot):
    if docroot.startswith('/') or '..' in docroot.split('/'):
        raise ValueError('invalid document root')
    findings = check_tree(base)
    if findings:
        first = findings[0]
        raise ValueError(f"{first['kind']}: {first['path']!r}; run isolate_tree.py --check BASE for the full list")
    root_uid = os.geteuid()
    links = []
    flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC

    def walk(fd, relative, shared=False, public=False):
        st = os.fstat(fd)
        directory = stat.S_ISDIR(st.st_mode)
        if not directory and (not stat.S_ISREG(st.st_mode) or st.st_nlink != 1):
            raise ValueError(f'special or hard-linked file: {relative!r} (links={st.st_nlink})')
        os.fchown(fd, uid if shared else root_uid, gid)
        os.fchmod(fd, (0o700 if directory else 0o600) if shared else (0o750 if directory else 0o640))
        access = (5 if directory else 4) if public else 0
        if relative in ('', 'releases') or (relative.startswith('releases/') and directory and not shared):
            # Apache can traverse release ancestors, but only read the public subtree.
            access = access or 1
        acl(fd, 7 if directory else 6, 0 if shared else (5 if directory else 4), apache, access)
        if not directory:
            return
        for name in os.listdir(fd):
            child_rel = relative + '/' + name if relative else name
            info = os.stat(name, dir_fd=fd, follow_symlinks=False)
            if stat.S_ISLNK(info.st_mode):
                if public:
                    links.append(os.path.join(base, child_rel))
                continue
            if not (stat.S_ISREG(info.st_mode) or stat.S_ISDIR(info.st_mode)):
                raise ValueError(f'special file: {child_rel!r}')
            child = os.open(name, flags | (os.O_DIRECTORY if stat.S_ISDIR(info.st_mode) else 0), dir_fd=fd)
            try:
                if os.fstat(child).st_ino != info.st_ino or os.fstat(child).st_dev != info.st_dev:
                    raise ValueError('tree changed')
                is_shared = child_rel == 'shared' or child_rel.startswith('shared/')
                parts = child_rel.split('/')
                prefix = '/'.join(parts[:2]) if len(parts) > 1 else ''
                is_public = public or len(parts) > 1 and parts[0] == 'releases' and re.fullmatch(r'\d{8}-\d{6}', parts[1]) and (not docroot or child_rel == prefix + '/' + docroot or child_rel.startswith(prefix + '/' + docroot + '/'))
                walk(child, child_rel, is_shared, bool(is_public))
            finally:
                os.close(child)

    # Open the routing parent first; changes to link names cannot redirect these operations.
    base_fd = os.open(base, flags | os.O_DIRECTORY)
    try:
        for name in ('releases', 'shared'):
            fd = os.open(name, flags | os.O_DIRECTORY, dir_fd=base_fd)
            os.close(fd)
        walk(base_fd, '')
        # Grant Apache access only to shared data explicitly linked inside DocumentRoot.
        for link in links:
            target = os.path.realpath(link)
            shared_base = os.path.join(base, 'shared')
            if not target.startswith(shared_base + '/'):
                continue
            parts = os.path.relpath(target, base).split('/')
            parent = os.dup(base_fd)
            try:
                for index, part in enumerate(parts):
                    child = os.open(part, flags | (os.O_DIRECTORY if index < len(parts) - 1 else 0), dir_fd=parent)
                    os.close(parent)
                    parent = child
                    if index < len(parts) - 1:
                        acl(parent, 7, 0, apache, 1)
                walk(parent, '/'.join(parts), True, True)
            finally:
                os.close(parent)
    finally:
        os.close(base_fd)


if __name__ == '__main__':
    if len(sys.argv) == 3 and sys.argv[1] == '--check':
        import json
        findings = check_tree(sys.argv[2])
        for finding in findings:
            print(json.dumps(finding, ensure_ascii=False))
        print(json.dumps({'issues': len(findings)}))
        sys.exit(1 if findings else 0)
    protect(sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), int(sys.argv[4]), sys.argv[5])
