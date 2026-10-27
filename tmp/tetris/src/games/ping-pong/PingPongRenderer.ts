import {
  clearLCD,
  drawPixel,
  drawText
} from "../shared/Drawing";
import type { PingPongSnapshot } from "./PingPongGame";

export class PingPongRenderer {
  render(ctx: CanvasRenderingContext2D, game: PingPongSnapshot) {
    clearLCD(ctx, 240, 320);

    drawText(ctx, "BRICK GAME", 12, 14, 10);
    drawText(ctx, "PING-PONG", 228, 14, 10, "right");

    drawText(ctx, String(game.playerScore), 82, 43, 17, "center");
    drawText(ctx, String(game.aiScore), 158, 43, 17, "center");

    // Dashed center line.
    for (let y = 54; y < 300; y += 12) {
      ctx.fillStyle = "#68705d";
      ctx.fillRect(119, y, 2, 6);
    }

    ctx.strokeStyle = "#30372b";
    ctx.strokeRect(10, 52, 220, 248);

    // Paddles and ball.
    drawPixel(ctx, 22, game.playerY, 5);
    ctx.fillStyle = "#30372b";
    ctx.fillRect(22, game.playerY, 5, 42);

    ctx.fillRect(213, game.aiY, 5, 42);
    ctx.fillRect(
      game.ballX - game.ballRadius,
      game.ballY - game.ballRadius,
      game.ballRadius * 2,
      game.ballRadius * 2
    );

    drawText(ctx, "YOU", 22, 313, 8);
    drawText(ctx, "CPU", 218, 313, 8, "right");

    if (game.status === "paused" || game.status === "gameover") {
      ctx.fillStyle = "#b8bea8";
      ctx.fillRect(42, 126, 156, 62);
      ctx.strokeStyle = "#30372b";
      ctx.strokeRect(42, 126, 156, 62);

      drawText(
        ctx,
        game.status === "paused" ? "PAUSED" : game.winner ?? "GAME OVER",
        120,
        150,
        12,
        "center"
      );

      drawText(
        ctx,
        game.status === "paused" ? "PRESS START" : "PLAY AGAIN",
        120,
        172,
        9,
        "center"
      );
    }
  }
}
