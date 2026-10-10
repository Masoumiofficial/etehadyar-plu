#!/usr/bin/env python3
"""
Automated Release Gate & Security Verification Suite for AvandWP v1.0.0.
"""

import os
import re
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUGIN_DIR = os.path.join(ROOT, "avandwp")

FORBIDDEN_PATTERNS = [
    r"\betehad",
    r"\beaiw_",
    r"\bEAIW_",
    r"\bnebula\b",
    r"\bchatsoul\b",
    r"\bgodmode\b",
    r"\boracle\b",
    r"\bsupernatural\b",
    r"\bsanitize_textfield\b",
    r"\bstr_word_count\b",
    r"queue\.fal\.run",
    r"\bEAIW_FPDF\b",
    r"\bmockReply\b",
    r"\bmock_gsc\b",
    r"\bmock_opps\b",
]


def strip_php_strings_and_comments(code: str) -> str:
    """Remove strings and comments from PHP code to check bracket balance accurately."""
    pattern = re.compile(
        r"//.*?$|#.*?$|/\*[\s\S]*?\*/|'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"",
        re.MULTILINE,
    )
    return pattern.sub(" ", code)


def check_balanced_delimiters(filepath: str, content: str) -> list:
    errors = []
    # Only strip inside <?php ... ?> blocks or whole file if pure PHP
    cleaned = strip_php_strings_and_comments(content)
    stack = []
    pairs = {"}": "{", ")": "(", "]": "["}
    for idx, ch in enumerate(cleaned):
        if ch in "{([":
            stack.append((ch, idx))
        elif ch in "})]":
            if not stack or stack[-1][0] != pairs[ch]:
                errors.append(f"{filepath}: Unbalanced '{ch}' at offset {idx}")
                return errors
            stack.pop()
    if stack:
        errors.append(f"{filepath}: Unclosed '{stack[-1][0]}' at EOF")
    return errors


def main():
    failures = []
    php_files = []
    js_files = []
    all_files = []

    for dirpath, _, filenames in os.walk(PLUGIN_DIR):
        for fn in sorted(filenames):
            full = os.path.join(dirpath, fn)
            rel = os.path.relpath(full, PLUGIN_DIR)
            all_files.append(rel)
            if fn.endswith(".php"):
                php_files.append(full)
            elif fn.endswith(".js"):
                js_files.append(full)

    print(f"[1/6] Inspecting {len(all_files)} files in avandwp/ ({len(php_files)} PHP, {len(js_files)} JS)...")

    # 1. PHP Guard, Delimiter Balance & Forbidden Legacy Patterns
    for pf in php_files:
        rel = os.path.relpath(pf, PLUGIN_DIR)
        with open(pf, "r", encoding="utf-8") as f:
            text = f.read()

        if not text.startswith("<?php"):
            failures.append(f"{rel}: Does not start with <?php")

        if "ABSPATH" not in text and "WP_UNINSTALL_PLUGIN" not in text:
            failures.append(f"{rel}: Missing ABSPATH / WP_UNINSTALL_PLUGIN guard")

        # Check balanced brackets on pure class files (templates have split if/endif blocks across HTML)
        if not rel.startswith("templates/"):
            failures.extend(check_balanced_delimiters(rel, text))

        cleaned_code = strip_php_strings_and_comments(text)
        for pat in FORBIDDEN_PATTERNS:
            if re.search(pat, cleaned_code, re.IGNORECASE):
                failures.append(f"{rel}: Found forbidden legacy pattern '{pat}'")

    print("[2/6] Verifying JavaScript syntax via `node --check` and XSS protection...")
    for jf in js_files:
        rel = os.path.relpath(jf, PLUGIN_DIR)
        res = subprocess.run(["node", "--check", jf], capture_output=True, text=True)
        if res.returncode != 0:
            failures.append(f"{rel}: JS syntax error: {res.stderr}")
        with open(jf, "r", encoding="utf-8") as f:
            jtext = f.read()
        if "function escHtml(" not in jtext:
            failures.append(f"{rel}: Missing escHtml() XSS helper")
        for pat in FORBIDDEN_PATTERNS:
            if re.search(pat, jtext, re.IGNORECASE):
                failures.append(f"{rel}: Found forbidden legacy pattern '{pat}'")

    print("[3/6] Verifying Database Schema & Support Ticket Columns...")
    with open(os.path.join(PLUGIN_DIR, "includes/Core/class-activator.php"), "r", encoding="utf-8") as f:
        act_text = f.read()
    with open(os.path.join(PLUGIN_DIR, "uninstall.php"), "r", encoding="utf-8") as f:
        uninst_text = f.read()

    expected_tables = [
        "avandwp_knowledge",
        "avandwp_jobs",
        "avandwp_chat_logs",
        "avandwp_usage",
        "avandwp_support_requests",
        "avandwp_support_history",
        "avandwp_chat_feedback",
        "avandwp_activity_log",
    ]
    for tbl in expected_tables:
        if f"CREATE TABLE {{$p}}{tbl}" not in act_text:
            failures.append(f"Activator missing CREATE TABLE for {tbl}")
        if f"'{tbl}'" not in uninst_text:
            failures.append(f"uninstall.php missing table {tbl}")

    for col in ["priority", "audio_url", "transcript"]:
        if col not in act_text:
            failures.append(f"avandwp_support_requests schema missing column '{col}'")

    print("[4/6] Verifying AJAX Nonce/Capability & REST Rate Limiting...")
    with open(os.path.join(PLUGIN_DIR, "includes/Core/class-plugin.php"), "r", encoding="utf-8") as f:
        plugin_text = f.read()

    ajax_matches = re.findall(r"public function (ajax_[a-z0-9_]+)\s*\(", plugin_text)
    if len(ajax_matches) < 18:
        failures.append(f"Expected at least 18 AJAX handlers, found {len(ajax_matches)}")

    for fn_name in ajax_matches:
        pattern = rf"public function {fn_name}\s*\(\)\s*\{{[\s\S]*?\$this->verify_ajax\("
        if not re.search(pattern, plugin_text):
            failures.append(f"AJAX handler {fn_name} missing $this->verify_ajax() check")

    for rest_fn in ["rest_handle_chat", "rest_handle_support", "rest_handle_support_audio", "rest_handle_feedback"]:
        pattern = rf"public function {rest_fn}\s*\([^)]*\)\s*\{{[\s\S]*?AvandWP_Rate_Limiter::check\("
        if not re.search(pattern, plugin_text):
            failures.append(f"REST handler {rest_fn} missing AvandWP_Rate_Limiter::check()")

    if "transcribe_audio_file( $uploaded['file'] )" not in plugin_text:
        failures.append("rest_handle_support_audio does not use $uploaded['file'] for Whisper transcription")

    print("[5/6] Verifying Telegram SSRF validation & Persian UTF-8 Word Counter...")
    with open(os.path.join(PLUGIN_DIR, "includes/Automation/class-telegram.php"), "r", encoding="utf-8") as f:
        tg_text = f.read()
    if "wp_http_validate_url" not in tg_text:
        failures.append("Telegram proxy validation missing wp_http_validate_url()")

    with open(os.path.join(PLUGIN_DIR, "includes/Core/class-text-helper.php"), "r", encoding="utf-8") as f:
        th_text = f.read()
    if "preg_split( '/\\s+/u'" not in th_text:
        failures.append("AvandWP_Text_Helper missing UTF-8 regex word splitter")

    print("[6/6] Summary...")
    if failures:
        print("FAILED CHECKS:")
        for err in failures:
            print("  ❌", err)
        sys.exit(1)

    print("✅ ALL RELEASE GATE CHECKS PASSED SUCCESSFULLY!")


if __name__ == "__main__":
    main()
