import type { GameAction, GameContext, GameInstance, GameStatus } from "../types";
import { ShootingGalleryRenderer } from "./ShootingGalleryRenderer";
import { saveHighScore } from "../../storage/highScores";

type Target = {
  x: number;
  y: number;
  speed: number;
  alive: boolean;
};

export type ShootingGallerySnapshot = {
  aimX: number;
  aimY: number;
  targets: Target[];
  score: number;
  ammo: number;
  timeLeft: number;
  status: GameStatus;
};

export class ShootingGalleryGame implements GameInstance {
  private aimX = 120;
  private aimY = 150;
  private targets: Target[] = [];
  private score = 0;
  private ammo = 10;
  private timeLeft = 45;
  private spawnTimer = 0;
  private reloadTimer = 0;
  private shotCooldown = 0;
  private status: GameStatus = "ready";
  private renderer = new ShootingGalleryRenderer();

  constructor(private context: GameContext) {
    this.reset();
  }

  start() {
    this.reset();
    this.status = "playing";
  }

  reset() {
    this.aimX = 120;
    this.aimY = 150;
    this.targets = [];
    this.score = 0;
    this.ammo = 10;
    this.timeLeft = 45;
    this.spawnTimer = 0;
    this.reloadTimer = 0;
    this.shotCooldown = 0;
    this.status = "ready";
  }

  update(deltaTime: number) {
    if (this.status !== "playing") return;

    const dt = Math.min(deltaTime, 40) / 1000;

    this.timeLeft -= dt;
    this.spawnTimer += dt;
    this.reloadTimer += dt;
    this.shotCooldown = Math.max(0, this.shotCooldown - dt);

    if (this.spawnTimer >= 0.9) {
      this.spawnTimer = 0;

      const direction = Math.random() > 0.5 ? 1 : -1;

      this.targets.push({
        x: direction > 0 ? -10 : 250,
        y: 65 + Math.random() * 155,
        speed: direction * (35 + Math.random() * 35),
        alive: true
      });
    }

    for (const target of this.targets) {
      target.x += target.speed * dt;
    }

    this.targets = this.targets.filter(
      target =>
        target.alive &&
        target.x > -20 &&
        target.x < 260
    );

    if (this.ammo < 10 && this.reloadTimer >= 1.5) {
      this.ammo++;
      this.reloadTimer = 0;
    }

    if (this.timeLeft <= 0) {
      this.timeLeft = 0;
      this.status = "gameover";
      saveHighScore("shooting-gallery", this.score);
      this.context.playSound("gameover");
    }
  }

  private shoot() {
    if (this.ammo <= 0 || this.shotCooldown > 0) return;

    this.ammo--;
    this.shotCooldown = 0.25;
    this.reloadTimer = 0;
    this.context.playSound("shoot");

    const target = this.targets.find(
      t => Math.hypot(t.x - this.aimX, t.y - this.aimY) < 16
    );

    if (target) {
      target.alive = false;
      this.score += 10;
      this.context.playSound("hit");
    }
  }

  handleAction(action: GameAction, pressed: boolean) {
    if (!pressed || this.status !== "playing") return;

    switch (action) {
      case "LEFT":
        this.aimX = Math.max(8, this.aimX - 8);
        break;
      case "RIGHT":
        this.aimX = Math.min(232, this.aimX + 8);
        break;
      case "UP":
        this.aimY = Math.max(45, this.aimY - 8);
        break;
      case "DOWN":
        this.aimY = Math.min(250, this.aimY + 8);
        break;
      case "A":
      case "B":
        this.shoot();
        break;
    }
  }

  pause() {
    if (this.status === "playing") this.status = "paused";
  }

  resume() {
    if (this.status === "paused") this.status = "playing";
  }

  getStatus() {
    return this.status;
  }

  getScore() {
    return this.score;
  }

  getSnapshot(): ShootingGallerySnapshot {
    return {
      aimX: this.aimX,
      aimY: this.aimY,
      targets: this.targets.map(t => ({ ...t })),
      score: this.score,
      ammo: this.ammo,
      timeLeft: this.timeLeft,
      status: this.status
    };
  }

  render(ctx: CanvasRenderingContext2D) {
    this.renderer.render(ctx, this.getSnapshot());
  }

  destroy() {}
}
