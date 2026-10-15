import { describe, expect, it } from 'vitest';
import { BIRD_X, FLAP_V, GROUND_H, H, PIPE_W } from '../../src/game/constants';
import { createState } from '../../src/game/state';
import { circleHitsRect, press, step } from '../../src/game/update';

describe('game logic', () => {
  it('flap starts the game and sets a fixed upward velocity', () => {
    const s = createState();
    press(s);
    expect(s.phase).toBe('playing');
    expect(s.vy).toBe(FLAP_V);
    s.vy = 300; press(s);
    expect(s.vy).toBe(FLAP_V);
  });

  it('gravity integrates similarly across dt choices', () => {
    const a = createState(), b = createState();
    press(a); press(b);
    for (let i = 0; i < 30; i++) step(a, 1 / 120);
    for (let i = 0; i < 15; i++) step(b, 1 / 60);
    expect(Math.abs(a.y - b.y)).toBeLessThan(8);
  });

  it('dies on the ground', () => {
    const s = createState(); press(s);
    for (let i = 0; i < 1200 && s.phase === 'playing'; i++) step(s, 1 / 120);
    expect(s.phase).toBe('gameOver');
    expect(s.y).toBeLessThanOrEqual(H - GROUND_H);
  });

  it('circle vs rect, including edge touch', () => {
    expect(circleHitsRect(0, 0, 10, 10, -5, 5, 10)).toBe(true);
    expect(circleHitsRect(0, 0, 10, 11, -5, 5, 10)).toBe(false);
  });

  it('scores once per pipe pair', () => {
    const s = createState(0, 42); press(s);
    s.pipes.push({ x: BIRD_X - 40 - PIPE_W, gapY: s.y, scored: false });
    s.vy = 0;
    step(s, 1 / 120); step(s, 1 / 120);
    expect(s.score).toBe(1);
  });

  it('is deterministic for a given seed', () => {
    const run = () => {
      const s = createState(0, 7); press(s);
      for (let i = 0; i < 300 && s.phase === 'playing'; i++) { step(s, 1 / 120); if (i % 50 === 0) press(s); }
      return s.pipes.map((p) => p.gapY);
    };
    expect(run()).toEqual(run());
  });
});
