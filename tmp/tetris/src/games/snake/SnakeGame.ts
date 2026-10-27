import type { GameAction, GameContext, GameInstance, GameStatus } from "../types";
import { SnakeRenderer } from "./SnakeRenderer";
import { saveHighScore } from "../../storage/highScores";

const COLS = 20;
const ROWS = 24;
const CELL = 10;

type Point = { x: number; y: number };

export type SnakeSnapshot = {
  snake: Point[];
  food: Point;
  score: number;
  highScore: number;
  status: GameStatus;
};

export class SnakeGame implements GameInstance {
  private snake: Point[] = [];
  private food: Point = { x: 10, y: 12 };
  private direction: Point = { x: 1, y: 0 };
  private nextDirection: Point = { x: 1, y: 0 };
  private elapsed = 0;
  private score = 0;
  private highScore = 0;
  private status: GameStatus = "ready";
  private renderer = new SnakeRenderer();

  constructor(private context: GameContext) {
    this.reset();
  }

  start() {
    this.reset();
    this.status = "playing";
  }

  reset() {
    this.snake = [{ x: 10, y: 12 }, { x: 9, y: 12 }, { x: 8, y: 12 }];
    this.direction = { x: 1, y: 0 };
    this.nextDirection = { x: 1, y: 0 };
    this.elapsed = 0;
    this.score = 0;
    this.food = this.randomFood();
    this.status = "ready";
  }

  private randomFood(): Point {
    let point: Point;
    do {
      point = {
        x: Math.floor(Math.random() * COLS),
        y: Math.floor(Math.random() * ROWS)
      };
    } while (this.snake.some(p => p.x === point.x && p.y === point.y));
    return point;
  }

  update(deltaTime: number) {
    if (this.status !== "playing") return;
    this.elapsed += Math.min(deltaTime, 100);

    const interval = Math.max(75, 150 - this.score * 2);
    if (this.elapsed < interval) return;
    this.elapsed %= interval;

    this.direction = this.nextDirection;
    const head = this.snake[0];
    const next = {
      x: head.x + this.direction.x,
      y: head.y + this.direction.y
    };

    const eating = next.x === this.food.x && next.y === this.food.y;
    const body = eating ? this.snake : this.snake.slice(0, -1);

    if (
      next.x < 0 || next.x >= COLS ||
      next.y < 0 || next.y >= ROWS ||
      body.some(p => p.x === next.x && p.y === next.y)
    ) {
      this.finish();
      return;
    }

    this.snake.unshift(next);

    if (eating) {
      this.score += 10;
      this.context.playSound("score");
      this.food = this.randomFood();
    } else {
      this.snake.pop();
    }
  }

  private finish() {
    this.status = "gameover";
    this.highScore = saveHighScore("snake", this.score);
    this.context.playSound("gameover");
  }

  handleAction(action: GameAction, pressed: boolean) {
    if (!pressed || this.status !== "playing") return;

    const directions: Partial<Record<GameAction, Point>> = {
      UP: { x: 0, y: -1 },
      DOWN: { x: 0, y: 1 },
      LEFT: { x: -1, y: 0 },
      RIGHT: { x: 1, y: 0 }
    };

    const next = directions[action];
    if (!next) return;

    if (next.x === -this.direction.x && next.y === -this.direction.y) return;
    this.nextDirection = next;
  }

  pause() { if (this.status === "playing") this.status = "paused"; }
  resume() { if (this.status === "paused") this.status = "playing"; }
  getStatus() { return this.status; }
  getScore() { return this.score; }
  getSnapshot(): SnakeSnapshot {
    return {
      snake: this.snake.map(p => ({ ...p })),
      food: { ...this.food },
      score: this.score,
      highScore: this.highScore,
      status: this.status
    };
  }
  render(ctx: CanvasRenderingContext2D) {
    this.renderer.render(ctx, this.getSnapshot());
  }
  destroy() {}
}
