import { BEST_KEY } from './constants';

let memory = 0; // fallback when storage is unavailable

export function loadBest(): number {
  try {
    const v = Number(localStorage.getItem(BEST_KEY));
    return Number.isFinite(v) && v > 0 ? Math.floor(v) : memory;
  } catch { return memory; }
}

export function saveBest(n: number): void {
  memory = n;
  try { localStorage.setItem(BEST_KEY, String(n)); } catch { /* private mode */ }
}
