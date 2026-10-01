export function isValidDateRange(startDate: string | null, endDate: string | null): boolean {
  if (!startDate || !endDate) return true;

  const start = Date.parse(startDate);
  const end = Date.parse(endDate);
  return Number.isFinite(start) && Number.isFinite(end) && end >= start;
}

export function isValidDateTimeRange(startDateTime: string, endDateTime: string): boolean {
  if (!startDateTime || !endDateTime) return true;

  const start = Date.parse(startDateTime);
  const end = Date.parse(endDateTime);
  return Number.isFinite(start) && Number.isFinite(end) && end >= start;
}