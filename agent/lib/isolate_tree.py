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


def split_internal_links(base):
    """Explicit repair of fully inventoried, same-release regular-file aliases only."""
    import secrets
    flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC
    root = os.open(base, flags | os.O_DIRECTORY)
    groups = {}

    def parent(path):
        fd = os.dup(root)
        try:
            for part in path.split('/')[:-1]:
                child = os.open(part, flags | os.O_DIRECTORY, dir_fd=fd)
                os.close(fd)
                fd = child
            return fd
        except BaseException:
            os.close(fd)
            raise

    try:
        issues = check_tree(base)
        for item in issues:
            path = item['path']
            if item['kind'] != 'hardlink' or not re.fullmatch(r'releases/\d{8}-\d{6}/.+', path):
                raise ValueError('repair is restricted to regular files inside releases: ' + repr(path))
            fd = parent(path)
            try:
                info = os.stat(path.split('/')[-1], dir_fd=fd, follow_symlinks=False)
                if not stat.S_ISREG(info.st_mode):
                    raise ValueError('tree changed')
                groups.setdefault((info.st_dev, info.st_ino), []).append((path, info))
            finally:
                os.close(fd)
        # Validate EVERY group before changing any file.
        for entries in groups.values():
            if any(info.st_nlink != len(entries) for _, info in entries):
                raise ValueError('link outside the inventoried release tree; nothing changed')
            if len({path.split('/')[1] for path, _ in entries}) != 1:
                raise ValueError('cross-release links require separate review; nothing changed')
            if any(info.st_mode & 0o6000 for _, info in entries):
                raise ValueError('privileged file mode; nothing changed')
        changed = []
        for identity, entries in groups.items():
            for index, (path, original) in enumerate(entries):
                fd = parent(path)
                name = path.split('/')[-1]
                temp = '.pjas-unlink-' + secrets.token_hex(12)
                source = dest = None
                try:
                    source = os.open(name, flags | os.O_NONBLOCK, dir_fd=fd)
                    before = os.fstat(source)
                    if (before.st_dev, before.st_ino) != identity or before.st_nlink != len(entries) - index:
                        raise ValueError('links changed during repair')
                    attrs = {key: os.getxattr(source, key) for key in os.listxattr(source)}
                    if attrs.get('security.capability'):
                        raise ValueError('file capability requires separate review')
                    dest = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=fd)
                    while data := os.read(source, 1024 * 1024):
                        offset = 0
                        while offset < len(data):
                            offset += os.write(dest, data[offset:])
                    after = os.fstat(source)
                    if (before.st_size, before.st_mtime_ns, before.st_ctime_ns, before.st_nlink) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns, after.st_nlink):
                        raise ValueError('source changed during copy')
                    os.fchown(dest, original.st_uid, original.st_gid)
                    os.fchmod(dest, stat.S_IMODE(original.st_mode))
                    for key, value in attrs.items():
                        os.setxattr(dest, key, value)
                    os.utime(dest, ns=(original.st_atime_ns, original.st_mtime_ns))
                    os.fsync(dest)
                    current = os.stat(name, dir_fd=fd, follow_symlinks=False)
                    if (current.st_dev, current.st_ino) != identity:
                        raise ValueError('destination changed during copy')
                    os.replace(temp, name, src_dir_fd=fd, dst_dir_fd=fd)
                    os.fsync(fd)
                    changed.append(path)
                finally:
                    if source is not None:
                        os.close(source)
                    if dest is not None:
                        os.close(dest)
                    try:
                        os.unlink(temp, dir_fd=fd)
                    except FileNotFoundError:
                        pass
                    os.close(fd)
        return changed
    finally:
        os.close(root)


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
        os.fchmod(fd, (0o700 if directory else 0o600) if shared else (0o750 if directory or st.st_mode & 0o111 else 0o640))
        access = (5 if directory else 4) if public else 0
        if relative in ('', 'releases') or (relative.startswith('releases/') and directory and not shared):
            # Apache can traverse release ancestors, but only read the public subtree.
            access = access or 1
        acl(fd, 7 if directory or (not shared and st.st_mode & 0o111) else 6, 0 if shared else (5 if directory or st.st_mode & 0o111 else 4), apache, access)
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
    if len(sys.argv) == 4 and sys.argv[1] == '--split-internal-hardlinks' and sys.argv[3] == '--writers-stopped':
        import json
        print(json.dumps({'separated': split_internal_links(sys.argv[2])}, ensure_ascii=False))
        sys.exit(0)
    if len(sys.argv) == 3 and sys.argv[1] == '--check':
        import json
        findings = check_tree(sys.argv[2])
        for finding in findings:
            print(json.dumps(finding, ensure_ascii=False))
        print(json.dumps({'issues': len(findings)}))
        sys.exit(1 if findings else 0)
    protect(sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), int(sys.argv[4]), sys.argv[5])
