import os
import pathlib
import tempfile
import unittest

from client.safedeploy import MARKER, build_bundle


class BundleTests(unittest.TestCase):
    def test_manifest_is_deterministic(self):
        with tempfile.TemporaryDirectory() as td:
            root = pathlib.Path(td)
            (root / "b.txt").write_text("B", encoding="utf-8")
            (root / "a.txt").write_text("A", encoding="utf-8")
            one = build_bundle(td, "apps/demo", "a" * 40)
            two = build_bundle(td, "apps/demo", "a" * 40)
            self.assertEqual(one["manifest_sha256"], two["manifest_sha256"])
            self.assertEqual(one["idempotency_key"], two["idempotency_key"])
            self.assertEqual(sorted(one["files"]), ["a.txt", "b.txt"])

    def test_symlink_is_rejected(self):
        with tempfile.TemporaryDirectory() as td:
            root = pathlib.Path(td)
            outside = root / "real.txt"
            outside.write_text("x", encoding="utf-8")
            os.symlink(outside, root / "link.txt")
            with self.assertRaises(SystemExit):
                build_bundle(td, "apps/demo", "b" * 40)

    def test_reserved_marker_is_rejected(self):
        with tempfile.TemporaryDirectory() as td:
            pathlib.Path(td, MARKER).write_text("forbidden", encoding="utf-8")
            with self.assertRaises(SystemExit):
                build_bundle(td, "apps/demo", "c" * 40)


if __name__ == "__main__":
    unittest.main()
