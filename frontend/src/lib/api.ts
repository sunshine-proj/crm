/** Authenticated JSON request against the Symfony API; throws an Error carrying the API's `error` message. */
export async function apiRequest<T>(path: string, options: RequestInit = {}, fallbackMessage = "Unable to complete the request."): Promise<T> {
  const token = localStorage.getItem("crm_token");
  const response = await fetch(`/api${path}`, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  });
  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as { error?: string } | null;
    throw new Error(payload?.error ?? fallbackMessage);
  }
  return response.status === 204 ? (undefined as T) : ((await response.json()) as T);
}

export function errorText(error: unknown, fallback: string): string {
  return error instanceof Error ? error.message : fallback;
}

/** Downloads an authenticated binary response (for example a PDF) through a temporary link. */
export async function downloadFile(path: string, filename: string, fallbackMessage = "Unable to download the file."): Promise<void> {
  const token = localStorage.getItem("crm_token");
  const response = await fetch(`/api${path}`, { headers: token ? { Authorization: `Bearer ${token}` } : {} });
  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as { error?: string } | null;
    throw new Error(payload?.error ?? fallbackMessage);
  }
  const blobUrl = URL.createObjectURL(await response.blob());
  const link = document.createElement("a");
  link.href = blobUrl;
  link.download = filename;
  link.click();
  window.setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
}
