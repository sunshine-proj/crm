import type { SnakeSnapshot } from "./SnakeGame";

export class SnakeRenderer {
  render(ctx: CanvasRenderingContext2D, game: SnakeSnapshot) {
    ctx.fillStyle = "#b8bea8";
    ctx.fillRect(0, 0, 240, 320);

    ctx.fillStyle = "#30372b";
    ctx.font = "10px monospace";
    ctx.fillText("SNAKE", 10, 15);
    ctx.fillText(`SCORE ${game.score}`, 10, 29);

    ctx.strokeStyle = "#30372b";
    ctx.strokeRect(18, 42, 204, 244);

    for (const [index, point] of game.snake.entries()) {
      ctx.fillStyle = index === 0 ? "#20251c" : "#4d5544";
      ctx.fillRect(20 + point.x * 10, 44 + point.y * 10, 9, 9);
    }

    ctx.fillStyle = "#30372b";
    ctx.fillRect(20 + game.food.x * 10, 44 + game.food.y * 10, 9, 9);

    if (game.status === "gameover" || game.status === "paused") {
      ctx.fillStyle = "#b8bea8";
      ctx.fillRect(45, 130, 150, 52);
      ctx.strokeRect(45, 130, 150, 52);
      ctx.textAlign = "center";
      ctx.fillText(game.status === "paused" ? "PAUSED" : "GAME OVER", 120, 151);
      ctx.fillText("PRESS START", 120, 169);
      ctx.textAlign = "left";
    }
  }
}
