const supportedCurrencies = ["EUR", "GBP", "USD"] as const;
export type SupportedCurrency = (typeof supportedCurrencies)[number];

export function getOrganizationDefaultCurrency(): string {
  const currency = localStorage.getItem("crm_default_currency");
  return supportedCurrencies.find((supportedCurrency) => supportedCurrency === currency) ?? "EUR";
}

export function setOrganizationDefaultCurrency(currency: string | null): void {
  if (currency && supportedCurrencies.some((supportedCurrency) => supportedCurrency === currency)) {
    localStorage.setItem("crm_default_currency", currency);
  } else {
    localStorage.removeItem("crm_default_currency");
  }
}
