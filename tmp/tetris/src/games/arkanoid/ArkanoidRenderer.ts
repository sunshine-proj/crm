import type { ArkanoidSnapshot } from "./ArkanoidGame";

export class ArkanoidRenderer {
  render(ctx: CanvasRenderingContext2D, game: ArkanoidSnapshot) {
    ctx.fillStyle = "#b8bea8";
    ctx.fillRect(0, 0, 240, 320);
    ctx.fillStyle = "#30372b";
    ctx.font = "10px monospace";
    ctx.fillText(`ARKANOID  ${game.score}`, 8, 15);
    ctx.fillText(`LIVES ${game.lives}`, 178, 15);

    for (const brick of game.bricks) {
      if (!brick.alive) continue;
      ctx.fillRect(brick.x, brick.y, 24, 10);
      ctx.clearRect(brick.x + 2, brick.y + 2, 20, 2);
    }

    ctx.fillStyle = "#30372b";
    ctx.fillRect(game.paddleX, 282, 40, 6);
    ctx.fillRect(game.ballX - 3, game.ballY - 3, 6, 6);

    if (game.status === "paused" || game.status === "gameover" || game.status === "victory") {
      ctx.fillStyle = "#b8bea8";
      ctx.fillRect(40, 135, 160, 48);
      ctx.strokeRect(40, 135, 160, 48);
      ctx.textAlign = "center";
      const message = game.status === "paused" ? "PAUSED" : game.status === "victory" ? "YOU WIN!" : "GAME OVER";
      ctx.fillText(message, 120, 154);
      ctx.fillText("PRESS START", 120, 171);
      ctx.textAlign = "left";
    } else if (!game.bricks.every(b => !b.alive) && game.ballY === 270) {
      ctx.fillText("A TO LAUNCH", 82, 308);
    }
  }
}
