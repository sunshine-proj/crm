import type { TankSnapshot } from "./TankGame";

export class TankRenderer {
  render(ctx: CanvasRenderingContext2D, game: TankSnapshot) {
    ctx.fillStyle = "#b8bea8";
    ctx.fillRect(0, 0, 240, 320);
    ctx.fillStyle = "#30372b";
    ctx.font = "10px monospace";
    ctx.fillText(`TANK  SCORE ${game.score}`, 8, 14);
    ctx.strokeRect(4, 24, 232, 286);

    const drawTank = (x: number, y: number, dir: number) => {
      ctx.fillRect(x, y, 16, 16);
      ctx.fillStyle = "#b8bea8";
      ctx.fillRect(x + 4, y + 4, 8, 8);
      const d = [{ x: 6, y: -5 }, { x: 15, y: 6 }, { x: 6, y: 15 }, { x: -5, y: 6 }][dir];
      ctx.fillStyle = "#30372b";
      ctx.fillRect(x + d.x, y + d.y, 5, 5);
    };

    drawTank(game.player.x, game.player.y, game.player.dir);
    game.enemies.forEach(e => drawTank(e.x, e.y, e.dir));

    game.bullets.forEach(b => {
      ctx.fillStyle = b.enemy ? "#68705d" : "#20251c";
      ctx.fillRect(b.x, b.y, 4, 4);
    });

    if (game.status === "gameover" || game.status === "paused") {
      ctx.fillStyle = "#b8bea8";
      ctx.fillRect(45, 135, 150, 45);
      ctx.strokeRect(45, 135, 150, 45);
      ctx.textAlign = "center";
      ctx.fillText(game.status === "paused" ? "PAUSED" : "GAME OVER", 120, 153);
      ctx.fillText("PRESS START", 120, 169);
      ctx.textAlign = "left";
    }
  }
}
