import type { SpaceInvadersSnapshot } from "./SpaceInvadersGame";

export class SpaceInvadersRenderer {
  render(ctx: CanvasRenderingContext2D, game: SpaceInvadersSnapshot) {
    ctx.fillStyle = "#b8bea8";
    ctx.fillRect(0, 0, 240, 320);
    ctx.fillStyle = "#30372b";
    ctx.font = "10px monospace";
    ctx.fillText(`SPACE INVADERS ${game.score}`, 8, 15);
    ctx.fillText(`LIVES ${game.lives}`, 175, 29);

    for (const e of game.enemies) {
      if (!e.alive) continue;
      ctx.fillRect(e.x, e.y, 13, 8);
      ctx.fillRect(e.x + 2, e.y - 3, 3, 3);
      ctx.fillRect(e.x + 8, e.y - 3, 3, 3);
      ctx.fillRect(e.x - 2, e.y + 5, 3, 4);
      ctx.fillRect(e.x + 12, e.y + 5, 3, 4);
    }

    for (const s of game.shots) {
      ctx.fillRect(s.x, s.y, 3, 7);
    }

    // Player ship.
    ctx.fillRect(game.playerX - 9, 292, 18, 5);
    ctx.fillRect(game.playerX - 5, 286, 10, 6);
    ctx.fillRect(game.playerX - 2, 281, 4, 5);

    if (game.status === "paused" || game.status === "gameover" || game.status === "victory") {
      ctx.fillStyle = "#b8bea8";
      ctx.fillRect(35, 135, 170, 48);
      ctx.strokeRect(35, 135, 170, 48);
      ctx.textAlign = "center";
      const message = game.status === "paused" ? "PAUSED" : game.status === "victory" ? "YOU WIN!" : "GAME OVER";
      ctx.fillText(message, 120, 154);
      ctx.fillText("PRESS START", 120, 171);
      ctx.textAlign = "left";
    }
  }
}
