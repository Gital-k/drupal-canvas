#!/usr/bin/env python3
"""
Script to add translation support to React component files.
This script is more careful about what it replaces to avoid breaking the code.
"""

import os
import re
import sys
from pathlib import Path

# Import line to add
IMPORT_TRANSLATE = "import { translate, translatePlural } from '@/utils/translate';"
IMPORT_TRANSLATE_SIMPLE = "import { translate } from '@/utils/translate';"

def is_already_translated(content):
    """Check if file already has translate import."""
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

def translate_jsx_text(content):
    """
    Translate JSX text nodes carefully.
    Only translate text that is:
    - Between > and < (JSX text content)
    - Contains actual letters (not just symbols/numbers)
    - Is more than 1 character
    - Is not inside a type definition, generic parameter, or similar
    """
    # Pattern to find JSX text: >Text<
    # But we need to be careful not to match:
    # - Type parameters: <Type>text</Type>
    # - Generic types: Component<T>
    # - CSS properties in objects: width: '100px'
    # - Strings in type definitions

    def should_translate_text(text):
        """Determine if text should be translated."""
        text = text.strip()
        if not text:
            return False
        if len(text) <= 1:
            return False
        if text.startswith('translate('):
            return False
        if text.startswith('{'):
            return False
        # Must contain actual letters (not just symbols/numbers)
        if not re.search(r'[a-zA-Z]', text):
            return False
        # Skip if it looks like CSS values or code
        if text in ['true', 'false', 'null', 'undefined', 'void', 'any', 'string', 'number', 'boolean']:
            return False
        if text.startswith('--'):
            return False
        if re.match(r'^\d+(px|em|rem|%|vh|vw|pt|ms|s)$', text):
            return False
        return True

    # Find JSX text content
    # Look for patterns like: >Some text here<
    # But be careful about context
    result = content
    pattern = r'>([^<]+?)<'

    matches = list(re.finditer(pattern, content))
    # Process from end to start to avoid position shifts
    for match in reversed(matches):
        text = match.group(1).strip()
        if should_translate_text(text):
            # Escape single quotes
            escaped = text.replace("'", "\\'")
            replacement = f">{{translate('{escaped}')}}<"
            result = result[:match.start()] + replacement + result[match.end():]

    return result

def translate_attributes(content):
    """
    Translate attribute values like label="Text", title="Text", etc.
    """
    def should_translate_attr(attr_name, value):
        """Determine if an attribute value should be translated."""
        if not value:
            return False
        if value.startswith('{') or value.startswith('translate('):
            return False
        if not re.search(r'[a-zA-Z]', value):
            return False
        # Skip CSS values and other non-translatable strings
        if re.match(r'^\d+(px|em|rem|%|vh|vw|pt|ms|s)$', value):
            return False
        if value in ['true', 'false', 'null', 'undefined']:
            return False
        return True

    # Attributes to translate
    attrs_to_translate = ['label', 'title', 'placeholder', 'description', 'helperText', 'message', 'aria-label']

    result = content
    for attr in attrs_to_translate:
        # Pattern: attr="value" or attr='value'
        pattern = rf'\b{attr}\s*=\s*["\']([^"\']+)["\']'
        matches = list(re.finditer(pattern, content))
        for match in reversed(matches):
            value = match.group(1)
            if should_translate_attr(attr, value):
                escaped = value.replace("'", "\\'")
                replacement = f"{attr}={{translate('{escaped}')}}"
                result = result[:match.start()] + replacement + result[match.end():]

    return result

def count_translate_uses(content):
    """Count how many translate() calls are in the content."""
    return content.count('translate(')

def process_file(filepath):
    """Process a single file."""
    try:
        with open(filepath, 'r', encoding='utf-8') as f:
            original_content = f.read()

        content = original_content

        # Skip if already has translate import
        if is_already_translated(content):
            return 'skipped', 'already has translate import'

        # Skip if no JSX (probably a utility file)
        if '<' not in content:
            return 'skipped', 'no JSX content'

        # Translate JSX text content
        content = translate_jsx_text(content)

        # Translate attributes
        content = translate_attributes(content)

        # Count translate uses
        translate_count = count_translate_uses(content)

        if translate_count == 0:
            return 'skipped', 'no translations added'

        # Add import
        content = add_translate_import(content, needs_plural=False)

        # Write back
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)

        return 'modified', f'{translate_count} translations added'

    except Exception as e:
        import traceback
        return 'error', f"{str(e)}\n{traceback.format_exc()}"

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
