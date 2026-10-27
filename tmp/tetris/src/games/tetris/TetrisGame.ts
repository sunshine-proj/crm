import type {
  GameAction,
  GameContext,
  GameInstance,
  GameStatus
} from "../types";
import { TetrisRenderer } from "./TetrisRenderer";
import { saveHighScore } from "../../storage/highScores";

const W = 10;
const H = 20;

const SHAPES = [
  [[1, 1, 1, 1]],
  [[1, 1], [1, 1]],
  [[0, 1, 0], [1, 1, 1]],
  [[0, 1, 1], [1, 1, 0]],
  [[1, 1, 0], [0, 1, 1]],
  [[1, 0, 0], [1, 1, 1]],
  [[0, 0, 1], [1, 1, 1]]
];

type Piece = {
  type: number;
  shape: number[][];
  x: number;
  y: number;
};

export type TetrisSnapshot = {
  board: number[][];
  piece: Piece | null;
  next: Piece | null;
  score: number;
  lines: number;
  level: number;
  highScore: number;
  status: GameStatus;
};

export class TetrisGame implements GameInstance {
  private board: number[][] = [];
  private piece: Piece | null = null;
  private next: Piece | null = null;
  private bag: number[] = [];

  private score = 0;
  private lines = 0;
  private level = 1;
  private fallTimer = 0;
  private status: GameStatus = "ready";
  private highScore = 0;

  private readonly renderer = new TetrisRenderer();

  constructor(private readonly context: GameContext) {}

  start() {
    this.reset();
    this.status = "playing";
  }

  reset() {
    this.board = Array.from({ length: H }, () => Array(W).fill(0));
    this.score = 0;
    this.lines = 0;
    this.level = 1;
    this.fallTimer = 0;
    this.bag = [];
    this.piece = null;
    this.next = this.randomPiece();
    this.spawnPiece();
    this.status = "ready";
  }

  private randomPiece(): Piece {
    if (!this.bag.length) {
      this.bag = SHAPES.map((_, index) => index);

      for (let i = this.bag.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [this.bag[i], this.bag[j]] = [this.bag[j], this.bag[i]];
      }
    }

    const type = this.bag.pop()!;
    const shape = SHAPES[type].map(row => [...row]);

    return {
      type,
      shape,
      x: Math.floor((W - shape[0].length) / 2),
      y: 0
    };
  }

  private spawnPiece() {
    this.piece = this.next;
    this.next = this.randomPiece();

    if (this.piece && this.collides(this.piece)) {
      this.finish();
    }
  }

  private collides(
    piece: Piece,
    dx = 0,
    dy = 0,
    shape = piece.shape
  ) {
    for (let y = 0; y < shape.length; y++) {
      for (let x = 0; x < shape[y].length; x++) {
        if (!shape[y][x]) continue;

        const bx = piece.x + x + dx;
        const by = piece.y + y + dy;

        if (bx < 0 || bx >= W || by >= H) return true;
        if (by >= 0 && this.board[by][bx]) return true;
      }
    }

    return false;
  }

  private move(dx: number, dy: number): boolean {
    if (!this.piece || this.status !== "playing") return false;

    if (!this.collides(this.piece, dx, dy)) {
      this.piece.x += dx;
      this.piece.y += dy;

      if (dy > 0) this.score += 1;
      return true;
    }

    if (dy > 0) this.lockPiece();
    return false;
  }

  private rotate() {
    if (!this.piece) return;

    const original = this.piece.shape;
    const rotated = original[0]
      .map((_, x) => original.map(row => row[x]).reverse());

    for (const offset of [0, -1, 1, -2, 2]) {
      if (!this.collides(this.piece, offset, 0, rotated)) {
        this.piece.x += offset;
        this.piece.shape = rotated;
        this.context.playSound("rotate");
        return;
      }
    }
  }

  private lockPiece() {
    const piece = this.piece;
    if (!piece) return;

    for (let y = 0; y < piece.shape.length; y++) {
      for (let x = 0; x < piece.shape[y].length; x++) {
        if (!piece.shape[y][x]) continue;

        const bx = piece.x + x;
        const by = piece.y + y;

        if (by < 0) {
          this.finish();
          return;
        }

        this.board[by][bx] = piece.type + 1;
      }
    }

    this.clearLines();
    this.spawnPiece();
    this.context.playSound("hit");
  }

  private clearLines() {
    let cleared = 0;

    for (let y = H - 1; y >= 0; y--) {
      if (this.board[y].every(cell => cell !== 0)) {
        this.board.splice(y, 1);
        this.board.unshift(Array(W).fill(0));
        cleared++;
        y++;
      }
    }

    if (cleared) {
      const points = [0, 100, 300, 500, 800];
      this.score += (points[cleared] ?? cleared * 300) * this.level;
      this.lines += cleared;
      this.level = 1 + Math.floor(this.lines / 10);
      this.context.playSound("score");
    }

    this.highScore = saveHighScore("tetris", this.score);
  }

  private finish() {
    this.status = "gameover";
    this.highScore = saveHighScore("tetris", this.score);
    this.context.playSound("gameover");
  }

  update(deltaTime: number) {
    if (this.status !== "playing") return;

    this.fallTimer += deltaTime;
    const interval = Math.max(90, 800 * Math.pow(0.82, this.level - 1));

    if (this.fallTimer >= interval) {
      this.fallTimer = 0;
      this.move(0, 1);
    }
  }

  handleAction(action: GameAction, pressed: boolean) {
    if (!pressed || this.status !== "playing") return;

    switch (action) {
      case "LEFT":
        if (this.move(-1, 0)) this.context.playSound("move");
        break;

      case "RIGHT":
        if (this.move(1, 0)) this.context.playSound("move");
        break;

      case "DOWN":
        this.move(0, 1);
        break;

      case "UP":
      case "A":
        this.rotate();
        break;

      case "B":
        if (this.piece) {
          while (!this.collides(this.piece, 0, 1)) {
            this.piece.y++;
            this.score += 2;
          }
          this.lockPiece();
        }
        break;
    }
  }

  pause() {
    if (this.status === "playing") this.status = "paused";
  }

  resume() {
    if (this.status === "paused") this.status = "playing";
  }

  render(ctx: CanvasRenderingContext2D) {
    this.renderer.render(ctx, this.getSnapshot());
  }

  getSnapshot(): TetrisSnapshot {
    return {
      board: this.board,
      piece: this.piece,
      next: this.next,
      score: this.score,
      lines: this.lines,
      level: this.level,
      highScore: this.highScore,
      status: this.status
    };
  }

  getStatus() {
    return this.status;
  }

  getScore() {
    return this.score;
  }

  destroy() {
    this.piece = null;
    this.next = null;
  }
}
