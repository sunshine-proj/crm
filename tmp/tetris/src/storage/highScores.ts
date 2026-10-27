const PREFIX = "brick-game:high-score:";

export function getHighScore(gameId: string): number {
  try {
    const value = Number(localStorage.getItem(PREFIX + gameId));
    return Number.isFinite(value) && value >= 0 ? value : 0;
  } catch {
    return 0;
  }
}

export function saveHighScore(
  gameId: string,
  score: number
): number {
  const best = Math.max(getHighScore(gameId), score);

  try {
    localStorage.setItem(PREFIX + gameId, String(best));
  } catch {
    // Storage may be unavailable in private browsing.
  }

  return best;
}
