import hashlib
import importlib.util
from pathlib import Path
import tempfile
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
    def test_runtime_text_uses_canonical_line_endings(self):
        packager = load_packager()
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / "runtime.txt"
            source.write_bytes(b"first\r\nsecond\r\n")
            self.assertEqual(b"first\nsecond\n", packager.archive_bytes(source))

    def test_package_is_deterministic_and_contains_only_runtime_files(self):
        packager = load_packager()

        expected_paths = tuple(sorted(packager.RUNTIME_FILES))
        archive_path, digest = packager.package_plugin(REPO)
        repeated_path, repeated_digest = packager.package_plugin(REPO)

        self.assertEqual("database-viewer-0.5.0.zip", archive_path.name)
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

        required_runtime = {
            "database-viewer/database/migrations/001_create_database_viewer_sessions_table.php",
            "database-viewer/src/Console/Commands/PruneViewerSessionsCommand.php",
            "database-viewer/src/Enums/SqlAccessMode.php",
            "database-viewer/src/Exceptions/DatabaseStatementException.php",
            "database-viewer/src/Exceptions/ViewerSessionException.php",
            "database-viewer/src/Models/ViewerSession.php",
            "database-viewer/src/Services/BrokerResponseGuard.php",
            "database-viewer/src/Services/GeneralSqlPolicy.php",
            "database-viewer/src/Services/ManagedTransactionPolicy.php",
            "database-viewer/src/Services/SqlStatementInspector.php",
            "database-viewer/src/Services/ViewerSessionManager.php",
            "database-viewer/src/ValueObjects/ViewerSessionHandle.php",
        }
        self.assertTrue(required_runtime.issubset(expected_paths))
        self.assertNotIn("database-viewer/src/Services/ViewerContext.php", expected_paths)
        self.assertFalse(any("database-viewer-ai-token.txt" in path for path in expected_paths))

    def test_release_documentation_describes_full_and_read_only_sql(self):
        readme = (REPO / "database-viewer" / "README.md").read_text(encoding="utf-8")
        for expected in [
            "Database Viewer 0.5.0",
            "Full SQL access",
            "Read-only SQL access",
            "64 KiB",
            "100 statements",
            "TRANSACTION_NOT_ATOMIC",
            "MariaDB account",
            "AI-generated SQL",
            "15-minute",
            "Extend",
            "two-hour",
            "Close",
            "20 active",
            "24 hours",
            "server time",
            "migration",
        ]:
            self.assertIn(expected, readme)


if __name__ == "__main__":
    unittest.main()
