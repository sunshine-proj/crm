import {
  clearLCD,
  drawPixel,
  drawText,
  LCD
} from "../shared/Drawing";

import type { RacingSnapshot } from "./RacingGame";

const WIDTH = 240;
const HEIGHT = 320;

const ROAD_LEFT = 32;
const ROAD_RIGHT = 208;
const ROAD_WIDTH = ROAD_RIGHT - ROAD_LEFT;

const LANE_COUNT = 3;
const LANE_WIDTH = ROAD_WIDTH / LANE_COUNT;

const CAR_WIDTH = 22;
const CAR_HEIGHT = 34;

export class RacingRenderer {
  render(
    ctx: CanvasRenderingContext2D,
    game: RacingSnapshot
  ) {
    clearLCD(ctx, WIDTH, HEIGHT);

    this.drawHeader(ctx, game);
    this.drawRoad(ctx, game);
    this.drawTraffic(ctx, game);
    this.drawPlayer(ctx, game);
    this.drawFooter(ctx, game);

    if (
      game.status === "paused" ||
      game.status === "gameover"
    ) {
      this.drawOverlay(ctx, game);
    }
  }

  private drawHeader(
    ctx: CanvasRenderingContext2D,
    game: RacingSnapshot
  ) {
    drawText(ctx, "BRICK GAME", 10, 14, 10);
    drawText(ctx, "RACING", 230, 14, 10, "right");

    drawText(ctx, "SCORE", 10, 32, 8);
    drawText(
      ctx,
      String(game.score).padStart(5, "0"),
      10,
      43,
      9
    );

    drawText(ctx, "BEST", 230, 32, 8, "right");
    drawText(
      ctx,
      String(game.highScore).padStart(5, "0"),
      230,
      43,
      9,
      "right"
    );
  }

  private drawRoad(
    ctx: CanvasRenderingContext2D,
    game: RacingSnapshot
  ) {
    const roadTop = 52;
    const roadBottom = 302;

    // Road edges.
    ctx.fillStyle = LCD.pixel;
    ctx.fillRect(
      ROAD_LEFT - 3,
      roadTop,
      3,
      roadBottom - roadTop
    );

    ctx.fillRect(
      ROAD_RIGHT,
      roadTop,
      3,
      roadBottom - roadTop
    );

    // Dashed lane markings.
    for (let lane = 1; lane < LANE_COUNT; lane++) {
      const x = ROAD_LEFT + LANE_WIDTH * lane;

      for (
        let y = roadTop - 40 + game.roadOffset;
        y < roadBottom;
        y += 40
      ) {
        ctx.fillStyle = LCD.shadow;
        ctx.fillRect(x - 1, y, 2, 20);
      }
    }

    // Roadside texture for a sense of motion.
    for (
      let y = roadTop - 24 + game.roadOffset;
      y < roadBottom;
      y += 24
    ) {
      drawPixel(ctx, ROAD_LEFT - 11, y, 4);
      drawPixel(ctx, ROAD_RIGHT + 7, y, 4);
    }

    // Keep the playfield visually contained.
    ctx.strokeStyle = LCD.pixel;
    ctx.strokeRect(
      ROAD_LEFT - 4,
      roadTop,
      ROAD_WIDTH + 8,
      roadBottom - roadTop
    );
  }

  private drawCar(
    ctx: CanvasRenderingContext2D,
    centerX: number,
    y: number,
    color = LCD.pixel,
    player = false
  ) {
    const x = centerX - CAR_WIDTH / 2;

    ctx.fillStyle = color;

    // Main body.
    ctx.fillRect(
      x + 3,
      y + 2,
      CAR_WIDTH - 6,
      CAR_HEIGHT - 4
    );

    // Hood and trunk.
    ctx.fillRect(x + 1, y + 7, CAR_WIDTH - 2, 5);
    ctx.fillRect(x + 2, y + 23, CAR_WIDTH - 4, 6);

    // Windscreen.
    ctx.fillStyle = LCD.background;
    ctx.fillRect(x + 5, y + 11, CAR_WIDTH - 10, 7);

    // Rear window.
    ctx.fillRect(x + 5, y + 21, CAR_WIDTH - 10, 4);

    // Wheels.
    ctx.fillStyle = LCD.pixel;
    ctx.fillRect(x, y + 7, 3, 8);
    ctx.fillRect(x + CAR_WIDTH - 3, y + 7, 3, 8);
    ctx.fillRect(x, y + 23, 3, 7);
    ctx.fillRect(x + CAR_WIDTH - 3, y + 23, 3, 7);

    if (player) {
      // Player-car headlights.
      ctx.fillStyle = LCD.background;
      ctx.fillRect(x + 4, y + 1, 4, 2);
      ctx.fillRect(x + CAR_WIDTH - 8, y + 1, 4, 2);
    }
  }

  private drawTraffic(
    ctx: CanvasRenderingContext2D,
    game: RacingSnapshot
  ) {
    for (const car of game.traffic) {
      const centerX =
        ROAD_LEFT + LANE_WIDTH * (car.lane + 0.5);

      this.drawCar(
        ctx,
        centerX,
        car.y,
        car.color
      );
    }
  }

  private drawPlayer(
    ctx: CanvasRenderingContext2D,
    game: RacingSnapshot
  ) {
    this.drawCar(
      ctx,
      game.playerX,
      game.playerY,
      LCD.pixel,
      true
    );
  }

  private drawFooter(
    ctx: CanvasRenderingContext2D,
    game: RacingSnapshot
  ) {
    drawText(ctx, `LV ${game.level}`, 10, 315, 8);

    drawText(
      ctx,
      `SPD ${Math.round(game.speed)}`,
      230,
      315,
      8,
      "right"
    );
  }

  private drawOverlay(
    ctx: CanvasRenderingContext2D,
    game: RacingSnapshot
  ) {
    const boxX = 32;
    const boxY = 126;
    const boxW = 176;
    const boxH = 65;

    ctx.fillStyle = LCD.background;
    ctx.fillRect(boxX, boxY, boxW, boxH);

    ctx.strokeStyle = LCD.pixel;
    ctx.lineWidth = 2;
    ctx.strokeRect(boxX, boxY, boxW, boxH);
    ctx.lineWidth = 1;

    if (game.status === "paused") {
      drawText(ctx, "PAUSED", 120, 151, 13, "center");
      drawText(ctx, "PRESS START", 120, 174, 9, "center");
      return;
    }

    drawText(ctx, "CRASH!", 120, 148, 14, "center");

    drawText(
      ctx,
      `SCORE ${game.score}`,
      120,
      166,
      9,
      "center"
    );

    drawText(ctx, "PRESS START", 120, 183, 8, "center");
  }
}
