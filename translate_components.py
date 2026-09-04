#!/usr/bin/env python3
"""
Script to add translation support to React component files.
"""

import os
import re
import sys
from pathlib import Path

# Import line to add
IMPORT_TRANSLATE = "import { translate, translatePlural } from '@/utils/translate';"
IMPORT_TRANSLATE_SIMPLE = "import { translate } from '@/utils/translate';"

def find_user_facing_strings(content):
    """
    Find user-facing strings in the content that should be translated.
    Returns a list of (pattern, replacement) tuples.
    """
    replacements = []

    # Pattern 1: JSX text nodes: >Some Text<
    # Skip: whitespace-only, numeric, already wrapped in {}
    jsx_text_pattern = r'>([^<]{2,})<'
    for match in re.finditer(jsx_text_pattern, content):
        text = match.group(1).strip()
        if text and not text.startswith('{') and not text.startswith('translate('):
            # Check if it has actual letters (not just symbols/numbers)
            if re.search(r'[a-zA-Z]', text) and len(text) > 1:
                # Escape single quotes in the text
                escaped_text = text.replace("'", "\\'")
                # Check if it's already inside a JSX expression
                full_match = match.group(0)
                if not re.search(r'\{[^}]*' + re.escape(text) + r'[^}]*\}', content[max(0, match.start()-50):match.end()+50]):
                    replacements.append((full_match, f">{{translate('{escaped_text}')}}<"))

    # Pattern 2: label="Text", title="Text", placeholder="Text", etc.
    attr_patterns = [
        r'\b(label|title|placeholder|description|helperText)\s*=\s*["\']([^"\']{2,})["\']',
    ]
    for pattern in attr_patterns:
        for match in re.finditer(pattern, content):
            attr_name = match.group(1)
            text = match.group(2)
            if text and not text.startswith('translate(') and not text.startswith('{'):
                if re.search(r'[a-zA-Z]', text):
                    escaped_text = text.replace("'", "\\'")
                    replacements.append((match.group(0), f"{attr_name}={{translate('{escaped_text}')}}"))

    return replacements

def has_translate_import(content):
    """Check if file already imports translate."""
    return "@/utils/translate" in content

def add_translate_import(content, needs_plural=False):
    """Add the translate import to the file."""
    lines = content.split('\n')

    # Find the last import line
    last_import_idx = -1
    for i, line in enumerate(lines):
        if line.strip().startswith('import '):
            last_import_idx = i

    if last_import_idx == -1:
        return content

    import_line = IMPORT_TRANSLATE if needs_plural else IMPORT_TRANSLATE_SIMPLE
    lines.insert(last_import_idx + 1, import_line)

    return '\n'.join(lines)

def process_file(filepath):
    """Process a single file."""
    try:
        with open(filepath, 'r', encoding='utf-8') as f:
            content = f.read()

        # Skip if already has translate import
        if has_translate_import(content):
            return 'skipped', 'already has translate import'

        # Skip if already uses translate function (might be imported differently)
        if 'translate(' in content or 'translatePlural(' in content:
            return 'skipped', 'already uses translate function'

        # Find user-facing strings
        replacements = find_user_facing_strings(content)

        if not replacements:
            # Check if there are any obvious user-facing strings
            has_strings = bool(re.search(r'[A-Z][a-z]+\s+[a-z]+', content))
            if not has_strings:
                return 'skipped', 'no obvious user-facing strings'
            return 'skipped', 'no translatable strings found'

        # Add import first
        needs_plural = any('Plural' in r[0] for r in replacements)
        content = add_translate_import(content, needs_plural)

        # Apply replacements
        # Need to be careful about overlapping replacements, so sort by position
        # and apply from end to start
        for old, new in replacements:
            content = content.replace(old, new, 1)

        # Write back
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)

        return 'modified', f'{len(replacements)} translations added'

    except Exception as e:
        return 'error', str(e)

def main():
    # Read file lists
    with open('/tmp/components_files_filtered.txt', 'r') as f:
        components_files = [l.strip() for l in f if l.strip()]

    with open('/tmp/features_files.txt', 'r') as f:
        features_files = [l.strip() for l in f if l.strip()]

    all_files = components_files + features_files

    modified = []
    skipped = []
    errors = []

    for filepath in all_files:
        if not os.path.exists(filepath):
            errors.append(f"{filepath} - file not found")
            continue

        result, msg = process_file(filepath)

        if result == 'modified':
            modified.append(f"{filepath} - {msg}")
        elif result == 'skipped':
            skipped.append(f"{filepath} - {msg}")
        else:
            errors.append(f"{filepath} - {msg}")

    # Print summary
    print("=== Translation Script Summary ===\n")
    print(f"Total files processed: {len(all_files)}")
    print(f"Files modified: {len(modified)}")
    print(f"Files skipped: {len(skipped)}")
    print(f"Errors: {len(errors)}\n")

    if modified:
        print("Modified files:")
        for f in modified:
            print(f"  - {f}")
        print()

    if skipped:
        print("Skipped files (first 20):")
        for f in skipped[:20]:
            print(f"  - {f}")
        if len(skipped) > 20:
            print(f"  ... and {len(skipped) - 20} more")
        print()

    if errors:
        print("Errors:")
        for e in errors:
            print(f"  - {e}")

if __name__ == '__main__':
    main()
