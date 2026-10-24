#!/usr/bin/env python3
"""Integration test of code-only updater using isolated local Git repositories."""
from pathlib import Path
import os, shutil, subprocess, tempfile
SCRIPT = Path(__file__).resolve().parents[1] / 'bin/update-code-only.sh'

def run(args, cwd, expected=0):
    r = subprocess.run(args, cwd=cwd, text=True, capture_output=True)
    assert r.returncode == expected, (args, r.returncode, r.stdout, r.stderr)
    return r.stdout.strip()

with tempfile.TemporaryDirectory(prefix='musedock-ha-updater-') as tmp:
    base = Path(tmp)
    remote, source, node = [base / n for n in ('remote.git', 'source', 'node')]
    run(['git', 'init', '--bare', '--initial-branch=main', str(remote)], base)
    run(['git', 'clone', str(remote), str(source)], base)
    run(['git', 'config', 'user.name', 'Test'], source)
    run(['git', 'config', 'user.email', 'test@example.invalid'], source)
    (source / 'bin').mkdir()
    shutil.copy2(SCRIPT, source / 'bin/update-code-only.sh')
    (source / 'payload').write_text('old')
    run(['git', 'add', '.'], source)
    run(['git', 'commit', '-m', 'initial'], source)
    run(['git', 'push', 'origin', 'main'], source)
    old = run(['git', 'rev-parse', 'HEAD'], source)
    run(['git', 'clone', str(remote), str(node)], base)
    (source / 'payload').write_text('new')
    run(['git', 'commit', '-am', 'release'], source)
    run(['git', 'push', 'origin', 'main'], source)
    new = run(['git', 'rev-parse', 'HEAD'], source)
    cmd = ['bash', 'bin/update-code-only.sh', '--target', new]
    run(cmd, node)
    assert (node / 'payload').read_text() == 'old', 'Preview changed code'
    run(cmd + ['--apply'], node)
    assert (node / 'payload').read_text() == 'new'
    run(cmd + ['--apply'], node)  # idempotent
    run(['bash', 'bin/update-code-only.sh', '--target', old, '--apply'], node, 1)
    (node / 'untracked').write_text('preserve')
    run(cmd + ['--apply'], node, 1)
    assert (node / 'untracked').read_text() == 'preserve'
    (node / 'untracked').unlink()
    (node / 'payload').write_text('local')
    run(cmd + ['--apply'], node, 1)
    assert (node / 'payload').read_text() == 'local'
    run(['git', 'checkout', '--', 'payload'], node)
    run(['git', 'checkout', '-b', 'other'], node)
    run(cmd + ['--apply'], node, 1)
    run(['bash', 'bin/update-code-only.sh', '--target', 'main'], node, 2)
print('8 code-only updater scenarios passed (isolated Git repositories)')
