import type {
  GameAction,
  GameContext,
  GameInstance,
  GameStatus
} from "../types";
import { PingPongRenderer } from "./PingPongRenderer";
import { saveHighScore } from "../../storage/highScores";

const WIDTH = 240;
const HEIGHT = 320;
const PADDLE_H = 42;
const PADDLE_W = 5;
const WIN_SCORE = 5;

export type PingPongSnapshot = {
  ballX: number;
  ballY: number;
  ballRadius: number;
  playerY: number;
  aiY: number;
  playerScore: number;
  aiScore: number;
  status: GameStatus;
  winner: string | null;
  highScore: number;
};

export class PingPongGame implements GameInstance {
  private ballX = WIDTH / 2;
  private ballY = HEIGHT / 2;
  private velocityX = 110;
  private velocityY = 65;

  private playerY = HEIGHT / 2 - PADDLE_H / 2;
  private aiY = HEIGHT / 2 - PADDLE_H / 2;

  private playerScore = 0;
  private aiScore = 0;
  private status: GameStatus = "ready";
  private winner: string | null = null;
  private highScore = 0;

  private readonly renderer = new PingPongRenderer();

  constructor(private readonly context: GameContext) {}

  start() {
    this.reset();
    this.status = "playing";
    this.serve(Math.random() > 0.5 ? 1 : -1);
  }

  reset() {
    this.ballX = WIDTH / 2;
    this.ballY = HEIGHT / 2;
    this.playerY = HEIGHT / 2 - PADDLE_H / 2;
    this.aiY = HEIGHT / 2 - PADDLE_H / 2;
    this.playerScore = 0;
    this.aiScore = 0;
    this.winner = null;
    this.velocityX = 110;
    this.velocityY = 65;
    this.status = "ready";
  }

  private serve(direction: number) {
    this.ballX = WIDTH / 2;
    this.ballY = HEIGHT / 2;
    this.velocityX = 110 * direction;
    this.velocityY = (Math.random() * 100 - 50);
  }

  update(deltaTime: number) {
    if (this.status !== "playing") return;

    // Limit the simulation timestep and use seconds for physics.
    const dt = Math.min(deltaTime, 32) / 1000;

    this.ballX += this.velocityX * dt;
    this.ballY += this.velocityY * dt;

    // Top and bottom wall collisions.
    if (this.ballY <= 10) {
      this.ballY = 10;
      this.velocityY = Math.abs(this.velocityY);
      this.context.playSound("hit");
    } else if (this.ballY >= HEIGHT - 10) {
      this.ballY = HEIGHT - 10;
      this.velocityY = -Math.abs(this.velocityY);
      this.context.playSound("hit");
    }

    // Computer paddle: tracks the ball, with limited speed.
    const aiCenter = this.aiY + PADDLE_H / 2;
    const target = this.ballY - PADDLE_H / 2;
    const aiSpeed = 100 + Math.min(this.playerScore + this.aiScore, 5) * 8;
    const difference = target - this.aiY;
    this.aiY += Math.max(
      -aiSpeed * dt,
      Math.min(aiSpeed * dt, difference)
    );
    this.aiY = this.clampPaddle(this.aiY);

    // Player paddle collision.
    if (
      this.velocityX < 0 &&
      this.ballX - 5 <= 22 + PADDLE_W &&
      this.ballX >= 22 &&
      this.ballY >= this.playerY - 5 &&
      this.ballY <= this.playerY + PADDLE_H + 5
    ) {
      this.ballX = 22 + PADDLE_W + 5;
      this.bounceFromPaddle(this.playerY);
      this.context.playSound("hit");
    }

    // AI paddle collision.
    if (
      this.velocityX > 0 &&
      this.ballX + 5 >= WIDTH - 22 - PADDLE_W &&
      this.ballX <= WIDTH - 22 &&
      this.ballY >= this.aiY - 5 &&
      this.ballY <= this.aiY + PADDLE_H + 5
    ) {
      this.ballX = WIDTH - 22 - PADDLE_W - 5;
      this.bounceFromPaddle(this.aiY);
      this.context.playSound("hit");
    }

    // The ball leaving either side awards a point.
    if (this.ballX < -5) {
      this.aiScore++;
      this.pointScored(-1);
    } else if (this.ballX > WIDTH + 5) {
      this.playerScore++;
      this.pointScored(1);
    }

    void aiCenter;
  }

  private bounceFromPaddle(paddleY: number) {
    const offset = (
      this.ballY - (paddleY + PADDLE_H / 2)
    ) / (PADDLE_H / 2);

    // Contact position changes the outgoing angle.
    this.velocityY = offset * 180;

    // Gradually increase the ball speed.
    const direction = this.velocityX > 0 ? -1 : 1;
    const speed = Math.min(
      Math.hypot(this.velocityX, this.velocityY) + 12,
      290
    );

    const angle = Math.atan2(this.velocityY, speed);
    this.velocityX = direction * Math.cos(angle) * speed;
    this.velocityY = Math.sin(angle) * speed;
  }

  private pointScored(direction: number) {
    this.context.playSound("score");

    if (
      this.playerScore >= WIN_SCORE ||
      this.aiScore >= WIN_SCORE
    ) {
      this.status = "gameover";
      this.winner = this.playerScore >= WIN_SCORE ? "YOU WIN" : "CPU WINS";

      if (this.winner === "YOU WIN") {
        this.highScore = saveHighScore("ping-pong", this.playerScore);
        this.context.playSound("victory");
      } else {
        this.context.playSound("gameover");
      }
      return;
    }

    this.serve(direction);
  }

  private clampPaddle(y: number) {
    return Math.max(25, Math.min(HEIGHT - PADDLE_H - 10, y));
  }

  handleAction(action: GameAction, pressed: boolean) {
    if (!pressed || this.status !== "playing") return;

    switch (action) {
      case "UP":
        this.playerY = this.clampPaddle(this.playerY - 20);
        break;
      case "DOWN":
        this.playerY = this.clampPaddle(this.playerY + 20);
        break;
      case "A":
        this.playerY = this.clampPaddle(this.playerY - 35);
        break;
      case "B":
        this.playerY = this.clampPaddle(this.playerY + 35);
        break;
      default:
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

  getSnapshot(): PingPongSnapshot {
    return {
      ballX: this.ballX,
      ballY: this.ballY,
      ballRadius: 4,
      playerY: this.playerY,
      aiY: this.aiY,
      playerScore: this.playerScore,
      aiScore: this.aiScore,
      status: this.status,
      winner: this.winner,
      highScore: this.highScore
    };
  }

  getStatus() {
    return this.status;
  }

  getScore() {
    return this.playerScore;
  }

  destroy() {
    // This game does not create its own timers or animation loops.
  }
}
