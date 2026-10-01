#!/usr/bin/env python3
"""Root CLI orchestration; stdlib only. API checkout is used only as its run user."""
import argparse
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import shlex
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile


COMPONENTS = ("database", "web-logs", "file-manager", "waf", "php-limits")
# php-limits restarts PHP-FPM once when installed, so it is only installed when selected.
DEFAULT_COMPONENTS = ("database", "web-logs", "file-manager", "waf")
REPOSITORY = "https://github.com/FELDSAM-INC/ispconfig-rest.git"
FILES = (
    "worker/install.sh", "worker/run.php", "worker/DatabaseWorker.php", "worker/SqlDump.php",
    "web-log-worker/install.sh", "web-log-worker/run.php", "web-log-worker/nginx-runtime.conf",
    "app/Support/WebLogReader.php", "app/Support/WebRuntimeDirectory.php", "app/Support/WebPhpDefaults.php", "app/Support/CronOutputLog.php",
    "file-manager-worker/install.sh", "file-manager-worker/reconcile.py", "file-manager-worker/sites.php",
    "file-manager-worker/wordpress-install.sh", "file-manager-worker/wordpress.php", "file-manager-worker/WordPressWorker.php",
    "file-manager-worker/wordpress-sandbox.py", "file-manager-worker/wordpress-tools.py", "app/Support/WordPressPolicy.php", "app/Support/WebDomainAutoalias.php",
    "file-manager-worker/sshd.conf", "waf-server/install.sh", "waf-server/run.php",
    "waf-server/configure.php", "waf-server/crs.json", "waf-server/crs-release-key.gpg",
    "waf-server/ispconfig-security.php", "waf-server/ispconfig-waf",
    "app/Support/WebWafPolicy.php", "app/Support/WebWafAudit.php", "app/Support/WebWafProfiles.php",
    "app/Support/WebWafCrs.php", "app/Support/WebWafIspconfigSecurity.php", "php-limits/install.sh", "php-limits/run.php",
    "php-limits/ispconfig-php-limits", "app/Support/PhpLimits.php", "server-tools/remote.php",
)


class Failure(RuntimeError):
    pass


def run(command, *, data=None, timeout=60, live=False):
    """No local shell. Only root-owned installer output is streamed."""
    try:
        result = subprocess.run(command, input=data, stdout=None if live else subprocess.PIPE,
                                stderr=None if live else subprocess.PIPE, timeout=timeout, check=False)
    except (OSError, subprocess.TimeoutExpired) as error:
        raise Failure(f"{command[0]} could not finish: {error}") from error
    if result.returncode:
        message = (result.stderr or b"").decode("utf-8", "replace").strip()
        # Terminal control sequences from a host must not control the local terminal.
        message = re.sub(r"[\x00-\x08\x0b-\x1f\x7f]", "?", message)
        raise Failure(f"{command[0]} failed (exit {result.returncode}): {message[-3000:]}")
    return result.stdout or b""


def trusted(path):
    """Check the lexical path too: no symlink components, root-owned, no writable ancestors."""
    path = Path(os.path.abspath(path))
    for item in (path, *path.parents):
        info = item.lstat()
        if stat.S_ISLNK(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
            raise Failure(f"Unsafe root source path: {item}. Use a root-owned release outside the API checkout.")
    return path


def hostname(value):
    if not isinstance(value, str) or len(value) > 253:
        raise Failure("Invalid SSH hostname in ISPConfig inventory.")
    try:
        ipaddress.ip_address(value)
        return value
    except ValueError:
        if not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9_.-]*", value):
            raise Failure("Invalid SSH hostname in ISPConfig inventory.")
    return value


def inventory(raw, selected, overrides):
    try:
        rows = json.loads(raw)["servers"]
        if not isinstance(rows, list) or len(rows) > 10000:
            raise ValueError("Invalid row list")
        seen = set()
        for row in rows:
            if not isinstance(row, dict) or type(row["id"]) is not int or row["id"] < 1 or row["id"] in seen:
                raise ValueError("Invalid server identity")
            seen.add(row["id"])
            if not all(type(row[key]) is bool for key in ("active", "web", "database")):
                raise ValueError("Invalid server role")
            if type(row["mirror_of"]) is not int or row["mirror_of"] < 0:
                raise ValueError("Invalid mirror identity")
            row["host"] = hostname(overrides.get(row["id"], row["host"]))
        if (set(selected) | set(overrides)) - seen:
            raise Failure("Unknown server ID in --server or --host.")
        return [row for row in rows if not selected or row["id"] in selected]
    except (ValueError, KeyError, TypeError) as error:
        raise Failure("Invalid server inventory; no servers were changed.") from error


def components_for(row, requested):
    if not row["active"] or row["mirror_of"]:
        return []
    return [item for item in requested if row["database"] and item == "database"] + [
        item for item in requested if item != "database" and row["web"]]


class Target:
    def __init__(self, row, args, local_id):
        self.row, self.args = row, args
        self.local = row["id"] == local_id
        self.label = f"{row['id']} {row['host']}" + (" (local)" if self.local else "")

    def ssh(self):
        args = ["ssh", "-T", "-o", "BatchMode=yes", "-o", "PasswordAuthentication=no",
                "-o", "KbdInteractiveAuthentication=no", "-o", "StrictHostKeyChecking=yes",
                "-o", "ForwardAgent=no", "-o", "ClearAllForwardings=yes", "-o", "ConnectTimeout=10",
                "-o", "ServerAliveInterval=15", "-o", "ServerAliveCountMax=3"]
        if self.args.ssh_port:
            args += ["-p", str(self.args.ssh_port)]
        if self.args.identity:
            args += ["-i", str(self.args.identity), "-o", "IdentitiesOnly=yes"]
        if self.args.known_hosts:
            args += ["-o", "UserKnownHostsFile=" + str(self.args.known_hosts)]
        return args + ["-l", self.args.ssh_user, "--", self.row["host"]]

    def execute(self, command, **kwargs):
        if self.local:
            return run(command, **kwargs)
        remote = ([] if self.args.ssh_user == "root" else ["sudo", "-n", "--"]) + command
        return run(self.ssh() + [shlex.join(remote)], **kwargs)

    def probe(self, components, helper):
        try:
            output = self.execute(["php", "--", "probe", str(self.row["id"]), ",".join(components)],
                                  data=helper, timeout=40)
            result = json.loads(output)
            if result["id"] != self.row["id"]:
                raise Failure("Wrong server identity.")
            return result
        except (ValueError, KeyError, TypeError) as error:
            raise Failure(f"Invalid probe reply from {self.label}") from error

    def check_access(self):
        try:
            if self.execute(["id", "-u"], timeout=20).strip() != b"0":
                raise Failure("Root access is required on the target.")
        except Failure:
            if not self.local:
                print(f"Configure administrator SSH key access to {self.label} and verify its host-key fingerprint.", file=sys.stderr)
                key = ["-i", str(self.args.identity) + ".pub"] if self.args.identity else []
                port = ["-p", str(self.args.ssh_port)] if self.args.ssh_port else []
                print("  " + shlex.join(["ssh-copy-id"] + key + port + [self.args.ssh_user + "@" + self.row["host"]]), file=sys.stderr)
                print("Test login as root on the REST host; non-root SSH users also need passwordless sudo.", file=sys.stderr)
                print("No SSH passwords are requested, stored, or retried by this command.", file=sys.stderr)
            raise

    def install(self, package, components):
        # The archive contains only our explicit regular-file allowlist, with a SHA-256 manifest.
        script = """set -eu
umask 077
test "$(id -u)" = 0
stage=$(mktemp -d /root/ispconfig-rest-tools.XXXXXXXX)
trap 'rm -rf -- "$stage"' EXIT HUP INT TERM
tar -xz --no-same-owner --no-same-permissions -C "$stage"
cd "$stage"
sha256sum -c SHA256SUMS >/dev/null
""" + shlex.join(["php", "server-tools/remote.php", "install", str(self.row["id"]), ",".join(components)]) + "\n"
        # No extracted code executes until transfer and checksum validation have finished.
        self.execute(["sh", "-c", script], data=package.read_bytes(), timeout=1800, live=True)


def package_release(args, directory, run_as):
    stage = directory / "release"
    stage.mkdir(mode=0o700)
    if args.source:
        source = trusted(args.source)
        for name in FILES:
            origin = trusted(source / name)
            if not origin.is_file():
                raise Failure(f"Missing release file: {name}")
            destination = stage / name
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(origin, destination)
    else:
        commit = run(run_as + ["git", "rev-parse", "HEAD"]).decode().strip()
        if not re.fullmatch(r"[0-9a-f]{40}", commit):
            raise Failure("Cannot determine the installed API release commit.")
        print(f"Fetching trusted worker sources for installed API commit {commit} from {REPOSITORY}", flush=True)
        repo = directory / "git"
        run(["git", "init", "--bare", str(repo)])
        git = ["git", "-c", "core.hooksPath=/dev/null", "-c", "protocol.file.allow=never", "-C", str(repo)]
        branch = os.environ.get("ISPCP_TOOLS_BRANCH", "main")
        if not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9_./-]*", branch) or ".." in branch:
            raise Failure("Invalid release branch in install.conf.")
        run(git + ["fetch", "--no-tags", REPOSITORY, branch], timeout=300)
        # A public object reachable only via a fork/PR must not qualify as trusted code.
        run(git + ["merge-base", "--is-ancestor", commit, "FETCH_HEAD"])
        for name in FILES:
            # Reject executable links/submodules; only blobs whose mode is a regular file qualify.
            mode = run(git + ["ls-tree", commit, "--", name]).split(b" ", 1)[0]
            if mode not in (b"100644", b"100755"):
                raise Failure(f"Release file is missing or not regular: {name}")
            destination = stage / name
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_bytes(run(git + ["show", commit + ":" + name]))
    if args.file_manager_key:
        # PUBLIC key only; never copy the WHMCS private key.
        key = args.file_manager_key.read_bytes()
        if len(key) > 16384 or not re.fullmatch(rb"(?:ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+/=]+(?: [^\r\n]*)?\n?", key):
            raise Failure("--file-manager-key must contain one plain OpenSSH public key.")
        (stage / "whmcs.pub").write_bytes(key)
        run(["ssh-keygen", "-l", "-f", str(stage / "whmcs.pub")])
        (stage / "whmcs-ip").write_text(args.whmcs_ip + "\n")
    names = sorted(str(path.relative_to(stage)) for path in stage.rglob("*") if path.is_file())
    manifest = "".join(hashlib.sha256((stage / name).read_bytes()).hexdigest() + "  " + name + "\n" for name in names)
    (stage / "SHA256SUMS").write_text(manifest)
    archive = directory / "tools.tar.gz"
    with tarfile.open(archive, "w:gz") as bundle:
        for name in names + ["SHA256SUMS"]:
            info = bundle.gettarinfo(str(stage / name), arcname=name)
            info.uid = info.gid = 0
            info.uname = info.gname = "root"
            info.mode = 0o600
            with (stage / name).open("rb") as stream:
                bundle.addfile(info, stream)
    return archive


def parser():
    result = argparse.ArgumentParser(description="Install/update ISPConfig REST workers and WAF across ISPConfig servers.")
    result.add_argument("action", choices=("install", "update", "status"))
    result.add_argument("--components", default=",".join(DEFAULT_COMPONENTS), help="Comma-separated database,web-logs,file-manager,waf,php-limits (default: all except php-limits)")
    result.add_argument("--server", type=int, action="append", default=[], help="Only this server ID (repeatable)")
    result.add_argument("--host", action="append", default=[], metavar="ID=HOST", help="Override SSH address/alias for a server")
    result.add_argument("--ssh-user", default="root", help="SSH user; non-root requires passwordless sudo")
    result.add_argument("--ssh-port", type=int, help="SSH port (otherwise SSH config/default)")
    result.add_argument("--identity", type=Path, help="Administrator SSH private key; stays on the REST host")
    result.add_argument("--known-hosts", type=Path)
    result.add_argument("--source", type=Path, help="Reviewed root-owned release instead of fetching the installed commit from GitHub")
    result.add_argument("--file-manager-key", type=Path, help="WHMCS PUBLIC SSH key, required for first file-manager installation")
    result.add_argument("--whmcs-ip", help="WHMCS egress IPv4/IPv6 for the file-manager key restriction")
    result.add_argument("--dry-run", action="store_true", help="Discover and check SSH/requirements without installing or granting privileges")
    result.add_argument("--yes", action="store_true", help="Apply the displayed plan without an interactive confirmation")
    result.add_argument("--no-grants", action="store_true", help="Require all worker table privileges to be configured already")
    return result


def main(argv=None):
    args = parser().parse_args(argv)
    if os.geteuid() != 0:
        raise Failure("Run as root (sudo ispconfig-rest server-tools install).")
    os.umask(0o077)
    requested = list(dict.fromkeys(args.components.split(",")))
    if not requested or any(item not in COMPONENTS for item in requested):
        raise Failure("Unknown --components value.")
    if not re.fullmatch(r"[a-z_][a-z0-9_-]*[$]?", args.ssh_user) or (args.ssh_port is not None and not 1 <= args.ssh_port <= 65535):
        raise Failure("Invalid SSH user or port.")
    if bool(args.file_manager_key) != bool(args.whmcs_ip):
        raise Failure("Supply both --file-manager-key and --whmcs-ip.")
    if args.whmcs_ip:
        try:
            args.whmcs_ip = str(ipaddress.ip_address(args.whmcs_ip))
        except ValueError as error:
            raise Failure("--whmcs-ip must be a single IPv4 or IPv6 address.") from error
    overrides = {}
    for override in args.host:
        try:
            ident, host = override.split("=", 1)
            overrides[int(ident)] = hostname(host)
        except ValueError as error:
            raise Failure("Use --host ID=HOST.") from error
    install_dir = os.environ.get("ISPCP_TOOLS_INSTALL_DIR", "")
    run_user = os.environ.get("ISPCP_TOOLS_RUN_USER", "")
    php = os.environ.get("ISPCP_TOOLS_PHP", "php")
    if not install_dir.startswith("/") or not re.fullmatch(r"[a-z_][a-z0-9_-]*[$]?", run_user) or run_user == "root":
        raise Failure("Use the installed ispconfig-rest manager; an unprivileged API run user is required.")
    run_as = ["sudo", "-u", run_user, "--", "env", "-C", install_dir, php]
    rows = inventory(run(run_as + ["artisan", "server-tools:inventory", "--no-ansi"]), args.server, overrides)
    here = trusted(Path(__file__).absolute()).parent
    helper = trusted(here / "remote.php")
    identity = json.loads(run([php, str(helper), "identity"]))
    helper_code = helper.read_bytes()
    plan, errors = [], []
    for row in rows:
        selected = components_for(row, requested)
        if not selected:
            print(f"Skip {row['id']} {row['host']}: inactive, mirrored, or no selected server role.")
            continue
        target = Target(row, args, identity["id"])
        print(f"Checking {target.label} ...", flush=True)
        try:
            target.check_access()
            probe = target.probe([], helper_code)
            if args.action in ("update", "status"):
                selected = [item for item in selected if item in probe["installed"]]
            if not selected:
                print(f"  No selected components installed on {target.label}.")
                continue
            probe = target.probe(selected, helper_code)
            print("  " + ", ".join(selected), flush=True)
            if probe["requirements"]:
                raise Failure("Missing requirements: " + ", ".join(probe["requirements"]))
            if "file-manager" in selected and not probe["file_manager_configured"] and not args.file_manager_key:
                raise Failure("First file-manager installation needs --file-manager-key /path/whmcs.pub --whmcs-ip IP; or select --components database,web-logs,waf.")
            missing = probe["grants"]["missing"]
            if missing:
                print("  Required table grants for " + probe["grants"]["account"] + ": " + json.dumps(missing, sort_keys=True))
                if args.no_grants or args.action == "status":
                    raise Failure("Worker database grants are incomplete.")
            plan.append((target, selected, probe))
        except Failure as error:
            errors.append(f"{target.label}: {error}")
    if errors:
        raise Failure("Preflight failed; no components were installed.\n" + "\n".join(errors))
    if not plan:
        print("No applicable servers/components.")
        return 0
    panel_local = "waf" in requested and identity["panel"] and any("waf" in selected for _, selected, _ in plan)
    if panel_local:
        print("The local ISPConfig panel directive allowlist will also be configured.")
    if args.dry_run or args.action == "status":
        print("Checks complete. No installation or database grants changed.")
        return 0
    if not args.yes:
        if not sys.stdin.isatty() or input("Apply this server-tools plan, including listed table grants? [y/N] ").lower() not in ("y", "yes"):
            raise Failure("Not applied. Use --yes for unattended installation.")
    base = Path("/var/lib/ispconfig-rest-server-tools")
    base.mkdir(mode=0o700, exist_ok=True)
    trusted(base)
    with tempfile.TemporaryDirectory(prefix="release-", dir=base) as work:
        # Fetch/stage before grants; invalid or unpublished revisions cannot change SQL privileges.
        package = package_release(args, Path(work), ["sudo", "-u", run_user, "--", "env", "-C", install_dir])
        for target, selected, probe in plan:
            if probe["grants"]["missing"]:
                run([php, str(helper), "grant"], data=json.dumps(probe["grants"]).encode(), live=True)
                checked = target.probe(selected, helper_code)
                if checked["grants"]["missing"]:
                    raise Failure(f"Grants still missing on {target.label}; no components installed.")
        failures = []
        for target, selected, probe in plan:
            print(f"Applying {target.label}: {', '.join(selected)}", flush=True)
            try:
                target.install(package, selected)
                verified = target.probe(selected, helper_code)
                if set(selected) - set(verified["installed"]):
                    raise Failure("Installed cron entries are missing.")
                print(f"OK {target.label}", flush=True)
            except Failure as error:
                failures.append(f"{target.label}: {error}")
        if panel_local and not any(target.local and "waf" in selected for target, selected, _ in plan):
            panel = Target({"id": identity["id"], "host": "local-panel"}, args, identity["id"])
            try:
                panel.install(package, ["panel-security"])
            except Failure as error:
                failures.append("Local panel security: " + str(error))
        if failures:
            raise Failure("Some components failed; successful installations are retained. Correct the errors and rerun.\n" + "\n".join(failures))
    print("Server tools installed/updated. Worker capabilities appear after the next minute's cron run.")
    if "waf" in requested:
        print("WAF stays disabled on new sites. Optional per-server Atomicorp key: sudo ispconfig-waf atomic-key")
    if "file-manager" in requested:
        print("Configure the WHMCS file-manager server mapping separately using each server's verified SSH host key.")
    if "php-limits" in requested:
        print("PHP resource limits apply to accounts whose limits are set; check with: ispconfig-php-limits status")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (Failure, OSError, ValueError) as error:
        print(f"ERROR: {error}", file=sys.stderr)
        sys.exit(1)
