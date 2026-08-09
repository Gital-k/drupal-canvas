import DOMPurify from 'dompurify';

// Extract and sanitize human-readable error message from API error response.
export const extractErrorMessageFromApiResponse = (error: any): string => {
  const fallbackMessage =
    'Error occurred, see browser console for more details.';
  const errors = error?.data?.errors;
  if (!errors || !errors.length) return fallbackMessage;
  // Handle simple string array errors.
  if (typeof errors[0] === 'string') {
    if (errors.length === 1) return DOMPurify.sanitize(errors[0]);
    return `<ul>${errors.map((msg: string) => `<li>${DOMPurify.sanitize(msg)}</li>`).join('')}</ul>`;
  }

  // Deduplicate exact duplicates (same pointer AND same message).
  const seen = new Set<string>();
  const messages = [...errors]
    .filter((err) => {
      const key = `${err.source?.pointer ?? ''}::${err.detail ?? ''}`;
      return seen.has(key) ? false : !!seen.add(key);
    })
    // Sort: pointer-based errors first, then general (no/empty pointer) errors.
    .sort((a, b) =>
      a.source?.pointer && !b.source?.pointer
        ? -1
        : !a.source?.pointer && b.source?.pointer
          ? 1
          : 0,
    )
    .map((err) => err.detail as string)
    .filter(Boolean);

  if (!messages.length) return fallbackMessage;

  // "This value should be of the correct primitive type." is a low-quality
  // fallback; suppress it when more specific messages exist.
  const PRIMITIVE_TYPE_MSG =
    'This value should be of the correct primitive type.';
  const hasSpecific = messages.some((m) => m !== PRIMITIVE_TYPE_MSG);

  // In a single pass:
  // - Suppress PRIMITIVE_TYPE_MSG when more specific messages exist.
  // - Track prop names from primary "Prop X has invalid example value" messages
  //   and suppress follow-on errors that reference the same prop (e.g. meta:enum
  //   detail errors), keeping the popup concise.
  const seenProps = new Set<string>();
  const finalMessages = messages.filter((msg) => {
    if (msg === PRIMITIVE_TYPE_MSG) return !hasSpecific;
    const primary = msg.match(/^Prop "([^"]+)"/);
    if (primary) {
      seenProps.add(primary[1]);
      return true;
    }
    const ref = msg.match(/"([^"]+)" prop/i);
    return !(ref && seenProps.has(ref[1]));
  });

  if (!finalMessages.length) return fallbackMessage;
  if (finalMessages.length === 1) return DOMPurify.sanitize(finalMessages[0]);
  return `<ul>${finalMessages.map((msg) => `<li>${DOMPurify.sanitize(msg)}</li>`).join('')}</ul>`;
};
