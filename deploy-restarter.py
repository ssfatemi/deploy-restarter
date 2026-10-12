#!/usr/bin/env python3
"""
deploy_plugin.py
Upload a WordPress plugin over FTPS, then restart it via the
Deploy Restarter REST API.
"""

import os
import ssl
import json
import time
import ftplib
import posixpath
from pathlib import Path
from datetime import datetime
from urllib.request import Request, urlopen
from urllib.error import HTTPError, URLError


# =====================================================================
# ============  1. FTP CONFIGURATION  =================================
# =====================================================================

FTP_HOST     = "----"          # FTP server hostname
FTP_PORT     = 21                           # FTP port
FTP_USER     = "----"              # FTP username
FTP_PASSWORD = "----"          # FTP password

# Local folder on your computer that contains your plugin files.
LOCAL_PLUGIN = Path(r"----")

# Remote WordPress plugins directory (absolute path on the server).
REMOTE_PLUGINS = "/public_html/wp-content/plugins"


# =====================================================================
# ============  2. REST API CONFIGURATION  ============================
# =====================================================================

# Full URL to the Deploy Restarter restart endpoint.
REST_API_URL = "----"

# The API key you copied from:  WP Admin -> Settings -> Deploy Restarter
REST_API_KEY = "----"

# Name of the plugin to restart. This is what the WP plugin resolves.
# Usually the plugin folder name, e.g. "my-plugin".
# If your plugin's main file has a different name, use "folder/main-file.php".
PLUGIN_SLUG = "----"

# TLS verification for the REST call. Set False ONLY for local testing.
VERIFY_TLS = True

# Retry behaviour for the REST call.
REST_TIMEOUT       = 30     # seconds per request
RESTART_RETRIES    = 3      # total attempts
RESTART_RETRY_WAIT = 2      # seconds between attempts


# =====================================================================
# ============  3. FTP HELPERS  =======================================
# =====================================================================

def remote_dir_exists(ftp, path):
    original = ftp.pwd()
    try:
        ftp.cwd(path)
        return True
    except ftplib.error_perm:
        return False
    finally:
        ftp.cwd(original)


def ensure_remote_dir(ftp, path):
    """Create a remote directory and its parents if necessary."""
    path = path.replace("\\", "/")
    current = "/" if path.startswith("/") else ""

    for part in path.strip("/").split("/"):
        if not part:
            continue
        current = posixpath.join(current, part) if current else part
        if not remote_dir_exists(ftp, current):
            ftp.mkd(current)


def upload_directory(ftp, local_dir, remote_dir):
    """Recursively upload every file and subdirectory."""
    ensure_remote_dir(ftp, remote_dir)

    for root, dirs, files in os.walk(local_dir):
        relative = Path(root).relative_to(local_dir)

        current_remote = remote_dir
        if str(relative) != ".":
            current_remote = posixpath.join(remote_dir, *relative.parts)

        ensure_remote_dir(ftp, current_remote)

        for filename in files:
            local_file  = Path(root) / filename
            remote_file = posixpath.join(current_remote, filename)

            print(f"  uploading {local_file}  ->  {remote_file}")

            with open(local_file, "rb") as fh:
                ftp.storbinary(f"STOR {remote_file}", fh, blocksize=64 * 1024)


# =====================================================================
# ============  4. REST API CALL  =====================================
# =====================================================================

def restart_plugin_via_api(plugin_slug):
    """
    POST to the WordPress Deploy Restarter endpoint to restart the plugin.

    Sends:
        Header:  X-API-Key: <REST_API_KEY>
        Body:    {"plugin": "<plugin_slug>"}

    Returns True on success, raises Exception on failure.
    """
    if not REST_API_URL or not REST_API_KEY or REST_API_KEY == "PASTE_YOUR_API_KEY_HERE":
        raise RuntimeError(
            "REST_API_URL / REST_API_KEY are not configured at the top of this file."
        )

    # ---- Build request body and headers --------------------------------
    payload = json.dumps({"plugin": plugin_slug}).encode("utf-8")

    headers = {
        "Content-Type":  "application/json",
        "Accept":        "application/json",
        "X-API-Key":     REST_API_KEY,
        "Authorization": f"Bearer {REST_API_KEY}",   # also accepted by the plugin
        "User-Agent":    "DeployRestarterPython/1.0",
    }

    # ---- TLS context ---------------------------------------------------
    context = ssl.create_default_context()
    if not VERIFY_TLS:
        context.check_hostname = False
        context.verify_mode    = ssl.CERT_NONE

    # ---- Retry loop ----------------------------------------------------
    last_error = None

    for attempt in range(1, RESTART_RETRIES + 1):
        print(f"[API] attempt {attempt}/{RESTART_RETRIES} -> POST {REST_API_URL}")
        print(f"[API] body: {payload.decode()}")

        try:
            request = Request(
                REST_API_URL,
                data=payload,
                headers=headers,
                method="POST",
            )

            with urlopen(request, context=context, timeout=REST_TIMEOUT) as response:
                status = response.getcode()
                body   = response.read().decode("utf-8", errors="replace")

            print(f"[API] HTTP {status}")
            print(f"[API] response: {body}")

            try:
                data = json.loads(body)
            except json.JSONDecodeError:
                raise RuntimeError(f"Non-JSON response from server: {body!r}")

            if data.get("ok") is True:
                print("[API] plugin restart confirmed by WordPress.")
                return True

            raise RuntimeError(f"API reported failure: {data}")

        except HTTPError as e:
            error_body = e.read().decode("utf-8", errors="replace")
            print(f"[API] HTTP error {e.code}: {error_body}")
            last_error = RuntimeError(f"HTTP {e.code}: {error_body}")

        except URLError as e:
            print(f"[API] URL error: {e.reason}")
            last_error = e

        except Exception as e:
            print(f"[API] unexpected error: {e}")
            last_error = e

        if attempt < RESTART_RETRIES:
            print(f"[API] retrying in {RESTART_RETRY_WAIT}s...")
            time.sleep(RESTART_RETRY_WAIT)

    raise RuntimeError(
        f"REST API call failed after {RESTART_RETRIES} attempts: {last_error}"
    )


# =====================================================================
# ============  5. MAIN DEPLOY  =======================================
# =====================================================================

def deploy_plugin():
    if not LOCAL_PLUGIN.is_dir():
        raise FileNotFoundError(f"Local plugin folder not found: {LOCAL_PLUGIN}")

    # The plugin slug the API will use. Defaults to the local folder name,
    # but PLUGIN_SLUG (set above) takes precedence if it's non-empty.
    plugin_name = PLUGIN_SLUG.strip() or LOCAL_PLUGIN.name

    target  = posixpath.join(REMOTE_PLUGINS, plugin_name)

    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    staging   = posixpath.join(REMOTE_PLUGINS, f".{plugin_name}.uploading_{timestamp}")
    backup    = posixpath.join(REMOTE_PLUGINS, f".{plugin_name}.backup_{timestamp}")

    print("=" * 70)
    print(f" Plugin name : {plugin_name}")
    print(f" Local source: {LOCAL_PLUGIN}")
    print(f" Remote dest : {target}")
    print(f" Staging     : {staging}")
    print(f" Backup      : {backup}")
    print(f" API endpoint: {REST_API_URL}")
    print("=" * 70)

    # ---------- 1. UPLOAD over FTPS -------------------------------------
    ftp = ftplib.FTP_TLS(timeout=60)
    try:
        print(f"[FTP] connecting to {FTP_HOST}:{FTP_PORT} ...")
        ftp.connect(FTP_HOST, FTP_PORT)
        ftp.login(FTP_USER, FTP_PASSWORD)
        ftp.prot_p()
        ftp.set_pasv(True)
        print("[FTP] connected, TLS-protected.")

        print("[FTP] uploading to staging ...")
        upload_directory(ftp, LOCAL_PLUGIN, staging)
        print("[FTP] upload complete.")

        # ---------- 2. BACKUP existing plugin ---------------------------
        old_moved = False
        if remote_dir_exists(ftp, target):
            print(f"[FTP] backing up existing plugin -> {backup}")
            ftp.rename(target, backup)
            old_moved = True
        else:
            print("[FTP] no existing plugin folder to back up.")

        # ---------- 3. PROMOTE staging -> live --------------------------
        try:
            print(f"[FTP] promoting staging -> {target}")
            ftp.rename(staging, target)
        except Exception:
            if old_moved:
                print("[FTP] promotion failed; restoring previous plugin.")
                ftp.rename(backup, target)
            raise

        print("[FTP] deployment complete.")

    finally:
        try:
            ftp.quit()
        except Exception:
            try:
                ftp.close()
            except Exception:
                pass

    # ---------- 4. RESTART plugin via REST API --------------------------
    print()
    print("=" * 70)
    print(" Calling REST API to restart the plugin")
    print("=" * 70)

    restart_plugin_via_api(plugin_name)

    print()
    print("DONE. Plugin files replaced and plugin restarted.")


if __name__ == "__main__":
    deploy_plugin()