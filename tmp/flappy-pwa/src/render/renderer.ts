import { BIRD_R, BIRD_X, GROUND_H, H, PIPE_GAP, PIPE_W, W } from '../game/constants';
import type { GameState } from '../game/state';

export function createRenderer(canvas: HTMLCanvasElement) {
  const ctx = canvas.getContext('2d')!;
  let k = 1;

  function resize(): void {
    const fit = Math.min(window.innerWidth / W, window.innerHeight / H);
    const dpr = window.devicePixelRatio || 1;
    canvas.style.width = `${W * fit}px`;
    canvas.style.height = `${H * fit}px`;
    canvas.width = Math.round(W * fit * dpr);
    canvas.height = Math.round(H * fit * dpr);
    k = canvas.width / W; // logical -> backing-store scale
  }

  function text(str: string, x: number, y: number, size: number): void {
    ctx.font = `bold ${size}px system-ui, sans-serif`;
    ctx.textAlign = 'center';
    ctx.lineWidth = 4; ctx.strokeStyle = '#222'; ctx.strokeText(str, x, y);
    ctx.fillStyle = '#fff'; ctx.fillText(str, x, y);
  }

  function pipe(x: number, y: number, h: number, capAtBottom: boolean): void {
    ctx.fillStyle = '#5cb82e'; ctx.fillRect(x, y, PIPE_W, h);
    ctx.fillStyle = '#7ad63f'; ctx.fillRect(x + 4, y, 8, h);
    const capY = capAtBottom ? y + h - 20 : y;
    ctx.fillStyle = '#4a9a24'; ctx.fillRect(x - 3, capY, PIPE_W + 6, 20);
  }

  function draw(s: GameState, paused: boolean): void {
    ctx.setTransform(k, 0, 0, k, 0, 0);
    ctx.imageSmoothingEnabled = false;

    const sky = ctx.createLinearGradient(0, 0, 0, H);
    sky.addColorStop(0, '#70c5ce'); sky.addColorStop(1, '#c9ecf0');
    ctx.fillStyle = sky; ctx.fillRect(0, 0, W, H);

    for (const p of s.pipes) {
      const top = p.gapY - PIPE_GAP / 2, bottom = p.gapY + PIPE_GAP / 2;
      pipe(p.x, 0, top, true);
      pipe(p.x, bottom, H - GROUND_H - bottom, false);
    }

    const gy = H - GROUND_H;
    ctx.fillStyle = '#ded895'; ctx.fillRect(0, gy, W, GROUND_H);
    ctx.fillStyle = '#73bf2e'; ctx.fillRect(0, gy, W, 10);
    ctx.fillStyle = '#9ccc3c';
    const off = -(s.scroll % 24);
    for (let x = off; x < W; x += 24) ctx.fillRect(x, gy, 12, 10);

    ctx.save();
    ctx.translate(BIRD_X, s.y);
    ctx.rotate(Math.max(-0.45, Math.min(1.2, s.vy / 600)));
    ctx.fillStyle = '#f7d51d'; ctx.beginPath(); ctx.arc(0, 0, BIRD_R + 3, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = '#e8a317'; ctx.fillRect(-6, 2, 8, 6);
    ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.arc(6, -4, 4, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = '#000'; ctx.fillRect(7, -5, 2, 2);
    ctx.fillStyle = '#f26a2e'; ctx.fillRect(10, 0, 8, 5);
    ctx.restore();

    if (s.phase === 'playing') text(String(s.score), W / 2, 70, 44);
    if (s.phase === 'ready') {
      text('Flappy', W / 2, 150, 44);
      text('Tap / Space to flap', W / 2, 330, 18);
      if (s.best > 0) text(`Best ${s.best}`, W / 2, 360, 18);
    }
    if (s.phase === 'gameOver') {
      text('Game Over', W / 2, 170, 38);
      text(`Score ${s.score}   Best ${s.best}`, W / 2, 215, 20);
      if (s.deadFor > 0.45) text('Tap to restart', W / 2, 260, 18);
    }
    if (paused) {
      ctx.fillStyle = 'rgba(0,0,0,.5)'; ctx.fillRect(0, 0, W, H);
      text('Paused', W / 2, H / 2, 36);
      text('Tap to resume', W / 2, H / 2 + 36, 18);
    }
  }

  return { resize, draw };
}
