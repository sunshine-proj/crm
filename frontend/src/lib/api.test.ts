import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { apiRequest, downloadFile, errorText } from "./api";

function respond(status: number, body: unknown): Response {
  return new Response(body === undefined ? null : JSON.stringify(body), { status });
}

describe("api helpers", () => {
  let token: string | null;
  const fetchMock = vi.fn<(input: string, init?: RequestInit) => Promise<Response>>();

  beforeEach(() => {
    token = "jwt-token";
    vi.stubGlobal("localStorage", { getItem: (key: string) => (key === "crm_token" ? token : null) });
    vi.stubGlobal("fetch", fetchMock);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    fetchMock.mockReset();
  });

  it("sends the bearer token and JSON content type to the relative API path", async () => {
    fetchMock.mockResolvedValue(respond(200, { id: 7 }));

    await expect(apiRequest<{ id: number }>("/clients/7")).resolves.toEqual({ id: 7 });

    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe("/api/clients/7");
    expect(init?.headers).toEqual({ "Content-Type": "application/json", Authorization: "Bearer jwt-token" });
  });

  it("omits the authorization header without a token and lets callers override headers", async () => {
    token = null;
    fetchMock.mockResolvedValue(respond(200, []));

    await apiRequest("/clients", { method: "POST", headers: { "Content-Type": "text/csv" }, body: "a,b" });

    const init = fetchMock.mock.calls[0][1];
    expect(init?.method).toBe("POST");
    expect(init?.headers).toEqual({ "Content-Type": "text/csv" });
  });

  it("returns undefined for an empty 204 response", async () => {
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }));

    await expect(apiRequest("/clients/7", { method: "DELETE" })).resolves.toBeUndefined();
  });

  it("throws the API error message, or the fallback when the body is not JSON", async () => {
    fetchMock.mockResolvedValueOnce(respond(409, { error: "Only draft invoices can be edited." }));
    await expect(apiRequest("/invoices/1")).rejects.toThrow("Only draft invoices can be edited.");

    fetchMock.mockResolvedValueOnce(new Response("<html>Bad gateway</html>", { status: 502 }));
    await expect(apiRequest("/invoices/1", {}, "Unable to load the invoice.")).rejects.toThrow("Unable to load the invoice.");

    fetchMock.mockResolvedValueOnce(respond(500, {}));
    await expect(apiRequest("/invoices/1")).rejects.toThrow("Unable to complete the request.");
  });

  it("turns unknown errors into a readable message", () => {
    expect(errorText(new Error("Boom"), "Fallback")).toBe("Boom");
    expect(errorText("not an error", "Fallback")).toBe("Fallback");
  });

  it("downloads an authenticated file through a temporary link and releases it afterwards", async () => {
    vi.useFakeTimers();
    const link = { href: "", download: "", click: vi.fn() };
    const revoke = vi.fn();
    vi.stubGlobal("document", { createElement: () => link });
    vi.stubGlobal("window", { setTimeout: (callback: () => void, delay: number) => setTimeout(callback, delay) });
    vi.stubGlobal("URL", { createObjectURL: () => "blob:invoice", revokeObjectURL: revoke });
    fetchMock.mockResolvedValue(new Response("%PDF-1.7", { status: 200 }));

    await downloadFile("/invoices/3/pdf", "inv-3.pdf");

    expect(fetchMock.mock.calls[0]).toEqual(["/api/invoices/3/pdf", { headers: { Authorization: "Bearer jwt-token" } }]);
    expect(link).toMatchObject({ href: "blob:invoice", download: "inv-3.pdf" });
    expect(link.click).toHaveBeenCalledOnce();
    expect(revoke).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1000);
    expect(revoke).toHaveBeenCalledWith("blob:invoice");
    vi.useRealTimers();
  });

  it("reports a failed download with the API message or the fallback", async () => {
    token = null;
    fetchMock.mockResolvedValueOnce(respond(404, { error: "Invoice not found." }));
    await expect(downloadFile("/invoices/9/pdf", "x.pdf")).rejects.toThrow("Invoice not found.");
    expect(fetchMock.mock.calls[0][1]).toEqual({ headers: {} });

    fetchMock.mockResolvedValueOnce(new Response("oops", { status: 500 }));
    await expect(downloadFile("/invoices/9/pdf", "x.pdf")).rejects.toThrow("Unable to download the file.");
  });
});
