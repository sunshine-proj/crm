import type { GameAction, GameContext, GameInstance, GameStatus } from "../types";
import { TankRenderer } from "./TankRenderer";
import { saveHighScore } from "../../storage/highScores";

type TankEntity = { x: number; y: number; dir: number; cooldown: number };
type Bullet = { x: number; y: number; dx: number; dy: number; enemy: boolean };

export type TankSnapshot = {
  player: TankEntity;
  enemies: TankEntity[];
  bullets: Bullet[];
  score: number;
  highScore: number;
  status: GameStatus;
};

const W = 240;
const H = 320;

export class TankGame implements GameInstance {
  private player: TankEntity = { x: 112, y: 270, dir: 0, cooldown: 0 };
  private enemies: TankEntity[] = [];
  private bullets: Bullet[] = [];
  private score = 0;
  private highScore = 0;
  private spawnTimer = 0;
  private status: GameStatus = "ready";
  private renderer = new TankRenderer();

  constructor(private context: GameContext) { this.reset(); }

  start() { this.reset(); this.status = "playing"; }

  reset() {
    this.player = { x: 112, y: 270, dir: 0, cooldown: 0 };
    this.enemies = [];
    this.bullets = [];
    this.score = 0;
    this.spawnTimer = 0;
    this.status = "ready";
  }

  private shoot(tank: TankEntity, enemy: boolean) {
    if (tank.cooldown > 0) return;
    const dirs = [{ x: 0, y: -1 }, { x: 1, y: 0 }, { x: 0, y: 1 }, { x: -1, y: 0 }];
    const d = dirs[tank.dir];
    this.bullets.push({
      x: tank.x + 8, y: tank.y + 8,
      dx: d.x * 150, dy: d.y * 150, enemy
    });
    tank.cooldown = enemy ? 1.5 : 0.35;
    this.context.playSound("shoot");
  }

  update(deltaTime: number) {
    if (this.status !== "playing") return;
    const dt = Math.min(deltaTime, 40) / 1000;

    this.player.cooldown = Math.max(0, this.player.cooldown - dt);
    this.spawnTimer += dt;

    if (this.spawnTimer > 2 && this.enemies.length < 4) {
      this.spawnTimer = 0;
      this.enemies.push({
        x: 12 + Math.random() * 190, y: 12,
        dir: 2, cooldown: 0.8 + Math.random()
      });
    }

    for (const enemy of this.enemies) {
      enemy.cooldown -= dt;
      if (enemy.cooldown <= 0) this.shoot(enemy, true);

      // Move toward the player on either the horizontal or vertical axis.
      const dx = this.player.x - enemy.x;
      const dy = this.player.y - enemy.y;
      if (Math.abs(dx) > Math.abs(dy)) {
        enemy.dir = dx > 0 ? 1 : 3;
        enemy.x += Math.sign(dx) * 25 * dt;
      } else {
        enemy.dir = dy > 0 ? 2 : 0;
        enemy.y += Math.sign(dy) * 25 * dt;
      }

      enemy.x = Math.max(2, Math.min(W - 18, enemy.x));
      enemy.y = Math.max(2, Math.min(H - 18, enemy.y));
    }

    for (const bullet of this.bullets) {
      bullet.x += bullet.dx * dt;
      bullet.y += bullet.dy * dt;
    }

    this.bullets = this.bullets.filter(
      b => b.x >= 0 && b.x < W && b.y >= 0 && b.y < H
    );

    for (const bullet of this.bullets) {
      if (bullet.enemy) {
        if (Math.abs(bullet.x - this.player.x - 8) < 12 &&
            Math.abs(bullet.y - this.player.y - 8) < 12) {
          this.finish();
          return;
        }
      } else {
        const target = this.enemies.find(
          e => Math.abs(bullet.x - e.x - 8) < 12 &&
               Math.abs(bullet.y - e.y - 8) < 12
        );
        if (target) {
          this.enemies = this.enemies.filter(e => e !== target);
          this.bullets = this.bullets.filter(b => b !== bullet);
          this.score += 100;
          this.context.playSound("hit");
        }
      }
    }
  }

  handleAction(action: GameAction, pressed: boolean) {
    if (!pressed || this.status !== "playing") return;
    if (action === "A" || action === "B") { this.shoot(this.player, false); return; }

    const dirs: Partial<Record<GameAction, number>> = {
      UP: 0, RIGHT: 1, DOWN: 2, LEFT: 3
    };
    const dir = dirs[action];
    if (dir === undefined) return;
    this.player.dir = dir;

    const speed = 12;
    if (dir === 0) this.player.y -= speed;
    if (dir === 1) this.player.x += speed;
    if (dir === 2) this.player.y += speed;
    if (dir === 3) this.player.x -= speed;

    this.player.x = Math.max(2, Math.min(W - 18, this.player.x));
    this.player.y = Math.max(2, Math.min(H - 18, this.player.y));
  }

  private finish() {
    this.status = "gameover";
    this.highScore = saveHighScore("tank", this.score);
    this.context.playSound("gameover");
  }

  pause() { if (this.status === "playing") this.status = "paused"; }
  resume() { if (this.status === "paused") this.status = "playing"; }
  getStatus() { return this.status; }
  getScore() { return this.score; }
  getSnapshot(): TankSnapshot {
    return {
      player: { ...this.player },
      enemies: this.enemies.map(e => ({ ...e })),
      bullets: this.bullets.map(b => ({ ...b })),
      score: this.score,
      highScore: this.highScore,
      status: this.status
    };
  }
  render(ctx: CanvasRenderingContext2D) { this.renderer.render(ctx, this.getSnapshot()); }
  destroy() {}
}
