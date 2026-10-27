import type { FroggerSnapshot } from "./FroggerGame";

export class FroggerRenderer {
  render(ctx: CanvasRenderingContext2D, game: FroggerSnapshot) {
    ctx.fillStyle = "#b8bea8";
    ctx.fillRect(0, 0, 240, 320);
    ctx.fillStyle = "#30372b";
    ctx.font = "10px monospace";
    ctx.fillText(`FROGGER  ${game.score}`, 8, 14);

    // Safe banks, river, road, and destination.
    ctx.fillRect(0, 35, 240, 22);
    ctx.fillStyle = "#68705d";
    ctx.fillRect(0, 58, 240, 90);
    ctx.fillStyle = "#30372b";
    ctx.fillRect(0, 149, 240, 100);
    ctx.fillStyle = "#b8bea8";
    for (let x = 0; x < 240; x += 30) ctx.fillRect(x, 197, 15, 2);
    ctx.fillStyle = "#30372b";
    ctx.fillRect(0, 250, 240, 35);

    for (const h of game.hazards) {
      ctx.fillStyle = "#30372b";
      ctx.fillRect(h.x, h.y, h.width, 12);
      ctx.fillStyle = "#b8bea8";
      ctx.fillRect(h.x + 3, h.y + 3, h.width - 6, 3);
    }

    ctx.fillStyle = "#20251c";
    ctx.fillRect(game.x - 5, game.y - 5, 10, 10);
    ctx.fillRect(game.x - 8, game.y - 8, 4, 4);
    ctx.fillRect(game.x + 4, game.y - 8, 4, 4);
    ctx.fillText(`LIVES ${game.lives}`, 165, 14);

    if (game.status === "paused" || game.status === "gameover") {
      ctx.fillStyle = "#b8bea8";
      ctx.fillRect(45, 135, 150, 48);
      ctx.strokeRect(45, 135, 150, 48);
      ctx.textAlign = "center";
      ctx.fillText(game.status === "paused" ? "PAUSED" : "GAME OVER", 120, 154);
      ctx.fillText("PRESS START", 120, 171);
      ctx.textAlign = "left";
    }
  }
}
