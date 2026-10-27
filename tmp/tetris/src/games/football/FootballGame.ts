import type { GameAction, GameContext, GameInstance, GameStatus } from "../types";
import { FootballRenderer } from "./FootballRenderer";
import { saveHighScore } from "../../storage/highScores";

export type FootballSnapshot = {
  playerX: number; playerY: number;
  cpuX: number; cpuY: number;
  ballX: number; ballY: number;
  ballVX: number; ballVY: number;
  playerScore: number; cpuScore: number;
  status: GameStatus;
};

export class FootballGame implements GameInstance {
  private playerX = 120;
  private playerY = 250;
  private cpuX = 120;
  private cpuY = 65;
  private ballX = 120;
  private ballY = 160;
  private ballVX = 0;
  private ballVY = 0;
  private playerScore = 0;
  private cpuScore = 0;
  private status: GameStatus = "ready";
  private kickCooldown = 0;
  private matchTime = 60;
  private renderer = new FootballRenderer();

  constructor(private context: GameContext) { this.reset(); }

  start() { this.reset(); this.status = "playing"; }

  reset() {
    this.playerX = 120; this.playerY = 250;
    this.cpuX = 120; this.cpuY = 65;
    this.ballX = 120; this.ballY = 160;
    this.ballVX = 0; this.ballVY = 0;
    this.playerScore = 0; this.cpuScore = 0;
    this.kickCooldown = 0; this.matchTime = 60;
    this.status = "ready";
  }

  update(deltaTime: number) {
    if (this.status !== "playing") return;
    const dt = Math.min(deltaTime, 40) / 1000;
    this.matchTime -= dt;
    this.kickCooldown = Math.max(0, this.kickCooldown - dt);

    // CPU follows the ball with a small speed advantage.
    this.cpuX += Math.sign(this.ballX - this.cpuX) * 35 * dt;
    this.cpuY += Math.sign(this.ballY - this.cpuY) * 35 * dt;
    this.cpuX = Math.max(15, Math.min(225, this.cpuX));
    this.cpuY = Math.max(40, Math.min(135, this.cpuY));

    this.ballX += this.ballVX * dt;
    this.ballY += this.ballVY * dt;
    this.ballVX *= Math.pow(0.97, dt * 60);
    this.ballVY *= Math.pow(0.97, dt * 60);

    if (this.ballX < 8 || this.ballX > 232) this.ballVX *= -0.8;
    this.ballX = Math.max(8, Math.min(232, this.ballX));

    if (this.ballY < 30) {
      if (this.ballX > 90 && this.ballX < 150) this.playerScore++;
      else this.ballVY *= -0.8;
      this.resetBall();
    } else if (this.ballY > 290) {
      if (this.ballX > 90 && this.ballX < 150) this.cpuScore++;
      else this.ballVY *= -0.8;
      this.resetBall();
    }

    if (Math.hypot(this.ballX - this.playerX, this.ballY - this.playerY) < 17) {
      this.ballVX += (this.ballX - this.playerX) * 3;
      this.ballVY = -Math.abs(this.ballVY) - 35;
    }

    if (Math.hypot(this.ballX - this.cpuX, this.ballY - this.cpuY) < 17) {
      this.ballVX += (this.ballX - this.cpuX) * 2;
      this.ballVY = Math.abs(this.ballVY) + 25;
    }

    if (this.playerScore >= 5 || this.cpuScore >= 5 || this.matchTime <= 0) {
      this.status = "gameover";
      saveHighScore("football", this.playerScore);
      this.context.playSound("victory");
    }
  }

  private resetBall() {
    this.ballX = 120; this.ballY = 160;
    this.ballVX = (Math.random() - 0.5) * 70;
    this.ballVY = 0;
    this.context.playSound("score");
  }

  handleAction(action: GameAction, pressed: boolean) {
    if (!pressed || this.status !== "playing") return;
    if (action === "LEFT") this.playerX = Math.max(15, this.playerX - 12);
    if (action === "RIGHT") this.playerX = Math.min(225, this.playerX + 12);
    if (action === "UP") this.playerY = Math.max(155, this.playerY - 12);
    if (action === "DOWN") this.playerY = Math.min(280, this.playerY + 12);

    if ((action === "A" || action === "B") && this.kickCooldown <= 0) {
      const distance = Math.hypot(this.ballX - this.playerX, this.ballY - this.playerY);
      if (distance < 45) {
        this.ballVX = (this.ballX - this.playerX) * 4;
        this.ballVY = -190;
        this.kickCooldown = 0.5;
        this.context.playSound("shoot");
      }
    }
  }

  pause() { if (this.status === "playing") this.status = "paused"; }
  resume() { if (this.status === "paused") this.status = "playing"; }
  getStatus() { return this.status; }
  getScore() { return this.playerScore; }
  getSnapshot(): FootballSnapshot {
    return {
      playerX: this.playerX, playerY: this.playerY,
      cpuX: this.cpuX, cpuY: this.cpuY,
      ballX: this.ballX, ballY: this.ballY,
      ballVX: this.ballVX, ballVY: this.ballVY,
      playerScore: this.playerScore, cpuScore: this.cpuScore,
      status: this.status
    };
  }
  render(ctx: CanvasRenderingContext2D) { this.renderer.render(ctx, this.getSnapshot()); }
  destroy() {}
}
