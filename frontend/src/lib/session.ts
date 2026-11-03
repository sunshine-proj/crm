// UI-only hint decoded from the JWT payload; the API enforces authorization.
export function rolesFromToken(token: string | null): string[] {
  if (!token) return [];
  const payload = token.split(".")[1];
  if (!payload) return [];
  try {
    const padded = payload.replace(/-/g, "+").replace(/_/g, "/");
    const binary = atob(padded.padEnd(Math.ceil(padded.length / 4) * 4, "="));
    const claims = JSON.parse(new TextDecoder().decode(Uint8Array.from(binary, (char) => char.charCodeAt(0)))) as { roles?: unknown };
    return Array.isArray(claims.roles) ? claims.roles.filter((role): role is string => typeof role === "string") : [];
  } catch {
    return [];
  }
}

export function isAdminToken(token: string | null): boolean {
  return rolesFromToken(token).includes("ROLE_ADMIN");
}

export function isFinanceToken(token: string | null): boolean {
  const roles = rolesFromToken(token);
  return roles.includes("ROLE_FINANCE") || roles.includes("ROLE_ADMIN");
}
