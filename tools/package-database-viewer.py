"""Package only reviewed plugin runtime files; no dependency installation required."""
from pathlib import Path
import hashlib
import json
import zipfile

repo = Path(__file__).resolve().parents[1]
plugin = repo / "database-viewer"
metadata = json.loads((plugin / "plugin.json").read_text())
assert metadata["id"] == plugin.name == "database-viewer"
files = [plugin / name for name in ("plugin.json", "README.md", "source-inspection.md", "verification.md", "LICENSE")]
for directory in ("config", "src", "routes", "resources"):
    files.extend(path for path in (plugin / directory).rglob("*") if path.is_file())
assert all(path.is_file() and not path.is_symlink() for path in files)
target = repo / "dist" / f"database-viewer-{metadata['version']}.zip"
target.parent.mkdir(exist_ok=True)
with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED) as archive:
    for path in sorted(files):
        info = zipfile.ZipInfo(path.relative_to(repo).as_posix(), date_time=(2026, 9, 27, 0, 0, 0))
        info.external_attr = 0o100644 << 16
        archive.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED)
with zipfile.ZipFile(target) as archive:
    assert archive.testzip() is None
    assert set(archive.namelist()) == {path.relative_to(repo).as_posix() for path in files}
digest = hashlib.sha256(target.read_bytes()).hexdigest()
target.with_suffix(".zip.sha256").write_text(f"{digest}  {target.name}\n")
print(f"{target}: {len(files)} files; SHA256 {digest}")
