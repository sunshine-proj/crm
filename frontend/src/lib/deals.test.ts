import { describe, expect, it } from "vitest";
import { groupDeals, isOpenStage, totalsByCurrency, type DealCard } from "./deals";

const deals: DealCard[] = [
  { id: 1, stage: "lead", amount: "100.10", currency: "EUR" },
  { id: 2, stage: "lead", amount: "0.20", currency: "EUR" },
  { id: 3, stage: "proposal", amount: "50", currency: "GBP" },
  { id: 4, stage: "lost", amount: null, currency: null },
];

describe("deal helpers", () => {
  it("groups deals into every stage", () => {
    const groups = groupDeals(deals);
    expect(groups.lead.map((deal) => deal.id)).toEqual([1, 2]);
    expect(groups.won).toEqual([]);
  });

  it("sums per currency in minor units without floating-point drift", () => {
    expect(totalsByCurrency(deals)).toEqual([
      { currency: "EUR", amount: "100.30" },
      { currency: "GBP", amount: "50.00" },
    ]);
    expect(totalsByCurrency([{ id: 9, stage: "lead", amount: "0.1", currency: "EUR" }, { id: 10, stage: "lead", amount: "0.2", currency: "EUR" }])).toEqual([{ currency: "EUR", amount: "0.30" }]);
  });

  it("knows which stages are open", () => {
    expect(isOpenStage("negotiation")).toBe(true);
    expect(isOpenStage("won")).toBe(false);
    expect(isOpenStage("lost")).toBe(false);
  });
});
