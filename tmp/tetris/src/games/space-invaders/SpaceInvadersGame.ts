import type { GameAction, GameContext, GameInstance, GameStatus } from "../types";
import { SpaceInvadersRenderer } from "./SpaceInvadersRenderer";
import { saveHighScore } from "../../storage/highScores";

type Enemy = { x: number; y: number; alive: boolean };
type Shot = { x: number; y: number; enemy: boolean };

export type SpaceInvadersSnapshot = {
  playerX: number;
  enemies: Enemy[];
  shots: Shot[];
  score: number;
  lives: number;
  status: GameStatus;
};

export class SpaceInvadersGame implements GameInstance {
  private playerX = 120;
  private enemies: Enemy[] = [];
  private shots: Shot[] = [];
  private score = 0;
  private lives = 3;
  private status: GameStatus = "ready";
  private shotCooldown = 0;
  private enemyDirection = 1;
  private enemyTimer = 0;
  private renderer = new SpaceInvadersRenderer();

  constructor(private context: GameContext) { this.reset(); }

  start() { this.reset(); this.status = "playing"; }

  reset() {
    this.playerX = 120;
    this.enemies = [];
    this.shots = [];
    this.score = 0;
    this.lives = 3;
    this.shotCooldown = 0;
    this.enemyDirection = 1;
    this.enemyTimer = 0;
    this.status = "ready";

    for (let row = 0; row < 4; row++) {
      for (let col = 0; col < 8; col++) {
        this.enemies.push({
          x: 22 + col * 27,
          y: 48 + row * 19,
          alive: true
        });
      }
    }
  }

  update(deltaTime: number) {
    if (this.status !== "playing") return;
    const dt = Math.min(deltaTime, 40) / 1000;
    this.shotCooldown = Math.max(0, this.shotCooldown - dt);
    this.enemyTimer += dt;

    const alive = this.enemies.filter(e => e.alive);
    const speed = 14 + (32 - alive.length) * 1.5;

    for (const enemy of alive) enemy.x += this.enemyDirection * speed * dt;

    if (alive.some(e => e.x < 8 || e.x > 220)) {
      this.enemyDirection *= -1;
      for (const enemy of alive) enemy.y += 7;
    }

    if (this.enemyTimer > 1.2 && alive.length) {
      this.enemyTimer = 0;
      const shooter = alive[Math.floor(Math.random() * alive.length)];
      this.shots.push({ x: shooter.x + 5, y: shooter.y + 8, enemy: true });
    }

    for (const shot of this.shots) {
      shot.y += shot.enemy ? 100 * dt : -180 * dt;
    }

    for (const shot of this.shots.filter(s => !s.enemy)) {
      const target = alive.find(e =>
        shot.x >= e.x - 3 && shot.x <= e.x + 14 &&
        shot.y >= e.y && shot.y <= e.y + 12
      );
      if (target) {
        target.alive = false;
        shot.y = -100;
        this.score += 10;
        this.context.playSound("hit");
      }
    }

    for (const shot of this.shots.filter(s => s.enemy)) {
      if (Math.abs(shot.x - this.playerX) < 10 && shot.y >= 278 && shot.y <= 300) {
        shot.y = 400;
        this.lives--;
        this.context.playSound("hit");
        if (this.lives <= 0) {
          this.finish();
          return;
        }
      }
    }

    this.shots = this.shots.filter(s => s.y > 20 && s.y < 315);

    if (alive.some(e => e.y >= 270)) {
      this.finish();
      return;
    }

    if (this.enemies.every(e => !e.alive)) {
      this.score += 100;
      this.status = "victory";
      saveHighScore("space-invaders", this.score);
      this.context.playSound("victory");
    }
  }

  private finish() {
    this.status = "gameover";
    saveHighScore("space-invaders", this.score);
    this.context.playSound("gameover");
  }

  handleAction(action: GameAction, pressed: boolean) {
    if (!pressed || this.status !== "playing") return;
    if (action === "LEFT") this.playerX = Math.max(10, this.playerX - 12);
    if (action === "RIGHT") this.playerX = Math.min(230, this.playerX + 12);
    if ((action === "A" || action === "B") && this.shotCooldown <= 0) {
      this.shots.push({ x: this.playerX, y: 278, enemy: false });
      this.shotCooldown = 0.3;
      this.context.playSound("shoot");
    }
  }

  pause() { if (this.status === "playing") this.status = "paused"; }
  resume() { if (this.status === "paused") this.status = "playing"; }
  getStatus() { return this.status; }
  getScore() { return this.score; }
  getSnapshot(): SpaceInvadersSnapshot {
    return {
      playerX: this.playerX,
      enemies: this.enemies.map(e => ({ ...e })),
      shots: this.shots.map(s => ({ ...s })),
      score: this.score, lives: this.lives, status: this.status
    };
  }
  render(ctx: CanvasRenderingContext2D) { this.renderer.render(ctx, this.getSnapshot()); }
  destroy() {}
}
