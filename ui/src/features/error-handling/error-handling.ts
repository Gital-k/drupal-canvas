import DOMPurify from 'dompurify';

// Extract and sanitize human-readable error message from API error response.
export const extractErrorMessageFromApiResponse = (error: any): string => {
  const fallbackMessage =
    'Error occurred, see browser console for more details.';
  const errors = error?.data?.errors;
  if (!errors || !errors.length) return fallbackMessage;
  // Handle simple string array errors
  if (typeof errors[0] === 'string') {
    if (errors.length === 1) return DOMPurify.sanitize(errors[0]);
    const items = errors
      .map((msg: string) => `<li>${DOMPurify.sanitize(msg)}</li>`)
      .join('');
    return `<ul>${items}</ul>`;
  }
  // Get error messages, and sort them where details with a pointer (ex. machineName) come first.
  const errorMessages = [...errors]
    .sort((a, b) =>
      a.source?.pointer && !b.source?.pointer
        ? -1
        : !a.source?.pointer && b.source?.pointer
          ? 1
          : 0,
    )
    .map((err) => err.detail)
    .filter(Boolean);
  if (!errorMessages.length) return fallbackMessage;
  if (errorMessages.length === 1) {
    return DOMPurify.sanitize(errorMessages[0]);
  }
  const items = errorMessages
    .map((msg: string) => `<li>${DOMPurify.sanitize(msg)}</li>`)
    .join('');
  return `<ul>${items}</ul>`;
};
