export type DealStage = "lead" | "qualified" | "proposal" | "negotiation" | "won" | "lost";

export const dealStages: { stage: DealStage; label: string }[] = [
  { stage: "lead", label: "Lead" },
  { stage: "qualified", label: "Qualified" },
  { stage: "proposal", label: "Proposal" },
  { stage: "negotiation", label: "Negotiation" },
  { stage: "won", label: "Won" },
  { stage: "lost", label: "Lost" },
];

export type DealCard = { id: number; stage: DealStage; amount: string | null; currency: string | null };

export function groupDeals<T extends { stage: DealStage }>(deals: T[]): Record<DealStage, T[]> {
  const groups: Record<DealStage, T[]> = { lead: [], qualified: [], proposal: [], negotiation: [], won: [], lost: [] };
  for (const deal of deals) groups[deal.stage]?.push(deal);
  return groups;
}

function toMinor(amount: string): number {
  const [whole, fraction = ""] = amount.split(".");
  return Number(whole) * 100 + Number(fraction.padEnd(2, "0").slice(0, 2));
}

/** Sums deal values per currency in integer minor units; currencies are never mixed. */
export function totalsByCurrency(deals: DealCard[]): { currency: string; amount: string }[] {
  const totals = new Map<string, number>();
  for (const deal of deals) {
    if (deal.amount === null || deal.currency === null) continue;
    totals.set(deal.currency, (totals.get(deal.currency) ?? 0) + toMinor(deal.amount));
  }
  return [...totals.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([currency, minor]) => ({ currency, amount: `${Math.floor(minor / 100)}.${String(minor % 100).padStart(2, "0")}` }));
}

export function isOpenStage(stage: DealStage): boolean {
  return stage !== "won" && stage !== "lost";
}
