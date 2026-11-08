"""Packaging regression checks. Build the image first; see development/docker.md."""

import base64
import json
import os
from pathlib import Path
import subprocess
import time
import unittest
import urllib.error
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[2]
IMAGE = os.environ.get("OPENBOOK_TEST_IMAGE", "openbook:local")


def docker(*args):
    result = subprocess.run(
        ["docker", *args], cwd=ROOT, text=True, capture_output=True, timeout=60
    )
    if result.returncode:
        raise RuntimeError(result.stdout + result.stderr)
    return result.stdout


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class DockerImageTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.container = "openbook-image-test-" + uuid.uuid4().hex[:12]
        cls.addClassCleanup(
            subprocess.run,
            ["docker", "rm", "--force", cls.container],
            capture_output=True,
            timeout=30,
        )
        # No real database or instance data are used. The installation lock is absent.
        docker(
            "run", "--detach", "--name", cls.container,
            "--stop-signal", "SIGWINCH", "--entrypoint", "sh",
            "--publish", "127.0.0.1::80",
            "--env", "APP_KEY=base64:" + base64.b64encode(os.urandom(32)).decode(),
            "--env", "APP_URL=https://social.example.org",
            "--env", "OPENBOOK_DOMAIN=social.example.org",
            "--env", "DB_HOST=invalid", "--env", "DB_CONNECTION=sqlite",
            "--env", "DB_DATABASE=/var/www/html/storage/image-test.sqlite",
            "--env", "DB_USERNAME=unused", IMAGE, "-c",
            "if [ ! -f storage/image-test.sqlite ]; then "
            "install -o www-data -g www-data /dev/null storage/image-test.sqlite; fi; "
            "exec openbook-entrypoint apache2-foreground",
        )
        state = json.loads(docker("inspect", cls.container))[0]
        port = state["NetworkSettings"]["Ports"]["80/tcp"][0]["HostPort"]
        cls.url = "http://127.0.0.1:" + port
        cls.opener = urllib.request.build_opener(NoRedirect)
        cls.wait_ready()
        docker(
            "exec", cls.container, "php", "-r",
            'file_put_contents("storage/app/public/image-test.txt", "public"); '
            'file_put_contents("storage/app/public/image-test.php", "<?php echo 123;");',
        )

    @classmethod
    def wait_ready(cls):
        state = json.loads(docker("inspect", cls.container))[0]
        port = state["NetworkSettings"]["Ports"]["80/tcp"][0]["HostPort"]
        cls.url = "http://127.0.0.1:" + port
        for _ in range(100):
            try:
                if cls.status("/up") == 200:
                    break
            except (urllib.error.URLError, ConnectionError):
                pass
            time.sleep(0.2)
        else:
            raise RuntimeError("Container did not become ready:\n" + docker("logs", cls.container)[-6000:])

    @classmethod
    def status(cls, path):
        request = urllib.request.Request(cls.url + path, headers={"Host": "social.example.org"})
        try:
            with cls.opener.open(request, timeout=5) as response:
                return response.status
        except urllib.error.HTTPError as error:
            return error.code

    def test_installer_is_blocked_before_installation(self):
        for path in (
            "/install", "/install/database", "/index.php/install",
            "/index.php/install/database",
        ):
            with self.subTest(path=path):
                self.assertEqual(self.status(path), 403)

    def test_uploads_are_public_but_cannot_execute_php(self):
        self.assertEqual(self.status("/storage/image-test.txt"), 200)
        for path in ("/storage/image-test.php", "/storage/image-test.php/path"):
            with self.subTest(path=path):
                self.assertEqual(self.status(path), 403)

    def test_restart_migrates_and_clears_persistent_views(self):
        migration = r"""<?php
return new class extends Illuminate\Database\Migrations\Migration {
    public function up(): void {
        Illuminate\Support\Facades\DB::statement('CREATE TABLE docker_startup_review (id INTEGER)');
    }
    public function down(): void {
        Illuminate\Support\Facades\DB::statement('DROP TABLE docker_startup_review');
    }
};
"""
        encoded = base64.b64encode(migration.encode()).decode()
        docker(
            "exec", self.container, "php", "-r",
            'file_put_contents("database/migrations/9999_01_01_000000_docker_review.php", '
            'base64_decode("' + encoded + '")); '
            'file_put_contents("storage/framework/views/image-stale.php", "stale");',
        )
        for _ in range(2):
            docker("restart", "--time", "10", self.container)
            self.wait_ready()
            docker(
                "exec", self.container, "php", "-r",
                'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; '
                '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
                'if (!Illuminate\\Support\\Facades\\Schema::hasTable("docker_startup_review") '
                '|| file_exists("storage/framework/views/image-stale.php")) exit(1);',
            )

    def test_failed_migration_prevents_web_startup(self):
        name = "openbook-failed-start-" + uuid.uuid4().hex[:12]
        self.addCleanup(
            subprocess.run, ["docker", "rm", "--force", name],
            capture_output=True, timeout=30,
        )
        result = subprocess.run(
            ["docker", "run", "--rm", "--name", name, "--network", "none",
             "--env", "APP_KEY=base64:" + base64.b64encode(os.urandom(32)).decode(),
             "--env", "APP_URL=https://social.example.org",
             "--env", "OPENBOOK_DOMAIN=social.example.org",
             "--env", "DB_HOST=127.0.0.1", "--env", "DB_PORT=1",
             "--env", "DB_DATABASE=unused", "--env", "DB_USERNAME=unused", IMAGE],
            text=True, capture_output=True, timeout=15,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("SQLSTATE", result.stdout + result.stderr)

    def test_sensitive_project_files_are_not_served(self):
        for path in ("/.env", "/composer.json", "/storage/installed.lock"):
            with self.subTest(path=path):
                self.assertEqual(self.status(path), 404)

    def test_image_is_clean_and_cli_drops_root(self):
        docker(
            "run", "--rm", IMAGE, "php", "-r",
            'require "vendor/autoload.php"; '
            'if (posix_geteuid() !== 33 || file_exists(".env") '
            '|| file_exists("storage/installed.lock") || is_dir("tests") '
            '|| file_exists("bootstrap/cache/config.php") '
            '|| Composer\\InstalledVersions::isInstalled("phpunit/phpunit")) exit(1);',
        )


class ComposeTest(unittest.TestCase):
    def test_local_and_registry_services_match(self):
        # Resolve both files without loading any instance configuration.
        configs = []
        for filename in ("compose.local.yaml", "compose.yaml"):
            output = docker(
                "compose", "-f", filename, "--profile", "video", "config",
                "--no-env-resolution", "--format", "json",
            )
            configs.append(json.loads(output))
        local, registry = configs
        self.assertEqual(set(local["services"]), {"web", "cron", "inbox", "delivery", "video"})
        self.assertEqual(local["services"].keys(), registry["services"].keys())
        self.assertEqual(local["volumes"], registry["volumes"])
        for name in ("cron", "inbox", "delivery", "video"):
            self.assertEqual(registry["services"][name]["depends_on"]["web"]["condition"], "service_healthy")
        self.assertIn("healthcheck", registry["services"]["web"])
        for name, service in local["services"].items():
            with self.subTest(service=name):
                other = registry["services"][name].copy()
                service = service.copy()
                self.assertEqual(service.pop("image"), "openbook:local")
                self.assertEqual(service.pop("pull_policy"), "never")
                self.assertEqual(service.pop("build")["context"], str(ROOT))
                self.assertTrue(other.pop("image"))
                self.assertNotIn("build", other)
                self.assertEqual(service, other)


if __name__ == "__main__":
    unittest.main()
