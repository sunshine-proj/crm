import {
  clearLCD,
  drawPixel,
  drawText
} from "../shared/Drawing";
import type { TetrisSnapshot } from "./TetrisGame";

export class TetrisRenderer {
  render(ctx: CanvasRenderingContext2D, game: TetrisSnapshot) {
    const width = 240;
    const height = 320;
    const cell = 12;
    const ox = 12;
    const oy = 43;

    clearLCD(ctx, width, height);

    drawText(ctx, "BRICK GAME", 12, 14, 10);
    drawText(ctx, "TETRIS", 228, 14, 10, "right");

    ctx.strokeStyle = "#30372b";
    ctx.strokeRect(ox - 1, oy - 1, 122, 242);

    for (let y = 0; y < 20; y++) {
      for (let x = 0; x < 10; x++) {
        if (game.board[y]?.[x]) {
          drawPixel(ctx, ox + x * cell, oy + y * cell, cell - 1);
        }
      }
    }

    const drawPiece = (piece: TetrisSnapshot["piece"]) => {
      if (!piece) return;

      piece.shape.forEach((row, y) => {
        row.forEach((filled, x) => {
          if (filled) {
            drawPixel(
              ctx,
              ox + (piece.x + x) * cell,
              oy + (piece.y + y) * cell,
              cell - 1
            );
          }
        });
      });
    };

    drawPiece(game.piece);

    drawText(ctx, "NEXT", 148, 54, 9);

    game.next?.shape.forEach((row, y) => {
      row.forEach((filled, x) => {
        if (filled) {
          drawPixel(ctx, 151 + x * 8, 65 + y * 8, 7);
        }
      });
    });

    drawText(ctx, "SCORE", 148, 119, 9);
    drawText(ctx, String(game.score).padStart(6, "0"), 148, 133, 8);
    drawText(ctx, "BEST", 148, 158, 9);
    drawText(ctx, String(game.highScore).padStart(6, "0"), 148, 172, 8);
    drawText(ctx, "LEVEL", 148, 198, 9);
    drawText(ctx, String(game.level).padStart(2, "0"), 148, 212, 10);
    drawText(ctx, "LINES", 148, 238, 9);
    drawText(ctx, String(game.lines).padStart(2, "0"), 148, 252, 10);

    if (game.status === "paused" || game.status === "gameover") {
      ctx.fillStyle = "#b8bea8";
      ctx.fillRect(27, 128, 105, 54);
      ctx.strokeStyle = "#30372b";
      ctx.strokeRect(27, 128, 105, 54);

      drawText(
        ctx,
        game.status === "paused" ? "PAUSED" : "GAME OVER",
        79,
        148,
        11,
        "center"
      );
      drawText(ctx, "PRESS START", 79, 166, 8, "center");
    }

    drawText(ctx, "MONO LCD", 12, 307, 8);
  }
}
