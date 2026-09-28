"""Build the deterministic Database Viewer release archive."""

from pathlib import Path
import hashlib
import json
import zipfile


ARCHIVE_TIMESTAMP = (2026, 9, 27, 0, 0, 0)
RUNTIME_FILES = (
    "database-viewer/LICENSE",
    "database-viewer/README.md",
    "database-viewer/config/database-viewer.php",
    "database-viewer/plugin.json",
    "database-viewer/resources/js/bridge.mjs",
    "database-viewer/resources/js/viewer.mjs",
    "database-viewer/resources/views/viewer.blade.php",
    "database-viewer/routes/web.php",
    "database-viewer/source-inspection.md",
    "database-viewer/src/DatabaseViewerPlugin.php",
    "database-viewer/src/Enums/AllowedQuery.php",
    "database-viewer/src/Http/ViewerController.php",
    "database-viewer/src/Providers/DatabaseViewerPluginProvider.php",
    "database-viewer/src/Services/AiBroker.php",
    "database-viewer/src/Services/AiLimits.php",
    "database-viewer/src/Services/BrokerLimits.php",
    "database-viewer/src/Services/DatabaseResultSerializer.php",
    "database-viewer/src/Services/MariaDbExecutor.php",
    "database-viewer/src/Services/QueryExecutor.php",
    "database-viewer/src/Services/SchemaBootstrapPolicy.php",
    "database-viewer/src/Services/StudioOrigin.php",
    "database-viewer/src/Services/ViewerAccess.php",
    "database-viewer/src/Services/ViewerContext.php",
    "database-viewer/verification.md",
)


def archive_bytes(source: Path) -> bytes:
    """Return canonical bytes independent of the checkout's line-ending mode."""
    return source.read_bytes().replace(b"\r\n", b"\n")


def package_plugin(repo: Path) -> tuple[Path, str]:
    repo = repo.resolve()
    metadata = json.loads((repo / "database-viewer" / "plugin.json").read_text(encoding="utf-8"))
    if metadata["id"] != "database-viewer":
        raise ValueError("plugin id must be database-viewer")

    sources = [repo / path for path in RUNTIME_FILES]
    if any(not path.is_file() or path.is_symlink() for path in sources):
        raise ValueError("runtime allowlist contains a missing file or symlink")

    target = repo / "dist" / f"database-viewer-{metadata['version']}.zip"
    target.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED) as archive:
        for relative_path, source in zip(RUNTIME_FILES, sources):
            info = zipfile.ZipInfo(relative_path, date_time=ARCHIVE_TIMESTAMP)
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            archive.writestr(info, archive_bytes(source), compress_type=zipfile.ZIP_DEFLATED)

    with zipfile.ZipFile(target) as archive:
        if archive.testzip() is not None:
            raise ValueError("archive CRC verification failed")
        if tuple(archive.namelist()) != RUNTIME_FILES:
            raise ValueError("archive content does not match runtime allowlist")

    digest = hashlib.sha256(target.read_bytes()).hexdigest()
    target.with_suffix(".zip.sha256").write_text(
        f"{digest}  {target.name}\n", encoding="utf-8"
    )
    return target, digest


def main() -> None:
    archive, digest = package_plugin(Path(__file__).resolve().parents[1])
    print(f"{archive}: {len(RUNTIME_FILES)} files; SHA256 {digest}")


if __name__ == "__main__":
    main()
