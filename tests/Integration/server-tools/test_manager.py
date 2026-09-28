import argparse
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import tarfile
import unittest
from unittest.mock import patch, MagicMock

ROOT = Path(__file__).resolve().parents[3]
SPEC = importlib.util.spec_from_file_location("manager", ROOT / "server-tools/manage.py")
manager = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(manager)


class ManagerTests(unittest.TestCase):
    def row(self, **changes):
        return dict(id=1, host="web.example.test", active=True, web=True, database=False, mirror_of=0, **changes)

    def args(self, *extra):
        return manager.parser().parse_args(["install", *extra])

    def test_role_selection_and_mirrors(self):
        row = self.row()
        self.assertEqual(["web-logs", "file-manager", "waf", "php-limits"], manager.components_for(row, manager.COMPONENTS))
        self.assertEqual(["web-logs", "file-manager", "waf"], manager.components_for(row, manager.DEFAULT_COMPONENTS))
        self.assertEqual("database,web-logs,file-manager,waf", self.args().components)
        row.update(web=False, database=True)
        self.assertEqual(["database"], manager.components_for(row, manager.COMPONENTS))
        row["mirror_of"] = 3
        self.assertEqual([], manager.components_for(row, manager.COMPONENTS))
        row.update(mirror_of=0, active=False)
        self.assertEqual([], manager.components_for(row, manager.COMPONENTS))

    def test_inventory_rejects_ssh_option_and_shell_injection(self):
        for host in ("-oProxyCommand=id", "good;id", "$(id)", "root@host", "host\nother", "host space"):
            row = self.row()
            row["host"] = host
            with self.subTest(host=host), self.assertRaises(manager.Failure):
                manager.inventory(json.dumps({"servers": [row]}), [], {})

    def test_explicit_address_and_server_selection(self):
        rows = [self.row(), dict(id=2, host="db", active=True, web=False, database=True, mirror_of=0)]
        result = manager.inventory(json.dumps({"servers": rows}), [2], {2: "2001:db8::1"})
        self.assertEqual(2, result[0]["id"])
        self.assertEqual("2001:db8::1", result[0]["host"])
        with self.assertRaises(manager.Failure):
            manager.inventory(json.dumps({"servers": rows}), [99], {})

    def test_duplicate_and_wrong_type_inventory_rejected(self):
        with self.assertRaises(manager.Failure):
            manager.inventory(json.dumps({"servers": [self.row(), self.row()]}), [], {})
        row = self.row()
        row["active"] = "true"
        with self.assertRaises(manager.Failure):
            manager.inventory(json.dumps({"servers": [row]}), [], {})

    def test_ssh_never_prompts_or_ignores_host_key_check(self):
        target = manager.Target(self.row(), self.args("--ssh-user", "deploy", "--identity", "/root/key", "--ssh-port", "2222"), 0)
        command = target.ssh()
        for item in ("BatchMode=yes", "StrictHostKeyChecking=yes", "PasswordAuthentication=no", "ForwardAgent=no", "IdentitiesOnly=yes"):
            self.assertIn(item, command)
        with patch.object(manager, "run", return_value=b"ok") as run:
            target.execute(["php", "--", "probe", "1", "waf"])
            self.assertEqual("sudo -n -- php -- probe 1 waf", run.call_args.args[0][-1])

    def test_local_target_does_not_require_ssh(self):
        target = manager.Target(self.row(), self.args(), 1)
        with patch.object(manager, "run", return_value=b"ok") as run:
            target.execute(["php", "--", "probe", "1", "waf"])
            self.assertEqual("php", run.call_args.args[0][0])

    def test_wrong_probe_identity_rejected(self):
        target = manager.Target(self.row(), self.args(), 1)
        with patch.object(target, "execute", return_value=b'{"id": 99}'), self.assertRaises(manager.Failure):
            target.probe(["waf"], b"<?php")

    def test_archive_is_private_checked_and_removed_on_remote(self):
        target = manager.Target(self.row(), self.args(), 0)
        with tempfile.TemporaryDirectory() as directory:
            archive = Path(directory) / "tools.tar.gz"
            archive.write_bytes(b"archive")
            with patch.object(target, "execute") as execute:
                target.install(archive, ["waf"])
            script = execute.call_args.args[0][2]
            self.assertIn("/root/ispconfig-rest-tools.", script)
            self.assertIn("umask 077", script)
            self.assertIn("trap", script)
            self.assertLess(script.index("sha256sum -c"), script.index("remote.php"))
            self.assertEqual(b"archive", execute.call_args.kwargs["data"])

    def test_web_owned_or_writable_source_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            file = Path(directory) / "script"
            file.write_text("data")
            # /tmp is writable, even when test is running as root.
            with self.assertRaises(manager.Failure):
                manager.trusted(file)

    def test_symlink_source_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            link = Path(directory) / "source"
            link.symlink_to(ROOT / "server-tools/remote.php")
            with self.assertRaises(manager.Failure):
                manager.trusted(link)

    def main_fixture(self, stack, rows=None):
        rows = rows or [self.row()]
        stack.enter_context(patch.dict(manager.os.environ, {"ISPCP_TOOLS_INSTALL_DIR": "/api", "ISPCP_TOOLS_RUN_USER": "www-data", "ISPCP_TOOLS_PHP": "php"}))
        stack.enter_context(patch.object(manager.os, "geteuid", return_value=0))
        stack.enter_context(patch.object(manager.os, "umask"))
        stack.enter_context(patch.object(manager, "trusted", side_effect=lambda path: Path(path)))
        stack.enter_context(patch.object(manager, "run", side_effect=[json.dumps({"servers": rows}).encode(), b'{"id":0,"panel":false}']))
        stack.enter_context(contextlib.redirect_stdout(io.StringIO()))
        target = MagicMock()
        target.label = "fixture"
        target.local = False
        target.probe.return_value = {"id": 1, "installed": ["web-logs"], "requirements": [], "file_manager_configured": True,
                                     "grants": {"missing": {}, "account": "worker@host", "database": "dbispconfig"}}
        factory = stack.enter_context(patch.object(manager, "Target", return_value=target))
        package = stack.enter_context(patch.object(manager, "package_release"))
        return target, factory, package

    def test_dry_run_does_not_fetch_copy_install_or_grant(self):
        with contextlib.ExitStack() as stack:
            target, _, package = self.main_fixture(stack)
            self.assertEqual(0, manager.main(["install", "--dry-run"]))
            target.check_access.assert_called_once()
            target.install.assert_not_called()
            package.assert_not_called()

    def test_update_does_not_add_waf_or_file_manager(self):
        with contextlib.ExitStack() as stack:
            target, _, _ = self.main_fixture(stack)
            self.assertEqual(0, manager.main(["update", "--dry-run"]))
            self.assertEqual(["web-logs"], target.probe.call_args_list[1].args[0])

    def test_any_failed_target_stops_before_fetch_or_install(self):
        with contextlib.ExitStack() as stack:
            row = self.row()
            row["id"] = 2
            target, factory, package = self.main_fixture(stack, [self.row(), row])
            target.check_access.side_effect = [manager.Failure("Login failed"), None]
            with self.assertRaisesRegex(manager.Failure, "Preflight failed"):
                manager.main(["install", "--yes"])
            self.assertEqual(2, factory.call_count, "All selected hosts must be checked")
            package.assert_not_called()
            target.install.assert_not_called()

    def test_missing_file_manager_key_is_actionable(self):
        with contextlib.ExitStack() as stack:
            target, _, package = self.main_fixture(stack)
            target.probe.return_value["file_manager_configured"] = False
            with self.assertRaisesRegex(manager.Failure, "--file-manager-key"):
                manager.main(["install", "--yes"])
            package.assert_not_called()

    def test_no_grants_refuses_missing_privileges_before_changes(self):
        with contextlib.ExitStack() as stack:
            target, _, package = self.main_fixture(stack)
            target.probe.return_value["grants"]["missing"] = {"api_web_waf_workers": ["INSERT"]}
            with self.assertRaisesRegex(manager.Failure, "grants are incomplete"):
                manager.main(["install", "--no-grants", "--yes"])
            package.assert_not_called()

    def test_release_archive_excludes_private_and_unlisted_files(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "source"
            source.mkdir()
            (source / ".env").write_text("PASSWORD=private")
            (source / "private-key").write_text("PRIVATE KEY")
            for name in manager.FILES:
                path = source / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text("reviewed source")
            output = root / "out"
            output.mkdir()
            args = self.args("--source", str(source))
            with patch.object(manager, "trusted", side_effect=lambda path: Path(path)):
                package = manager.package_release(args, output, [])
            with tarfile.open(package) as archive:
                self.assertEqual(set(manager.FILES) | {"SHA256SUMS"}, set(archive.getnames()))
                for item in archive.getmembers():
                    self.assertTrue(item.isfile())
                    self.assertEqual(0o600, item.mode)

    def test_arbitrary_public_commit_outside_configured_branch_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            def git(command, **kwargs):
                if "rev-parse" in command:
                    return b"1234567890123456789012345678901234567890\n"
                if "merge-base" in command:
                    raise manager.Failure("Not an ancestor of configured branch")
                return b""
            with patch.object(manager, "run", side_effect=git) as run, self.assertRaisesRegex(manager.Failure, "Not an ancestor"):
                manager.package_release(self.args(), Path(directory), ["sudo", "-u", "www-data"])
            commands = [call.args[0] for call in run.call_args_list]
            self.assertTrue(any(manager.REPOSITORY in command and "fetch" in command for command in commands))
            self.assertFalse(any("show" in command for command in commands), "No source is read before branch ancestry verification")


if __name__ == "__main__":
    unittest.main()
