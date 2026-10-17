"""Create a distributable without development files, credentials, or Git metadata."""
from pathlib import Path
import sys
import zipfile

root = Path(__file__).resolve().parents[1]
output = Path(sys.argv[1] if len(sys.argv) > 1 else '/workspace/artifacts/fa-wp-speedup-0.2.0.zip')
output.parent.mkdir(parents=True, exist_ok=True)
files = [root / name for name in ('fa-wp-speedup.php', 'uninstall.php', 'README.md', 'LICENSE')]
for folder in ('includes', 'licenses', 'languages', 'assets', 'docs'):
    files.extend(sorted((root / folder).glob('*')))
with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
    for file in files:
        if file.is_file():
            archive.write(file, 'fa-wp-speedup/' + str(file.relative_to(root)))
print(output)
