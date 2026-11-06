"""Exercise the real updater against disposable Git origins, without host changes."""
import json
import os
from pathlib import Path
import shlex
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]
MANAGER = (ROOT / "bin/ispconfig-rest").read_text()
REAL_PHP = shutil.which("php")
HOSTING = "https://github.com/FELDSAM-INC/ispconfig-rest-hosting.git"


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
            # The manager reads verified extension.json files with `php -r`; everything else is recorded only.
            passthrough = ('[ "$1" = -r ] && exec ' + shlex.quote(REAL_PHP) + ' "$@"\n') if name == "php" and REAL_PHP else ""
            record = "printf '%s\\n' \"" + name + " $*\" >> " + shlex.quote(str(self.calls)) + "\n"
            if name == "php":
                # FIXTURE_PENDING: `migrate:status --pending=1` reports pending migrations. A migration records where
                # it ran, with which login, and the private .env and extension files it saw.
                record += ('case "$*" in\n'
                           '  *"artisan migrate:status"*) [ -z "${FIXTURE_PENDING:-}" ] || exit 1;;\n'
                           '  *"artisan migrate --force"*) printf "migrate-env %s|%s|%s|%s|%s\\n" "$PWD" "${DB_USERNAME:-}" "${DB_PASSWORD:-}" '
                           '"${API_EXTENSIONS_PATH:-}" "$(cat extensions/hosting/tool.py 2>/dev/null)" >> ' + shlex.quote(str(self.calls)) + '\n'
                           '    [ ! -f .env ] || cp .env ' + shlex.quote(str(self.base / "migrate.env")) + ';;\n'
                           'esac\n')
            else:
                record += "printf 'composer-cwd %s\\n' \"$PWD\" >> " + shlex.quote(str(self.calls)) + "\n"
            script.write_text("#!/bin/sh\n" + passthrough + record)
            script.chmod(0o755)
        self.cli_lib = self.base / "cli-lib"
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
        code = code.replace('CLI_LIB="/usr/local/lib/ispconfig-rest-cli"', "CLI_LIB=" + shlex.quote(str(self.cli_lib)))
        code += '\nneed_root(){ :; }\nrun_as(){ (cd "$INSTALL_DIR" && "$@"); }\n'
        code += 'sync_timezone(){ :; }\ncmd_schedule_install(){ :; }\nreload_server(){ :; }\n'
        if mock_helpers:
            code += 'refresh_manager(){ printf "%s\\n" "$RELEASE_REF" >> ' + shlex.quote(str(self.calls)) + '; }\n'
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

    def test_registered_channel_wins_until_root_explicitly_selects_main(self):
        self.switch_main_manually()
        self.run_manager()
        self.assert_branch("develop")
        self.run_manager("--branch", "main")
        self.assert_branch("main")
        self.git(self.source, "switch", "main")
        (self.source / "fix.txt").write_text("Next stable fix\n")
        self.commit("Stable fix")
        self.run_manager()
        self.assert_branch("main")

    def test_explicit_develop_updates_and_fetches_release_tags(self):
        self.write_state("main")
        (self.checkout / ".env").write_text("runtime settings\n")
        (self.checkout / ".composer").mkdir()
        (self.checkout / ".composer/config.json").write_text("{}\n")
        self.run_manager("--branch", "develop")
        self.assert_branch("develop")
        self.assertEqual(["v1.0.0", "v1.0.1"], self.git(self.checkout, "tag", "--list").splitlines())
        self.assertEqual("runtime settings\n", (self.checkout / ".env").read_text())
        self.assertEqual("{}\n", (self.checkout / ".composer/config.json").read_text())

    def test_registered_annotated_tag_stays_pinned(self):
        self.git(self.checkout, "fetch", "origin", "tag", "v1.0.1")
        self.git(self.checkout, "checkout", "--detach", "v1.0.1")
        with self.state.open("a") as stream:
            stream.write('RELEASE_REF="refs/tags/v1.0.1"\n')
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

    def test_repeated_old_depth_one_fetches_do_not_look_like_local_divergence(self):
        self.git(self.checkout, "fetch", "--depth", "1", "origin", "develop")
        (self.source / "next.txt").write_text("Next development commit\n")
        self.commit("Newer development commit")
        self.assertEqual("true", self.git(self.checkout, "rev-parse", "--is-shallow-repository"))
        self.run_manager()
        self.assert_branch("develop")
        self.assertEqual("false", self.git(self.checkout, "rev-parse", "--is-shallow-repository"))

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
        with self.state.open("a") as stream:
            stream.write('RELEASE_REF="refs/tags/v1.0.1"\n')
        self.run_manager()
        self.assertEqual("v1.0.1", self.git(self.checkout, "describe", "--tags", "--exact-match"))
        self.assertEqual("HEAD", self.git(self.checkout, "rev-parse", "--abbrev-ref", "HEAD"))

    def test_runtime_detachment_does_not_change_registered_channel(self):
        self.run_manager()
        self.git(self.checkout, "checkout", "--detach")
        self.run_manager()
        self.assert_branch("develop")

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

    FAKE_GIT = '''git(){
  local args=() arg
  for arg in "$@"; do
    case "$arg" in
      protocol.file.allow=never) args+=(protocol.file.allow=always);;
      https://github.com/FELDSAM-INC/ispconfig-rest.git) args+=("$FIXTURE_ORIGIN");;
      https://github.com/FELDSAM-INC/ispconfig-rest-hosting.git) args+=("$FIXTURE_EXTENSION_ORIGIN");;
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

    def trusted_fixtures(self):
        stage = self.base / "trusted"
        installed = self.base / "installed"
        installed.mkdir(exist_ok=True)
        self.env.update(FIXTURE_ORIGIN=self.source.as_uri(), FIXTURE_STAGE=str(stage), FIXTURE_INSTALLED=str(installed))
        return stage, installed

    def test_manager_refresh_uses_verified_sources_and_preserves_new_updater_for_old_release(self):
        # Map only the hardcoded official HTTPS fetch into the disposable origin;
        # retain the real ancestry check and read blobs from the real bare clone.
        stage, installed = self.trusted_fixtures()
        current = installed / "ispconfig-rest"
        current.write_text("current manager")
        self.cli_lib.mkdir()
        (self.cli_lib / "manage.py").write_text("helper of a manager before 1.1.0")
        for modern in (False, True):
            with self.subTest(modern=modern):
                (self.source / "bin").mkdir(exist_ok=True)
                manager = "#!/bin/bash\n" + ("# release-aware-updater-v1: fixture\n" if modern else "# Old manager\n")
                (self.source / "bin/ispconfig-rest").write_text(manager)
                self.commit("Manager fixture")
                self.run_manager()
                result = self.run_manager(command="refresh_manager", extra=self.FAKE_GIT, mock_helpers=False)
                self.assertEqual(manager if modern else "current manager", current.read_text())
                self.assertFalse((self.cli_lib / "manage.py").exists())
                self.assertFalse(stage.exists())
                if not modern:
                    self.assertIn("Keeping the current manager", result.stdout)
        # Even if a web-owned checkout is tampered with, its code cannot become
        # the root-executed manager without official ref ancestry.
        (self.checkout / "bin/ispconfig-rest").write_text("#!/bin/bash\n# release-aware-updater-v1: untrusted\n")
        self.git(self.checkout, "add", ".")
        self.git(self.checkout, "commit", "-m", "Untrusted local commit")
        result = self.run_manager(command="refresh_manager", extra=self.FAKE_GIT, mock_helpers=False, check=False)
        self.assertNotEqual(0, result.returncode)
        self.assertEqual(manager, current.read_text())

    def test_runtime_origin_cannot_roll_back_the_privileged_manager(self):
        stage, installed = self.trusted_fixtures()
        (self.source / "bin").mkdir()
        manager = self.source / "bin/ispconfig-rest"
        manager.write_text("#!/bin/bash\n# release-aware-updater-v1: old\n")
        self.commit("Older published manager")
        old = self.git(self.source, "rev-parse", "HEAD")
        self.run_manager()
        manager.write_text("#!/bin/bash\n# release-aware-updater-v1: new\n")
        self.commit("Current published manager")
        current = installed / "ispconfig-rest"
        current.write_text(manager.read_text())
        stale = self.base / "stale-origin"
        self.git(self.base, "clone", self.source.as_uri(), str(stale))
        self.git(stale, "reset", "--hard", old)
        self.git(self.checkout, "remote", "set-url", "origin", stale.as_uri())
        result = self.run_manager(extra=self.FAKE_GIT, mock_helpers=False, check=False)
        self.assertNotEqual(0, result.returncode)
        self.assertEqual(manager.read_text(), current.read_text())
        self.assertFalse(stage.exists())

    def extension_origin(self):
        origin = self.base / "hosting"
        self.git(self.base, "init", "--initial-branch=main", str(origin))
        (origin / "bin").mkdir()
        entry = origin / "bin/ispconfig-rest-extension"
        entry.write_text("#!/bin/sh\n"
                         "case \"$1\" in --handles) [ \"$2\" = demo:run ]; exit;; --help) echo '  ispconfig-rest demo:run'; exit 0;; esac\n"
                         "printf '%s|%s|%s\\n' \"$*\" \"$ISPCONFIG_REST_EXTENSION_DIR\" \"$ISPCONFIG_REST_EXTENSION_REF\"\n")
        entry.chmod(0o755)
        (origin / "tool.py").write_text("verified tool\n")
        (origin / "extension.json").write_text(json.dumps({"name": "hosting", "version": "1.0.0",
                                                           "cli": ["bin/ispconfig-rest-extension", "tool.py"]}))
        self.git(origin, "add", ".")
        self.git(origin, "commit", "-m", "Extension release")
        self.git(origin, "tag", "-a", "v1.0.0", "-m", "Release")
        self.env["FIXTURE_EXTENSION_ORIGIN"] = origin.as_uri()
        return origin

    def run_extension(self, *args, check=True):
        clone = ('run_as(){ (cd "$INSTALL_DIR" && if [ "$1" = git ] && [ "$2" = clone ]; then '
                 'shift 2; set -- git clone "${@/' + HOSTING.replace("/", "\\/") + '/$FIXTURE_EXTENSION_ORIGIN}"; fi; "$@"); }\n')
        return self.run_manager(*args, command='case "$1" in extension:*) c="cmd_${1//:/_}"; shift; "${c//-/_}" "$@";; '
                                '*) dispatch_extension_command "$@";; esac\n', extra=self.FAKE_GIT + clone, check=check)

    @unittest.skipUnless(REAL_PHP, "php is required to read extension.json")
    def test_extension_install_update_and_verified_command_files(self):
        self.trusted_fixtures()
        origin = self.extension_origin()
        self.run_extension("extension:install", "hosting", "--tag", "v1.0.0")
        state = self.state.read_text()
        self.assertIn('EXTENSIONS="hosting"', state)
        self.assertIn('EXTENSION_HOSTING_REPOSITORY="' + HOSTING + '"', state)
        self.assertIn('EXTENSION_HOSTING_REF="refs/tags/v1.0.0"', state)
        self.assertEqual(self.git(origin, "rev-parse", "v1.0.0^{commit}"), self.git(self.checkout / "extensions/hosting", "rev-parse", "HEAD"))
        self.assertEqual("verified tool\n", (self.cli_lib / "extensions/hosting/tool.py").read_text())
        calls = self.calls.read_text()
        self.assertIn("php artisan extension:list hosting", calls)
        # Nothing pending: no migration at all, and never as the runtime user.
        self.assertIn("php artisan migrate:status --pending=1", calls)
        self.assertNotIn("php artisan migrate --force", calls)

        # Commands this manager does not know run from the verified copy, with the extension's checkout and ref.
        result = self.run_extension("demo:run", "--flag")
        self.assertEqual("demo:run --flag|" + str(self.checkout / "extensions/hosting") + "|refs/tags/v1.0.0", result.stdout.strip())
        self.assertNotEqual(0, self.run_extension("unknown:command", check=False).returncode)

        # A switch to the branch follows new commits; the command files are copied again.
        (origin / "tool.py").write_text("next tool\n")
        self.git(origin, "commit", "-am", "Next")
        self.run_extension("extension:update", "hosting", "--branch", "main")
        self.assertIn('EXTENSION_HOSTING_REF="refs/heads/main"', self.state.read_text())
        self.assertEqual("next tool\n", (self.cli_lib / "extensions/hosting/tool.py").read_text())

        # A local commit in the web-owned checkout never becomes root-executed code.
        (self.checkout / "extensions/hosting/tool.py").write_text("untrusted\n")
        self.git(self.checkout / "extensions/hosting", "commit", "-am", "Untrusted")
        result = self.run_manager(command="install_extension_cli hosting", extra=self.FAKE_GIT, check=False)
        self.assertNotEqual(0, result.returncode)
        self.assertEqual("next tool\n", (self.cli_lib / "extensions/hosting/tool.py").read_text())

        self.run_extension("extension:remove", "hosting")
        self.assertFalse((self.checkout / "extensions/hosting").exists())
        self.assertFalse((self.cli_lib / "extensions/hosting").exists())
        self.assertNotIn("EXTENSION_HOSTING", self.state.read_text())

    @unittest.skipUnless(REAL_PHP, "php is required to read extension.json")
    def test_extension_rejects_unknown_names_bad_repositories_and_unsafe_command_files(self):
        self.trusted_fixtures()
        origin = self.extension_origin()
        self.assertIn("Unknown extension other", self.run_extension("extension:install", "other", check=False).stderr)
        self.assertIn("https:// Git URL", self.run_extension("extension:install", "other", "--repository", "file:///tmp/x.git", check=False).stderr)
        self.assertFalse((self.checkout / "extensions/other").exists())
        (origin / "extension.json").write_text(json.dumps({"name": "hosting", "cli": ["../outside"]}))
        self.git(origin, "commit", "-am", "Unsafe")
        result = self.run_extension("extension:install", "hosting", check=False)
        self.assertNotEqual(0, result.returncode)
        self.assertFalse((self.cli_lib / "extensions/hosting").exists())

    def test_core_update_also_updates_extensions_on_their_channel(self):
        if not REAL_PHP:
            self.skipTest("php is required to read extension.json")
        self.trusted_fixtures()
        origin = self.extension_origin()
        self.run_extension("extension:install", "hosting")
        (origin / "tool.py").write_text("update tool\n")
        self.git(origin, "commit", "-am", "Update")
        self.run_manager(extra=self.FAKE_GIT + 'refresh_manager(){ :; }\n', mock_helpers=False)
        self.assertEqual(self.git(origin, "rev-parse", "main"), self.git(self.checkout / "extensions/hosting", "rev-parse", "HEAD"))
        self.assertEqual("update tool\n", (self.cli_lib / "extensions/hosting/tool.py").read_text())

    def privileged_fixtures(self):
        """A trusted core and hosting install, ISPConfig's configuration and the MariaDB maintenance login."""
        stage, _ = self.trusted_fixtures()
        origin = self.extension_origin()
        self.run_manager()
        self.run_extension("extension:install", "hosting", "--tag", "v1.0.0")
        config = self.base / "config.inc.php"
        config.write_text("<?php\n$conf['db_host'] = 'db.example.test';\n$conf['db_port'] = '3307';\n$conf['db_database'] = 'dbispconfig';\n"
                          "$conf['db_password'] = 'runtime-secret';\n")
        config.chmod(0o640)
        maintenance = self.base / "debian.cnf"
        maintenance.write_text("[client]\nhost     = localhost\nuser     = maint-admin\npassword = \"p@ss word;#1\"\nsocket   = /run/mysqld/mysqld.sock\n"
                               "[mysql_upgrade]\nuser = other\n")
        maintenance.chmod(0o600)
        self.env["FIXTURE_PENDING"] = "1"
        self.calls.unlink()
        paths = 'ISPCONFIG_CONFIG=' + shlex.quote(str(config)) + '\nMYSQL_MAINTENANCE=' + shlex.quote(str(maintenance)) + '\n'
        return stage, origin, paths

    def privileged(self, command, paths, check=True):
        return self.run_manager(command=command, extra=self.FAKE_GIT + paths, check=check)

    @unittest.skipUnless(REAL_PHP, "php is required for the private login and database readers")
    def test_pending_migrations_run_privileged_from_verified_sources_only(self):
        stage, origin, paths = self.privileged_fixtures()
        self.privileged("apply_release", paths)
        calls = self.calls.read_text()
        app = str(stage / "app")
        # Verified core and extension sources, private dependencies, the maintenance login and ISPConfig's database.
        self.assertIn("migrate-env " + app + "|maint-admin|p@ss word;#1|" + app + "/extensions|verified tool\n", calls)
        self.assertIn("composer install --no-dev --no-plugins --no-scripts --optimize-autoloader --no-interaction --no-progress --quiet", calls)
        self.assertIn("composer-cwd " + app, calls)
        environment = (self.base / "migrate.env").read_text()
        for line in ("DB_HOST=db.example.test", "DB_PORT=3307", "DB_DATABASE=dbispconfig", "DB_CONNECTION=mysql"):
            self.assertIn(line + "\n", environment)
        self.assertNotIn("runtime-secret", environment)
        self.assertNotIn("DB_PASSWORD", environment, "the privileged password never lands in a file")
        self.assertFalse(stage.exists(), "the private stage is removed")
        self.assertEqual(1, calls.count("migrate-env "), "no second migration as the runtime user")

        # Options win over the maintenance login; registered database settings over ISPConfig's file.
        secret = self.base / "admin-password"
        secret.write_text("from-file\n")
        secret.chmod(0o600)
        self.state.write_text(self.state.read_text() + 'DB_HOST="10.0.0.5"\nDB_PORT="3306"\nDB_NAME="registered"\n')
        self.calls.unlink()
        self.privileged('parse_admin --db-admin-user ops --db-admin-pass-file ' + shlex.quote(str(secret)) + '\napply_release', paths)
        self.assertIn("|ops|from-file|", self.calls.read_text())
        self.assertIn("DB_HOST=10.0.0.5\n", (self.base / "migrate.env").read_text())
        self.assertIn("DB_DATABASE=registered\n", (self.base / "migrate.env").read_text())

        # Nothing pending: no privileged step.
        del self.env["FIXTURE_PENDING"]
        self.calls.unlink()
        self.privileged("apply_release", paths)
        self.assertNotIn("migrate-env", self.calls.read_text())

    @unittest.skipUnless(REAL_PHP, "php is required for the private login and database readers")
    def test_privileged_migrations_refuse_unverified_code_and_unsafe_logins(self):
        stage, origin, paths = self.privileged_fixtures()
        # A local commit in the web-owned extension checkout is never migrated as root.
        (self.checkout / "extensions/hosting/tool.py").write_text("untrusted\n")
        self.git(self.checkout / "extensions/hosting", "commit", "-am", "Untrusted")
        result = self.privileged("apply_release", paths, check=False)
        self.assertNotEqual(0, result.returncode)
        self.assertIn("Migrations failed", result.stderr)
        self.assertNotIn("migrate-env", self.calls.read_text())
        self.assertFalse(stage.exists())
        self.git(self.checkout / "extensions/hosting", "reset", "--hard", "HEAD~1")

        # A readable password file, a password argument and a composer others may change are refused.
        public = self.base / "public-password"
        public.write_text("secret\n")
        public.chmod(0o644)
        result = self.privileged("parse_admin --db-admin-pass-file " + shlex.quote(str(public)) + "\napply_release", paths, check=False)
        self.assertIn("private regular file", result.stderr)
        result = self.privileged("parse_admin --db-admin-pass secret", paths, check=False)
        self.assertIn("Password-valued arguments are not supported", result.stderr)
        self.assertNotIn("secret", result.stdout + result.stderr.replace("--db-admin-pass-file", ""))
        (self.bin / "composer").chmod(0o777)
        result = self.privileged("apply_release", paths, check=False)
        self.assertIn("composer owned by root", result.stderr)
        self.assertNotIn("migrate-env", self.calls.read_text())

    @unittest.skipUnless(REAL_PHP, "php is required for the private login and database readers")
    def test_update_and_extension_update_accept_the_privileged_login_options(self):
        stage, origin, paths = self.privileged_fixtures()
        secret = self.base / "admin-password"
        secret.write_text("from-file\n")
        secret.chmod(0o600)
        self.run_manager("--db-admin-pass-file", str(secret), "--tag", "v1.0.1", extra=self.FAKE_GIT + paths + 'refresh_manager(){ :; }\n', mock_helpers=False)
        self.assertIn("|root|from-file|", self.calls.read_text())
        self.assertIn('RELEASE_REF="refs/tags/v1.0.1"', self.state.read_text())
        self.calls.unlink()
        self.run_manager(command='cmd_extension_update hosting --db-admin-user ops --db-admin-pass-file ' + shlex.quote(str(secret)),
                         extra=self.FAKE_GIT + paths)
        self.assertIn("|ops|from-file|", self.calls.read_text())


if __name__ == "__main__":
    unittest.main()
