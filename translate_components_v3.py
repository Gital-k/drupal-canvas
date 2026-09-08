#!/usr/bin/env python3
"""
Script to add translation support to React component files.
This script carefully identifies and translates only user-facing strings in JSX.
"""

import os
import re
from pathlib import Path

IMPORT_TRANSLATE = "import { translate, translatePlural } from '@/utils/translate';"
IMPORT_TRANSLATE_SIMPLE = "import { translate } from '@/utils/translate';"

def is_already_translated(content):
    """Check if file already has translate import."""
    return "@/utils/translate" in content

def find_jsx_text_spans(content):
    """
    Find JSX text spans that are actual user-facing strings.
    Returns list of (start, end, text) tuples.
    """
    spans = []

    # Pattern: >text< (JSX text content)
    # We need to be careful not to match:
    # - Type definitions: type X = Y
    # - Generic types: Component<T>
    # - CSS in objects: style={{ color: 'red' }}
    # - TypeScript type annotations: var: Type

    # Simple approach: look for >...< patterns that are likely JSX
    pattern = r'>([^<]*[a-zA-Z][^<]*)<'

    for match in re.finditer(pattern, content):
        text = match.group(1)
        start = match.start() + 1  # After the >
        end = match.end() - 1      # Before the <

        # Get context around the match
        context_start = max(0, match.start() - 200)
        context = content[context_start:match.start()]

        # Skip if inside a type definition or type annotation
        if re.search(r':\s*[A-Z][a-zA-Z0-9]*\s*[=<>?!]', context):
            continue

        # Skip if it's just CSS values
        if re.match(r'^\s*(--|#|\d)', text):
            continue

        # Skip if looks like code/variables
        if text.strip() in ['true', 'false', 'null', 'undefined', 'void']:
            continue

        # Must have actual letters
        if not re.search(r'[a-zA-Z]', text):
            continue

        # Skip if it looks like a CSS custom property or variable
        if '--' in text and not re.search(r'[a-zA-Z]{3,}', text):
            continue

        # Check the tag context - should be a JSX element
        # Look backwards to find the opening tag
        tag_search = content[context_start:match.start()]
        if '<' in tag_search:
            # Check if we're in JSX element context
            # Simple heuristic: if we see something like <Tag> before us
            if re.search(r'<[a-zA-Z][a-zA-Z0-9]*', tag_search):
                spans.append((start, end, text))

    return spans

def translate_jsx_text(content):
    """
    Translate JSX text content.
    """
    spans = find_jsx_text_spans(content)

    if not spans:
        return content, 0

    # Apply replacements from end to start
    result = content
    count = 0
    for start, end, text in reversed(spans):
        # Skip if already wrapped
        if 'translate(' in text:
            continue

        # Escape single quotes
        escaped = text.strip().replace("'", "\\'")

        # Create replacement
        replacement = f"{{translate('{escaped}')}}"

        # Apply
        result = result[:start] + replacement + result[end:]
        count += 1

    return result, count

def translate_attributes(content):
    """
    Translate common attribute values like label="Text", title="Text", etc.
    """
    def should_translate(value):
        if not value or len(value) < 2:
            return False
        if value.startswith('{') or value.startswith('translate('):
            return False
        if not re.search(r'[a-zA-Z]', value):
            return False
        # Skip CSS values
        if re.match(r'^\d+(px|em|rem|%|vh|vw|pt|ms|s)$', value):
            return False
        if value in ['true', 'false', 'null', 'undefined']:
            return False
        return True

    attrs_to_translate = ['label', 'title', 'placeholder', 'description', 'helperText', 'message', 'aria-label']
    result = content
    count = 0

    for attr in attrs_to_translate:
        # Pattern: attr="value" or attr='value'
        pattern = rf'\b{attr}\s*=\s*["\']([^"\']+)["\']'
        matches = list(re.finditer(pattern, content))
        for match in reversed(matches):
            value = match.group(1)
            if should_translate(value):
                # Check context - don't translate inside type definitions
                context_start = max(0, match.start() - 100)
                context = content[context_start:match.start()]
                if ':' in context and re.search(r':\s*[A-Z]', context):
                    continue

                escaped = value.replace("'", "\\'")
                replacement = f"{attr}={{translate('{escaped}')}}"
                result = result[:match.start()] + replacement + result[match.end():]
                count += 1

    return result, count

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
            original_content = f.read()

        content = original_content

        # Skip if already has translate import
        if is_already_translated(content):
            return 'skipped', 'already has translate import'

        # Skip if no JSX
        if '<' not in content:
            return 'skipped', 'no JSX content'

        # Translate JSX text
        content, text_count = translate_jsx_text(content)

        # Translate attributes
        content, attr_count = translate_attributes(content)

        total_count = text_count + attr_count

        if total_count == 0:
            return 'skipped', 'no translations added'

        # Add import
        content = add_translate_import(content, needs_plural=False)

        # Write back
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)

        return 'modified', f'{total_count} translations added'

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
