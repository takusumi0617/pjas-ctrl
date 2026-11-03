import io, os, pathlib, stat, subprocess, tarfile, tempfile, unittest, warnings, zipfile
HELPER=pathlib.Path(__file__).resolve().parents[1]/'agent/lib/extract_archive.py'
class ArchiveSecurity(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.root=pathlib.Path(self.temp.name);self.out=self.root/'out';self.out.mkdir();self.victim=self.root/'victim';self.victim.write_text('unchanged')
    def tearDown(self):self.temp.cleanup()
    def run_archive(self,source,ok=False):
        r=subprocess.run(['/usr/bin/python3',str(HELPER),str(source),str(self.out)],capture_output=True)
        self.assertEqual(r.returncode==0,ok,r.stderr.decode());self.assertEqual(self.victim.read_text(),'unchanged')
    def tar(self,name,kind=tarfile.REGTYPE,link=''):
        p=self.root/'in.tgz'
        with tarfile.open(p,'w:gz') as t:
            i=tarfile.TarInfo(name);i.type=kind;i.linkname=link;i.size=0;t.addfile(i)
        return p
    def test_normal(self):self.run_archive(self.tar('public/index.html'),True);self.assertTrue((self.out/'public/index.html').is_file())
    def test_traversal(self):self.run_archive(self.tar('../victim'))
    def test_absolute(self):self.run_archive(self.tar(str(self.victim)))
    def test_symlink(self):self.run_archive(self.tar('link',tarfile.SYMTYPE,'../victim'))
    def test_hardlink(self):self.run_archive(self.tar('link',tarfile.LNKTYPE,'../victim'))
    def test_device(self):self.run_archive(self.tar('device',tarfile.CHRTYPE))
    def test_zip_symlink(self):
        p=self.root/'in.zip'
        with zipfile.ZipFile(p,'w') as z:
            i=zipfile.ZipInfo('link');i.create_system=3;i.external_attr=(stat.S_IFLNK|0o777)<<16;z.writestr(i,'../victim')
        self.run_archive(p)
    def test_duplicate(self):
        p=self.root/'in.zip'
        with warnings.catch_warnings():
            warnings.simplefilter('ignore')
            with zipfile.ZipFile(p,'w') as z:z.writestr('same','a');z.writestr('same','b')
        self.run_archive(p)
if __name__=='__main__':unittest.main()
