import io, json, os, pathlib, subprocess, tarfile, tempfile, unittest
ROOT = pathlib.Path(__file__).resolve().parents[1]

class ComponentFiles(unittest.TestCase):
    def test_code_rejects_links_and_traversal(self):
        for name, kind in [('link', tarfile.SYMTYPE), ('../escape', tarfile.REGTYPE), ('device', tarfile.CHRTYPE), ('shared/key', tarfile.REGTYPE)]:
            with self.subTest(name=name), tempfile.TemporaryDirectory() as tmp:
                root=pathlib.Path(tmp); (root/'target').mkdir()
                with tarfile.open(root/'archive','w') as t:
                    m=tarfile.TarInfo(name); m.type=kind; m.linkname='/etc/passwd'; t.addfile(m,io.BytesIO())
                r=subprocess.run(['python3',str(ROOT/'agent/lib/component_archive.py'),str(root/'archive'),str(root/'target')],capture_output=True)
                self.assertNotEqual(r.returncode,0)
                self.assertEqual(list((root/'target').iterdir()),[])

    def test_backup_preserves_ownership_and_rejects_links(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=pathlib.Path(tmp); data=root/'data'; data.mkdir(); (data/'database').write_text('original')
            spec=root/'spec'; spec.write_text(json.dumps({'state':{'path':str(data),'uid':os.getuid(),'gid':os.getgid()}}))
            command=['python3',str(ROOT/'agent/lib/component_backup.py')]
            subprocess.run(command+['snapshot',str(spec),str(root/'backup')],check=True)
            (data/'database').write_text('changed')
            subprocess.run(command+['restore',str(spec),str(root/'backup')],check=True)
            self.assertEqual((data/'database').read_text(),'original')
            self.assertEqual((data/'database').stat().st_mode&0o777,0o600)
            (data/'linked').symlink_to('/etc/passwd')
            result=subprocess.run(command+['snapshot',str(spec),str(root/'bad')],capture_output=True)
            self.assertNotEqual(result.returncode,0)

    def test_restore_validates_whole_archive_before_writes(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=pathlib.Path(tmp); data=root/'data'; data.mkdir(); (data/'database').write_text('retained')
            spec=root/'spec'; spec.write_text(json.dumps({'state':{'path':str(data),'uid':os.getuid(),'gid':os.getgid()}}))
            with tarfile.open(root/'bad','w') as tar:
                m=tarfile.TarInfo('state');m.type=tarfile.DIRTYPE;tar.addfile(m)
                m=tarfile.TarInfo('state/key');m.type=tarfile.SYMTYPE;m.linkname='/etc/passwd';tar.addfile(m)
            result=subprocess.run(['python3',str(ROOT/'agent/lib/component_backup.py'),'restore',str(spec),str(root/'bad')],capture_output=True)
            self.assertNotEqual(result.returncode,0)
            self.assertEqual((data/'database').read_text(),'retained')

if __name__=='__main__': unittest.main()
