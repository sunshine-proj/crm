import type {
  GameAction,
  GameContext,
  GameInstance,
  GameStatus
} from "../types";

import { RacingRenderer } from "./RacingRenderer";
import { saveHighScore } from "../../storage/highScores";

const WIDTH = 240;
const HEIGHT = 320;

const ROAD_LEFT = 32;
const ROAD_RIGHT = 208;
const ROAD_WIDTH = ROAD_RIGHT - ROAD_LEFT;

const LANE_COUNT = 3;
const LANE_WIDTH = ROAD_WIDTH / LANE_COUNT;

const CAR_WIDTH = 22;
const CAR_HEIGHT = 34;

const PLAYER_Y = 254;

const INITIAL_SPEED = 105;
const MAX_SPEED = 245;

const TRAFFIC_COLORS = [
  "#30372b",
  "#68705d",
  "#30372b",
  "#50594a"
];

type TrafficCar = {
  lane: number;
  y: number;
  speed: number;
  color: string;
};

export type RacingSnapshot = {
  playerLane: number;
  playerX: number;
  playerY: number;
  traffic: TrafficCar[];
  roadOffset: number;
  score: number;
  highScore: number;
  speed: number;
  level: number;
  status: GameStatus;
};

export class RacingGame implements GameInstance {
  private playerLane = 1;

  private traffic: TrafficCar[] = [];

  private roadOffset = 0;
  private spawnTimer = 0;
  private score = 0;
  private elapsedTime = 0;

  private speed = INITIAL_SPEED;
  private level = 1;

  private status: GameStatus = "ready";
  private highScore = 0;

  private readonly renderer = new RacingRenderer();

  constructor(
    private readonly context: GameContext
  ) {}

  start() {
    this.reset();
    this.status = "playing";
    this.context.playSound("click");
  }

  reset() {
    this.playerLane = 1;
    this.traffic = [];

    this.roadOffset = 0;
    this.spawnTimer = 0;
    this.score = 0;
    this.elapsedTime = 0;

    this.speed = INITIAL_SPEED;
    this.level = 1;

    this.highScore = saveHighScore("racing", 0);
    this.status = "ready";
  }

  /**
   * Convert a lane number into the horizontal center
   * of that lane.
   */
  private laneCenter(lane: number): number {
    return ROAD_LEFT + LANE_WIDTH * (lane + 0.5);
  }

  /**
   * Move traffic and road markings to create the
   * illusion of forward movement.
   */
  update(deltaTime: number) {
    if (this.status !== "playing") return;

    // Prevent enormous updates when a browser tab resumes.
    const dt = Math.min(deltaTime, 50) / 1000;

    this.elapsedTime += dt;

    // Increase speed and difficulty over time.
    this.level = 1 + Math.floor(this.score / 500);

    this.speed = Math.min(
      INITIAL_SPEED + (this.level - 1) * 15,
      MAX_SPEED
    );

    // Move road markings.
    this.roadOffset = (
      this.roadOffset + this.speed * dt
    ) % 40;

    // Distance-based scoring.
    this.score += Math.round(this.speed * dt * 0.1);

    // Generate traffic.
    this.spawnTimer += dt;

    const spawnInterval = Math.max(
      0.52,
      1.15 - (this.level - 1) * 0.06
    );

    if (this.spawnTimer >= spawnInterval) {
      this.spawnTimer = 0;
      this.spawnTraffic();
    }

    // Move traffic toward the bottom of the display.
    for (const car of this.traffic) {
      car.y += (this.speed + car.speed) * dt;
    }

    // Remove traffic that has left the screen.
    this.traffic = this.traffic.filter(
      car => car.y < HEIGHT + CAR_HEIGHT
    );

    // Detect collisions.
    if (this.checkCollision()) {
      this.finish();
    }
  }

  private spawnTraffic() {
    const availableLanes = [0, 1, 2].filter(lane => {
      // Avoid spawning directly on top of a nearby car.
      return !this.traffic.some(
        car => car.lane === lane && car.y < 65
      );
    });

    if (availableLanes.length === 0) return;

    const lane = availableLanes[
      Math.floor(Math.random() * availableLanes.length)
    ];

    const color = TRAFFIC_COLORS[
      Math.floor(Math.random() * TRAFFIC_COLORS.length)
    ];

    this.traffic.push({
      lane,
      y: -CAR_HEIGHT,
      speed: 15 + Math.random() * 25,
      color
    });
  }

  private checkCollision(): boolean {
    const playerX = this.laneCenter(this.playerLane);

    const playerLeft = playerX - CAR_WIDTH / 2 + 3;
    const playerRight = playerX + CAR_WIDTH / 2 - 3;

    const playerTop = PLAYER_Y + 3;
    const playerBottom = PLAYER_Y + CAR_HEIGHT - 3;

    return this.traffic.some(car => {
      const carX = this.laneCenter(car.lane);

      const carLeft = carX - CAR_WIDTH / 2 + 3;
      const carRight = carX + CAR_WIDTH / 2 - 3;

      const carTop = car.y + 3;
      const carBottom = car.y + CAR_HEIGHT - 3;

      return (
        playerLeft < carRight &&
        playerRight > carLeft &&
        playerTop < carBottom &&
        playerBottom > carTop
      );
    });
  }

  private finish() {
    if (this.status !== "playing") return;

    this.status = "gameover";

    this.highScore = saveHighScore(
      "racing",
      this.score
    );

    this.context.playSound("gameover");
  }

  /**
   * The handheld has three lanes.
   * LEFT and RIGHT change the player's lane.
   */
  handleAction(
    action: GameAction,
    pressed: boolean
  ) {
    if (!pressed || this.status !== "playing") return;

    switch (action) {
      case "LEFT":
        if (this.playerLane > 0) {
          this.playerLane--;
          this.context.playSound("move");
        }
        break;

      case "RIGHT":
        if (this.playerLane < LANE_COUNT - 1) {
          this.playerLane++;
          this.context.playSound("move");
        }
        break;

      // UP and DOWN are intentionally ignored.
      // This keeps the controls simple and predictable.
      default:
        break;
    }
  }

  pause() {
    if (this.status === "playing") {
      this.status = "paused";
    }
  }

  resume() {
    if (this.status === "paused") {
      this.status = "playing";
    }
  }

  render(ctx: CanvasRenderingContext2D) {
    this.renderer.render(
      ctx,
      this.getSnapshot()
    );
  }

  getSnapshot(): RacingSnapshot {
    return {
      playerLane: this.playerLane,
      playerX: this.laneCenter(this.playerLane),
      playerY: PLAYER_Y,
      traffic: this.traffic.map(car => ({ ...car })),
      roadOffset: this.roadOffset,
      score: this.score,
      highScore: this.highScore,
      speed: this.speed,
      level: this.level,
      status: this.status
    };
  }

  getStatus(): GameStatus {
    return this.status;
  }

  getScore(): number {
    return this.score;
  }

  destroy() {
    this.traffic = [];
  }
}
