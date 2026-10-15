import { H, GROUND_H } from './constants';

export type Phase = 'ready' | 'playing' | 'gameOver';
export interface Pipe { x: number; gapY: number; scored: boolean }
export interface GameState {
  phase: Phase;
  y: number;
  vy: number;
  pipes: Pipe[];
  score: number;
  best: number;
  time: number;
  scroll: number;
  deadFor: number;
  seed: number;
}

export const START_Y = (H - GROUND_H) / 2;

export function createState(best = 0, seed = 1): GameState {
  return { phase: 'ready', y: START_Y, vy: 0, pipes: [], score: 0, best, time: 0, scroll: 0, deadFor: 0, seed };
}

export function reset(s: GameState): void {
  s.phase = 'ready'; s.y = START_Y; s.vy = 0; s.pipes.length = 0;
  s.score = 0; s.deadFor = 0;
}
