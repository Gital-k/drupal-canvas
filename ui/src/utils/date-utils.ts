// Converts local datetime-local input to UTC ISO string
// Example: "2024-01-15T14:30" → "2024-01-15T19:30:00.000Z"
export const localTimeToUtcConversion = (datetimeLocal: string): string => {
  if (!datetimeLocal || datetimeLocal.trim() === '') return '';

  // Ensure the datetime string includes seconds if missing
  // datetime-local inputs may return "YYYY-MM-DDTHH:MM" without seconds
  let normalized = datetimeLocal;
  if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(datetimeLocal)) {
    // Add seconds if only HH:MM is provided
    normalized = `${datetimeLocal}:00`;
  }

  const date = new Date(normalized);

  // Validate that the date is valid
  if (isNaN(date.getTime())) {
    return '';
  }

  return date.toISOString();
};

// Converts UTC ISO string to local datetime-local format for input display
// Example: "2024-01-15T19:30:00.000Z" → "2024-01-15T14:30"
export const utcToLocalTimeConversion = (isoUtc: string): string => {
  if (!isoUtc) return '';
  const d = new Date(isoUtc); // parsed in UTC, Date methods return local time
  const pad = (n: number) => String(n).padStart(2, '0');
  const yyyy = d.getFullYear();
  const mm = pad(d.getMonth() + 1);
  const dd = pad(d.getDate());
  const hh = pad(d.getHours());
  const min = pad(d.getMinutes());
  return `${yyyy}-${mm}-${dd}T${hh}:${min}`;
};
