import hashlib
import importlib.util
from pathlib import Path
import unittest
import zipfile


REPO = Path(__file__).resolve().parents[1]
PACKAGER_PATH = REPO / "tools" / "package-database-viewer.py"


def load_packager():
    spec = importlib.util.spec_from_file_location("package_database_viewer", PACKAGER_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


class DatabaseViewerPackageTest(unittest.TestCase):
    def test_package_is_deterministic_and_contains_only_runtime_files(self):
        packager = load_packager()

        expected_paths = tuple(sorted(packager.RUNTIME_FILES))
        archive_path, digest = packager.package_plugin(REPO)
        repeated_path, repeated_digest = packager.package_plugin(REPO)

        self.assertEqual("database-viewer-0.2.0.zip", archive_path.name)
        self.assertEqual(64, len(digest))
        self.assertEqual(archive_path, repeated_path)
        self.assertEqual(digest, repeated_digest)

        with zipfile.ZipFile(archive_path) as archive:
            infos = archive.infolist()
            self.assertEqual(list(expected_paths), [info.filename for info in infos])
            self.assertEqual(len(expected_paths), len(set(info.filename for info in infos)))
            self.assertTrue(all(info.date_time == packager.ARCHIVE_TIMESTAMP for info in infos))
            self.assertTrue(all((info.external_attr >> 16) & 0o170000 == 0o100000 for info in infos))
            self.assertIsNone(archive.testzip())

        excluded_parts = {"tests", "docs", ".superpowers", "__pycache__", ".pytest_cache"}
        self.assertFalse(any(excluded_parts.intersection(Path(path).parts) for path in expected_paths))

        sidecar = archive_path.with_suffix(".zip.sha256")
        self.assertEqual(f"{digest}  {archive_path.name}\n", sidecar.read_text(encoding="utf-8"))
        self.assertEqual(digest, hashlib.sha256(archive_path.read_bytes()).hexdigest())


if __name__ == "__main__":
    unittest.main()
