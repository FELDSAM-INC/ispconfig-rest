"""Exercise the real updater against disposable Git origins, without host changes."""
import os
from pathlib import Path
import shlex
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]
MANAGER = (ROOT / "bin/ispconfig-rest").read_text()


class UpdaterTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        self.source = self.base / "source"
        self.checkout = self.base / "checkout"
        self.state = self.base / "install.conf"
        self.calls = self.base / "calls"
        self.env = dict(os.environ, GIT_CONFIG_GLOBAL=str(self.base / "gitconfig"),
                        GIT_CONFIG_NOSYSTEM="1", GIT_TERMINAL_PROMPT="0",
                        GIT_AUTHOR_NAME="Updater test", GIT_AUTHOR_EMAIL="test@example.invalid",
                        GIT_COMMITTER_NAME="Updater test", GIT_COMMITTER_EMAIL="test@example.invalid")
        self.git(self.base, "init", "--initial-branch=main", str(self.source))
        (self.source / "VERSION").write_text("1.0.0\n")
        self.commit("Initial release")
        self.git(self.source, "tag", "v1.0.0")
        self.git(self.source, "branch", "develop")
        self.git(self.base, "clone", "--depth", "1", "--branch", "develop",
                 self.source.as_uri(), str(self.checkout))
        # Tags created after the shallow install must become available on update.
        (self.source / "VERSION").write_text("1.0.1\n")
        self.commit("Stable release")
        self.git(self.source, "tag", "-a", "v1.0.1", "-m", "Release")
        self.git(self.source, "switch", "develop")
        (self.source / "development.txt").write_text("Development only\n")
        self.commit("Development progress")
        self.write_state("develop")
        self.bin = self.base / "bin"
        self.bin.mkdir()
        for name in ("composer", "php"):
            script = self.bin / name
            script.write_text("#!/bin/sh\nprintf '%s\\n' " + shlex.quote(name) + " >> " + shlex.quote(str(self.calls)) + "\n")
            script.chmod(0o755)
        self.env["PATH"] = str(self.bin) + os.pathsep + self.env["PATH"]

    def git(self, directory, *args, check=True):
        return subprocess.run(["git", "-C", str(directory), *args], env=self.env,
                              text=True, capture_output=True, check=check).stdout.strip()

    def commit(self, message):
        self.git(self.source, "add", ".")
        self.git(self.source, "commit", "-m", message)

    def write_state(self, branch):
        self.state.write_text("INSTALL_DIR=" + shlex.quote(str(self.checkout)) + "\n"
                              "RUN_USER=fixture\nPHP_BIN=php\nBRANCH=" + shlex.quote(branch) + "\n")

    def run_manager(self, *args, command=None, extra="", mock_helpers=True, check=True):
        # Keep all real function bodies, replacing only deployment paths and OS
        # integrations. Git fetch/checkout/state persistence run without mocks.
        code = MANAGER.split('case "${1:-}" in\n', 1)[0]
        code = code.replace('STATE_FILE="/etc/ispconfig-rest/install.conf"',
                            "STATE_FILE=" + shlex.quote(str(self.state)))
        code += '\nneed_root(){ :; }\nrun_as(){ (cd "$INSTALL_DIR" && "$@"); }\n'
        code += 'sync_timezone(){ :; }\ncmd_schedule_install(){ :; }\nreload_server(){ :; }\n'
        if mock_helpers:
            code += 'install_server_tools_manager(){ printf "%s\\n" "$RELEASE_REF" >> ' + shlex.quote(str(self.calls)) + '; }\n'
        code += extra + "\n"
        code += command or 'cmd_update "$@"\n'
        script = self.base / "manager.sh"
        script.write_text(code)
        result = subprocess.run(["bash", str(script), *args], env=self.env, text=True, capture_output=True)
        if check and result.returncode:
            self.fail(result.stdout + result.stderr)
        return result

    def assert_branch(self, branch):
        self.assertEqual(branch, self.git(self.checkout, "symbolic-ref", "--short", "HEAD"))
        self.assertEqual(self.git(self.source, "rev-parse", branch), self.git(self.checkout, "rev-parse", "HEAD"))
        self.assertIn('BRANCH="' + branch + '"', self.state.read_text())
        self.assertIn('RELEASE_REF="refs/heads/' + branch + '"', self.state.read_text())

    def switch_main_manually(self):
        self.git(self.checkout, "fetch", "origin", "refs/heads/main:refs/remotes/origin/main")
        self.git(self.checkout, "switch", "-c", "main", "refs/remotes/origin/main")

    def test_current_main_wins_over_saved_develop_and_updates_again(self):
        self.switch_main_manually()
        self.run_manager()
        self.assert_branch("main")
        self.git(self.source, "switch", "main")
        (self.source / "fix.txt").write_text("Next stable fix\n")
        self.commit("Stable fix")
        self.run_manager()
        self.assert_branch("main")

    def test_current_develop_updates_and_fetches_release_tags(self):
        self.write_state("main")
        (self.checkout / ".env").write_text("runtime settings\n")
        (self.checkout / ".composer").mkdir()
        (self.checkout / ".composer/config.json").write_text("{}\n")
        self.run_manager()
        self.assert_branch("develop")
        self.assertEqual(["v1.0.0", "v1.0.1"], self.git(self.checkout, "tag", "--list").splitlines())
        self.assertEqual("runtime settings\n", (self.checkout / ".env").read_text())
        self.assertEqual("{}\n", (self.checkout / ".composer/config.json").read_text())

    def test_detached_annotated_tag_stays_pinned_despite_saved_develop(self):
        self.git(self.checkout, "fetch", "origin", "tag", "v1.0.1")
        self.git(self.checkout, "checkout", "--detach", "v1.0.1")
        for _ in range(2):
            self.run_manager()
            self.assertEqual("HEAD", self.git(self.checkout, "rev-parse", "--abbrev-ref", "HEAD"))
            self.assertEqual(self.git(self.source, "rev-parse", "v1.0.1^{commit}"),
                             self.git(self.checkout, "rev-parse", "HEAD"))
            self.assertIn('RELEASE_REF="refs/tags/v1.0.1"', self.state.read_text())

    def test_explicit_main_from_single_branch_clone(self):
        self.run_manager("--branch", "main")
        self.assert_branch("main")
        self.assertFalse((self.checkout / "development.txt").exists())

    def test_explicit_release_from_clone_without_tag(self):
        self.assertNotIn("v1.0.1", self.git(self.checkout, "tag", "--list"))
        self.run_manager("--tag", "v1.0.1")
        self.assertEqual("v1.0.1", self.git(self.checkout, "describe", "--tags", "--exact-match"))
        self.assertEqual("HEAD", self.git(self.checkout, "rev-parse", "--abbrev-ref", "HEAD"))
        self.assertIn("refs/tags/v1.0.1", self.calls.read_text())

    def test_detached_release_commit_with_missing_local_tag_is_recognized_after_fetch(self):
        self.git(self.checkout, "fetch", "--no-tags", "origin", "refs/heads/main")
        self.git(self.checkout, "checkout", "--detach", "FETCH_HEAD")
        self.assertNotIn("v1.0.1", self.git(self.checkout, "tag", "--list"))
        self.run_manager()
        self.assertEqual("v1.0.1", self.git(self.checkout, "describe", "--tags", "--exact-match"))
        self.assertEqual("HEAD", self.git(self.checkout, "rev-parse", "--abbrev-ref", "HEAD"))

    def test_detached_nonrelease_commit_requires_explicit_selection(self):
        self.run_manager()
        self.git(self.checkout, "checkout", "--detach")
        before = self.git(self.checkout, "rev-parse", "HEAD")
        result = self.run_manager(check=False)
        self.assertNotEqual(0, result.returncode)
        self.assertIn("Detached HEAD is not a release tag", result.stderr)
        self.assertEqual(before, self.git(self.checkout, "rev-parse", "HEAD"))

    def test_modified_and_staged_files_are_preserved(self):
        for staged in (False, True):
            with self.subTest(staged=staged):
                (self.checkout / "VERSION").write_text("local edit\n")
                if staged:
                    self.git(self.checkout, "add", "VERSION")
                result = self.run_manager(check=False)
                self.assertNotEqual(0, result.returncode)
                self.assertIn("local changes", result.stderr)
                self.assertEqual("local edit\n", (self.checkout / "VERSION").read_text())
                self.assertFalse(self.calls.exists())

    def test_local_commits_are_not_reset(self):
        (self.checkout / "local.txt").write_text("local\n")
        self.git(self.checkout, "add", ".")
        self.git(self.checkout, "commit", "-m", "Local commit")
        before = self.git(self.checkout, "rev-parse", "HEAD")
        result = self.run_manager(check=False)
        self.assertNotEqual(0, result.returncode)
        self.assertIn("diverged", result.stderr)
        self.assertEqual(before, self.git(self.checkout, "rev-parse", "HEAD"))

    def test_unknown_branch_tag_and_invalid_options_do_not_change_checkout_or_state(self):
        before = self.git(self.checkout, "rev-parse", "HEAD")
        state = self.state.read_bytes()
        options = [("--branch", "missing"), ("--tag", "v9.9.9"), ("--branch",),
                   ("--branch", "main", "--tag", "v1.0.1"), ("--force",),
                   ("--branch", "$(id)"), ("--branch", "../main"), ("--tag", "HEAD")]
        for args in options:
            with self.subTest(args=args):
                result = self.run_manager(*args, check=False)
                self.assertNotEqual(0, result.returncode)
                self.assertEqual(before, self.git(self.checkout, "rev-parse", "HEAD"))
                self.assertEqual(state, self.state.read_bytes())
                self.assertFalse(self.calls.exists())

    def test_moved_release_tag_is_not_overwritten(self):
        self.run_manager("--tag", "v1.0.1")
        before = self.git(self.checkout, "rev-parse", "HEAD")
        self.git(self.source, "tag", "-f", "v1.0.1", "develop")
        result = self.run_manager(check=False)
        self.assertNotEqual(0, result.returncode)
        self.assertEqual(before, self.git(self.checkout, "rev-parse", "HEAD"))

    def test_deleted_release_tag_cannot_be_used_from_local_cache(self):
        self.run_manager("--tag", "v1.0.1")
        self.git(self.source, "tag", "-d", "v1.0.1")
        result = self.run_manager(check=False)
        self.assertNotEqual(0, result.returncode)
        self.assertIn("not found on origin", result.stderr)

    def test_version_reports_actual_branch_or_tag_instead_of_saved_branch(self):
        self.switch_main_manually()
        self.assertIn("on branch main", self.run_manager(command="cmd_version").stdout)
        self.git(self.checkout, "fetch", "origin", "tag", "v1.0.1")
        self.git(self.checkout, "checkout", "--detach", "v1.0.1")
        self.assertIn("at release v1.0.1 (detached, pinned)", self.run_manager(command="cmd_version").stdout)

    def test_help_does_not_update(self):
        result = self.run_manager("--help")
        self.assertIn("--branch NAME | --tag VERSION", result.stdout)
        self.assertFalse(self.calls.exists())

    def test_manager_refresh_uses_verified_sources_and_preserves_new_updater_for_old_release(self):
        # Map only the hardcoded official HTTPS fetch into the disposable origin;
        # retain the real ancestry check and read blobs from the real bare clone.
        stage = self.base / "trusted"
        installed = self.base / "installed"
        installed.mkdir()
        current = installed / "ispconfig-rest"
        current.write_text("current manager")
        fake_git = '''git(){
  local args=() arg
  for arg in "$@"; do
    case "$arg" in
      protocol.file.allow=never) args+=(protocol.file.allow=always);;
      https://github.com/FELDSAM-INC/ispconfig-rest.git) args+=("$FIXTURE_ORIGIN");;
      *) args+=("$arg");;
    esac
  done
  command git "${args[@]}"
}
mktemp(){ mkdir "$FIXTURE_STAGE"; printf '%s\\n' "$FIXTURE_STAGE"; }
install(){
  local args=() arg
  for arg in "$@"; do
    case "$arg" in
      /usr/local/lib/ispconfig-rest-cli|/usr/local/lib/ispconfig-rest-cli/) args+=("$FIXTURE_INSTALLED/helpers");;
      /usr/local/bin/ispconfig-rest) args+=("$FIXTURE_INSTALLED/ispconfig-rest");;
      *) args+=("$arg");;
    esac
  done
  # Ignore ownership flags so this test works as an unprivileged CI user.
  local filtered=() i=0
  while [ "$i" -lt "${#args[@]}" ]; do
    case "${args[$i]}" in
      -o|-g) i=$((i + 2));;
      *) filtered+=("${args[$i]}"); i=$((i + 1));;
    esac
  done
  command install "${filtered[@]}"
}
'''
        self.env.update(FIXTURE_ORIGIN=self.source.as_uri(), FIXTURE_STAGE=str(stage), FIXTURE_INSTALLED=str(installed))
        for modern in (False, True):
            with self.subTest(modern=modern):
                (self.source / "bin").mkdir(exist_ok=True)
                manager = "#!/bin/bash\n" + ("# release-aware-updater-v1: fixture\n" if modern else "# Old manager\n")
                (self.source / "bin/ispconfig-rest").write_text(manager)
                (self.source / "server-tools").mkdir(exist_ok=True)
                for name in ("manage.py", "remote.php"):
                    (self.source / "server-tools" / name).write_text("verified helper")
                self.commit("Manager fixture")
                self.run_manager()
                result = self.run_manager(command="install_server_tools_manager --refresh-manager", extra=fake_git, mock_helpers=False)
                self.assertEqual(manager if modern else "current manager", current.read_text())
                self.assertEqual("verified helper", (installed / "helpers/manage.py").read_text())
                self.assertFalse(stage.exists())
                if not modern:
                    self.assertIn("Keeping the current manager", result.stdout)
        # Even if a web-owned checkout is tampered with, its code cannot become
        # the root-executed manager or helpers without official ref ancestry.
        (self.checkout / "server-tools/manage.py").write_text("untrusted")
        self.git(self.checkout, "add", ".")
        self.git(self.checkout, "commit", "-m", "Untrusted local commit")
        result = self.run_manager(command="install_server_tools_manager --refresh-manager", extra=fake_git,
                                  mock_helpers=False, check=False)
        self.assertNotEqual(0, result.returncode)
        self.assertEqual("verified helper", (installed / "helpers/manage.py").read_text())
        self.assertEqual(manager, current.read_text())


if __name__ == "__main__":
    unittest.main()
