import type { GameAction, GameContext, GameInstance, GameStatus } from "../types";
import { ArkanoidRenderer } from "./ArkanoidRenderer";
import { saveHighScore } from "../../storage/highScores";

type Brick = { x: number; y: number; alive: boolean };

export type ArkanoidSnapshot = {
  paddleX: number;
  ballX: number;
  ballY: number;
  bricks: Brick[];
  score: number;
  lives: number;
  status: GameStatus;
};

export class ArkanoidGame implements GameInstance {
  private paddleX = 100;
  private ballX = 120;
  private ballY = 270;
  private vx = 90;
  private vy = -100;
  private launched = false;
  private bricks: Brick[] = [];
  private score = 0;
  private lives = 3;
  private status: GameStatus = "ready";
  private renderer = new ArkanoidRenderer();

  constructor(private context: GameContext) { this.reset(); }

  start() { this.reset(); this.status = "playing"; }

  reset() {
    this.paddleX = 100;
    this.ballX = 120;
    this.ballY = 270;
    this.vx = 90;
    this.vy = -100;
    this.launched = false;
    this.score = 0;
    this.lives = 3;
    this.bricks = [];
    for (let row = 0; row < 5; row++) {
      for (let col = 0; col < 8; col++) {
        this.bricks.push({ x: 12 + col * 27, y: 55 + row * 14, alive: true });
      }
    }
    this.status = "ready";
  }

  update(deltaTime: number) {
    if (this.status !== "playing" || !this.launched) return;
    const dt = Math.min(deltaTime, 40) / 1000;

    this.ballX += this.vx * dt;
    this.ballY += this.vy * dt;

    if (this.ballX < 5 || this.ballX > 235) this.vx *= -1;
    if (this.ballY < 35) this.vy = Math.abs(this.vy);

    if (this.ballY >= 275 && this.ballY <= 286 &&
        this.ballX >= this.paddleX && this.ballX <= this.paddleX + 40 &&
        this.vy > 0) {
      this.vy = -Math.abs(this.vy);
      this.vx = ((this.ballX - this.paddleX - 20) / 20) * 140;
      this.context.playSound("hit");
    }

    for (const brick of this.bricks) {
      if (brick.alive &&
          this.ballX >= brick.x && this.ballX <= brick.x + 24 &&
          this.ballY >= brick.y && this.ballY <= brick.y + 10 &&
          this.vy < 0) {
        brick.alive = false;
        this.vy = Math.abs(this.vy);
        this.score += 10;
        this.context.playSound("hit");
        break;
      }
    }

    if (this.bricks.every(b => !b.alive)) {
      this.highScoreSave();
      this.status = "victory";
      this.context.playSound("victory");
    }

    if (this.ballY > 320) {
      this.lives--;
      if (this.lives <= 0) {
        this.status = "gameover";
        this.highScoreSave();
        this.context.playSound("gameover");
      } else {
        this.ballX = this.paddleX + 20;
        this.ballY = 268;
        this.vx = 90;
        this.vy = -100;
        this.launched = false;
      }
    }
  }

  private highScoreSave() {
    saveHighScore("arkanoid", this.score);
  }

  handleAction(action: GameAction, pressed: boolean) {
    if (!pressed || this.status !== "playing") return;
    if (action === "LEFT") this.paddleX = Math.max(4, this.paddleX - 16);
    if (action === "RIGHT") this.paddleX = Math.min(196, this.paddleX + 16);
    if (action === "A" || action === "B") this.launched = true;
  }

  pause() { if (this.status === "playing") this.status = "paused"; }
  resume() { if (this.status === "paused") this.status = "playing"; }
  getStatus() { return this.status; }
  getScore() { return this.score; }
  getSnapshot(): ArkanoidSnapshot {
    return {
      paddleX: this.paddleX, ballX: this.ballX, ballY: this.ballY,
      bricks: this.bricks.map(b => ({ ...b })),
      score: this.score, lives: this.lives, status: this.status
    };
  }
  render(ctx: CanvasRenderingContext2D) { this.renderer.render(ctx, this.getSnapshot()); }
  destroy() {}
}
