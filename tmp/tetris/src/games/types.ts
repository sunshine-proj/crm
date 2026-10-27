export type GameAction =
  | "UP"
  | "DOWN"
  | "LEFT"
  | "RIGHT"
  | "A"
  | "B";

export type GameStatus =
  | "ready"
  | "playing"
  | "paused"
  | "gameover"
  | "victory";

export interface GameContext {
  playSound(effect: string): void;
}

export interface GameInstance {
  start(): void;
  update(deltaTime: number): void;
  render(ctx: CanvasRenderingContext2D): void;
  handleAction(action: GameAction, pressed: boolean): void;
  pause(): void;
  resume(): void;
  reset(): void;
  destroy(): void;
  getStatus(): GameStatus;
  getScore(): number;
}

export interface GameDefinition {
  id: string;
  number: number;
  name: string;
  description: string;
  instructions: string[];
  create(context: GameContext): GameInstance;
}
