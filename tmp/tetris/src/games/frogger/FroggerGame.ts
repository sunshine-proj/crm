import type { GameAction, GameContext, GameInstance, GameStatus } from "../types";
import { FroggerRenderer } from "./FroggerRenderer";
import { saveHighScore } from "../../storage/highScores";

type Hazard = { x: number; y: number; speed: number; width: number };

export type FroggerSnapshot = {
  x: number;
  y: number;
  hazards: Hazard[];
  score: number;
  lives: number;
  status: GameStatus;
};

export class FroggerGame implements GameInstance {
  private x = 120;
  private y = 292;
  private hazards: Hazard[] = [];
  private score = 0;
  private lives = 3;
  private status: GameStatus = "ready";
  private spawnTimer = 0;
  private renderer = new FroggerRenderer();

  constructor(private context: GameContext) { this.reset(); }

  start() { this.reset(); this.status = "playing"; }

  reset() {
    this.x = 120; this.y = 292;
    this.score = 0; this.lives = 3;
    this.hazards = []; this.spawnTimer = 0;
    this.status = "ready";
    for (let row = 0; row < 5; row++) this.spawnRow(65 + row * 43);
  }

  private spawnRow(y: number) {
    const river = y < 185;
    this.hazards.push({
      x: Math.random() * 240,
      y,
      speed: (river ? 28 : 42) * (Math.random() > 0.5 ? 1 : -1),
      width: river ? 34 : 25
    });
  }

  update(deltaTime: number) {
    if (this.status !== "playing") return;
    const dt = Math.min(deltaTime, 40) / 1000;
    for (const h of this.hazards) {
      h.x += h.speed * dt;
      if (h.x > 250) h.x = -h.width;
      if (h.x < -h.width) h.x = 250;
    }

    // Roads are rows 6-9; rivers are rows 2-4.
    const onRoad = this.y >= 150 && this.y <= 245;
    const inRiver = this.y >= 65 && this.y < 150;
    const nearby = this.hazards.filter(h => Math.abs(h.y - this.y) < 12);
    const supported = nearby.some(h => this.x + 5 > h.x && this.x - 5 < h.x + h.width);

    if (onRoad && supported) {
      this.loseLife();
    } else if (inRiver && !supported) {
      this.loseLife();
    } else if (inRiver && supported) {
      // Ride a log or platform.
      const platform = nearby.find(h => this.x + 5 > h.x && this.x - 5 < h.x + h.width);
      if (platform) {
        this.x += platform.speed * dt;
        if (this.x < 0 || this.x > 240) this.loseLife();
      }
    }

    if (this.y < 55) {
      this.score += 100;
      this.context.playSound("score");
      this.x = 120;
      this.y = 292;
    }
  }

  private loseLife() {
    this.lives--;
    this.x = 120; this.y = 292;
    this.context.playSound("hit");
    if (this.lives <= 0) {
      this.status = "gameover";
      saveHighScore("frogger", this.score);
      this.context.playSound("gameover");
    }
  }

  handleAction(action: GameAction, pressed: boolean) {
    if (!pressed || this.status !== "playing") return;
    if (action === "LEFT") this.x = Math.max(4, this.x - 12);
    if (action === "RIGHT") this.x = Math.min(236, this.x + 12);
    if (action === "UP") this.y = Math.max(40, this.y - 22);
    if (action === "DOWN") this.y = Math.min(292, this.y + 22);
  }

  pause() { if (this.status === "playing") this.status = "paused"; }
  resume() { if (this.status === "paused") this.status = "playing"; }
  getStatus() { return this.status; }
  getScore() { return this.score; }
  getSnapshot(): FroggerSnapshot {
    return { x: this.x, y: this.y, hazards: this.hazards.map(h => ({ ...h })), score: this.score, lives: this.lives, status: this.status };
  }
  render(ctx: CanvasRenderingContext2D) { this.renderer.render(ctx, this.getSnapshot()); }
  destroy() {}
}
