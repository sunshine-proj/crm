import { describe, expect, it } from "vitest";
import { isAdminToken, isFinanceToken, rolesFromToken } from "./session";

function tokenWith(claims: unknown): string {
  const encode = (value: unknown) => btoa(JSON.stringify(value)).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
  return `${encode({ alg: "RS256" })}.${encode(claims)}.signature`;
}

describe("session roles", () => {
  it("reads roles from the token payload", () => {
    expect(rolesFromToken(tokenWith({ roles: ["ROLE_USER", "ROLE_ADMIN"] }))).toEqual(["ROLE_USER", "ROLE_ADMIN"]);
    expect(isAdminToken(tokenWith({ roles: ["ROLE_ADMIN"] }))).toBe(true);
  });

  it("treats missing or malformed tokens as non-admin", () => {
    expect(isAdminToken(null)).toBe(false);
    expect(isAdminToken("not-a-token")).toBe(false);
    expect(isAdminToken("a.%%%.c")).toBe(false);
    expect(isAdminToken(tokenWith({ roles: "ROLE_ADMIN" }))).toBe(false);
    expect(isAdminToken(tokenWith({ roles: ["ROLE_USER"] }))).toBe(false);
  });

  it("treats finance and admin tokens as finance users", () => {
    expect(isFinanceToken(tokenWith({ roles: ["ROLE_USER", "ROLE_FINANCE"] }))).toBe(true);
    expect(isFinanceToken(tokenWith({ roles: ["ROLE_ADMIN"] }))).toBe(true);
    expect(isFinanceToken(tokenWith({ roles: ["ROLE_USER"] }))).toBe(false);
    expect(isAdminToken(tokenWith({ roles: ["ROLE_FINANCE"] }))).toBe(false);
  });
});
