"""Real OpenSSH + real component installers; ONLY inside the disposable test image."""
import importlib.util
import io
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile

assert Path("/.dockerenv").exists() and os.geteuid() == 0
ROOT = Path("/app")
spec = importlib.util.spec_from_file_location("manager", ROOT / "server-tools/manage.py")
manager = importlib.util.module_from_spec(spec)
spec.loader.exec_module(manager)


def cmd(*args):
    return subprocess.check_output(args)


for directory in ("/run/sshd", "/root/.ssh", "/usr/local/ispconfig/server/lib", "/usr/local/ispconfig/server/plugins-enabled", "/usr/local/ispconfig/security"):
    Path(directory).mkdir(parents=True, exist_ok=True)
Path("/root/.ssh").chmod(0o700)
cmd("ssh-keygen", "-q", "-t", "ed25519", "-N", "", "-f", "/root/.ssh/tools")
shutil.copyfile("/root/.ssh/tools.pub", "/root/.ssh/authorized_keys")
Path("/root/.ssh/authorized_keys").chmod(0o600)
pub = cmd("ssh-keygen", "-y", "-f", "/etc/ssh/ssh_host_ed25519_key").decode().strip()
Path("/root/.ssh/known_hosts").write_text("[127.0.0.1]:2222 " + pub + "\n")
cmd("/usr/sbin/sshd", "-p", "2222", "-o", "ListenAddress=127.0.0.1", "-o", "HostKey=/etc/ssh/ssh_host_ed25519_key")
# Only this disposable container uses a systemctl stub.
Path("/usr/bin/systemctl").write_text("#!/bin/sh\nexit 0\n")
Path("/usr/bin/systemctl").chmod(0o755)
cmd("a2dismod", "security2")
Path("/usr/local/ispconfig/server/plugins-enabled/apache2_plugin.inc.php").symlink_to("/tmp/plugin.php")
Path("/usr/local/ispconfig/security/apache_directives.blacklist").write_text("/^\\s*(LoadModule|LoadFile|Include|IncludeOptional)(\\s+|[\\\\])/mi\n")
cmd("php", "/app/tests/Integration/server-tools/fixture.php")

source = Path("/root/tools-source")
source.mkdir(mode=0o700)
for name in manager.FILES:
    destination = source / name
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(ROOT / name, destination)
    destination.chmod(0o600)
args = manager.parser().parse_args(["install", "--ssh-port", "2222", "--identity", "/root/.ssh/tools",
    "--source", str(source), "--file-manager-key", "/root/.ssh/tools.pub", "--whmcs-ip", "127.0.0.1"])
target = manager.Target(dict(id=1, host="127.0.0.1"), args, 0)
helper = (source / "server-tools/remote.php").read_bytes()
probe = target.probe(list(manager.COMPONENTS), helper)
assert not probe["requirements"] and not probe["grants"]["missing"], probe
assert not probe["installed"]

wrong = manager.Target(dict(id=2, host="127.0.0.1"), args, 0)
try:
    wrong.probe(["waf"], helper)
    raise AssertionError("Wrong server ID accepted")
except manager.Failure:
    pass

with tempfile.TemporaryDirectory(dir="/root") as directory:
    archive = manager.package_release(args, Path(directory), [])
    target.install(archive, list(manager.COMPONENTS))
    probe = target.probe(list(manager.COMPONENTS), helper)
    assert set(probe["installed"]) == set(manager.COMPONENTS), probe
    assert probe["file_manager_configured"]
    # Upgrade reuses existing public key/IP; no key options in the update archive.
    args.file_manager_key = None
    args.whmcs_ip = None
    with tempfile.TemporaryDirectory(dir="/root") as update_directory:
        archive = manager.package_release(args, Path(update_directory), [])
        target.install(archive, list(manager.COMPONENTS))
    assert Path("/etc/ispcp-files/client.pub").read_bytes() == Path("/root/.ssh/tools.pub").read_bytes()
    assert not list(Path("/root").glob("ispconfig-rest-tools.*")), "Remote stages leaked"
    for path in ("/usr/local/lib/ispconfig-rest-waf/run.php", "/usr/local/lib/ispconfig-rest-web-log-worker/run.php", "/usr/local/lib/ispconfig-rest-database-worker/run.php"):
        assert Path(path).stat().st_uid == 0 and Path(path).stat().st_mode & 0o777 == 0o600

    # A damaged payload must fail verification before any PHP/installer executes.
    corrupted = Path(directory) / "corrupted.tar.gz"
    with tarfile.open(Path(directory) / "tools.tar.gz", "r:gz") as source_tar, tarfile.open(corrupted, "w:gz") as bad_tar:
        for info in source_tar.getmembers():
            content = source_tar.extractfile(info).read()
            if info.name == "server-tools/remote.php":
                content = b'<?php touch("/root/payload-must-not-run");'
                info.size = len(content)
            bad_tar.addfile(info, io.BytesIO(content))
    try:
        target.install(corrupted, ["waf"])
        raise AssertionError("Corrupted payload accepted")
    except manager.Failure:
        pass
    assert not Path("/root/payload-must-not-run").exists()
    assert not list(Path("/root").glob("ispconfig-rest-tools.*")), "Failed stage leaked"

# Unknown/mismatched host keys and unconfigured login keys must be refused.
cmd("ssh-keygen", "-q", "-t", "ed25519", "-N", "", "-f", "/root/.ssh/wrong")
args.identity = Path("/root/.ssh/wrong")
try:
    target.execute(["true"])
    raise AssertionError("Passwordless authentication should fail")
except manager.Failure:
    pass
args.identity = Path("/root/.ssh/tools")
Path("/root/.ssh/known_hosts").write_text("[127.0.0.1]:2222 " + Path("/root/.ssh/wrong.pub").read_text())
try:
    target.execute(["true"])
    raise AssertionError("Wrong SSH host key accepted")
except manager.Failure:
    pass
print("PASS real SSH, all four installers, repeated update, preserved SFTP settings, root ownership, cleanup and host/login key rejection")
