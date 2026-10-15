import {
  BIRD_R, BIRD_X, FLAP_V, GRAVITY, GROUND_H, H, MAX_FALL, PIPE_GAP, PIPE_MARGIN,
  PIPE_SPACING, PIPE_SPEED, PIPE_W, RESTART_DELAY, W,
} from './constants';
import { GameState, Pipe, reset, START_Y } from './state';

const GROUND_Y = H - GROUND_H;

/** Seeded mulberry32 so runs are reproducible in tests. */
export function rand(s: GameState): number {
  s.seed = (s.seed + 0x6d2b79f5) | 0;
  let t = Math.imul(s.seed ^ (s.seed >>> 15), 1 | s.seed);
  t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
  return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
}

/** Single "flap" action: start, flap, or restart depending on phase. */
export function press(s: GameState): void {
  if (s.phase === 'ready') { s.phase = 'playing'; s.vy = FLAP_V; }
  else if (s.phase === 'playing') s.vy = FLAP_V;
  else if (s.deadFor >= RESTART_DELAY) reset(s);
}

export function circleHitsRect(cx: number, cy: number, r: number, rx: number, ry: number, rw: number, rh: number): boolean {
  const nx = Math.max(rx, Math.min(cx, rx + rw));
  const ny = Math.max(ry, Math.min(cy, ry + rh));
  const dx = cx - nx, dy = cy - ny;
  return dx * dx + dy * dy <= r * r;
}

function spawnPipe(s: GameState): void {
  const min = PIPE_MARGIN + PIPE_GAP / 2;
  const max = GROUND_Y - PIPE_MARGIN - PIPE_GAP / 2;
  const p: Pipe = { x: W + 10, gapY: min + rand(s) * (max - min), scored: false };
  s.pipes.push(p);
}

function die(s: GameState): void {
  s.phase = 'gameOver'; s.deadFor = 0; s.vy = Math.max(s.vy, 0);
  if (s.score > s.best) s.best = s.score;
}

function hitsPipe(s: GameState, p: Pipe): boolean {
  const top = p.gapY - PIPE_GAP / 2, bottom = p.gapY + PIPE_GAP / 2;
  return circleHitsRect(BIRD_X, s.y, BIRD_R, p.x, 0, PIPE_W, top)
      || circleHitsRect(BIRD_X, s.y, BIRD_R, p.x, bottom, PIPE_W, H);
}

/** Advance the simulation by a fixed dt. Mutates state in place (no per-frame allocation). */
export function step(s: GameState, dt: number): void {
  s.time += dt;

  if (s.phase === 'ready') {
    s.scroll += PIPE_SPEED * dt;
    s.y = START_Y + Math.sin(s.time * 5) * 6;
    return;
  }

  if (s.phase === 'gameOver') {
    s.deadFor += dt;
    if (s.y + BIRD_R < GROUND_Y) {
      s.vy = Math.min(s.vy + GRAVITY * dt, MAX_FALL);
      s.y = Math.min(s.y + s.vy * dt, GROUND_Y - BIRD_R);
    }
    return;
  }

  s.scroll += PIPE_SPEED * dt;
  s.vy = Math.min(s.vy + GRAVITY * dt, MAX_FALL);
  s.y += s.vy * dt;

  const last = s.pipes[s.pipes.length - 1];
  if (!last || last.x < W + 10 - PIPE_SPACING) spawnPipe(s);

  for (const p of s.pipes) {
    p.x -= PIPE_SPEED * dt;
    if (!p.scored && p.x + PIPE_W < BIRD_X - BIRD_R) { p.scored = true; s.score++; }
    if (hitsPipe(s, p)) { die(s); return; }
  }
  while (s.pipes.length && s.pipes[0].x + PIPE_W < 0) s.pipes.shift();

  if (s.y + BIRD_R >= GROUND_Y) { s.y = GROUND_Y - BIRD_R; die(s); }
  else if (s.y - BIRD_R <= 0) { s.y = BIRD_R; die(s); }
}
