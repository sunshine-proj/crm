import './styles.css';
import { STEP } from './game/constants';
import { loadBest, saveBest } from './game/score';
import { createState } from './game/state';
import { press, step } from './game/update';
import { setupInput } from './input/input';
import { setupInstall } from './pwa/install-prompt';
import { setupSW } from './pwa/register-sw';
import { createRenderer } from './render/renderer';

const $ = <T extends HTMLElement>(id: string) => document.getElementById(id) as T;
const canvas = $<HTMLCanvasElement>('game');
const live = $('live');

const state = createState(loadBest(), (Math.random() * 2 ** 32) >>> 0);
const renderer = createRenderer(canvas);
let paused = false;
let prevPhase = state.phase;
let prevScore = 0;

renderer.resize();
window.addEventListener('resize', renderer.resize);
window.addEventListener('orientationchange', renderer.resize);

setupInput(canvas, () => {
  if (paused) { paused = false; return; } // resume only on explicit input
  press(state);
});
document.addEventListener('visibilitychange', () => {
  if (document.hidden && state.phase === 'playing') paused = true;
});

setupSW($('update'), $('reload'), $('later'));
setupInstall($<HTMLButtonElement>('install'));
canvas.focus();

let last = 0;
let acc = 0;
function frame(t: number): void {
  const dt = Math.min((t - last) / 1000, 0.1); // clamp after backgrounding
  last = t;
  if (!paused) {
    acc += dt;
    while (acc >= STEP) { step(state, STEP); acc -= STEP; }
  }
  renderer.draw(state, paused);

  if (state.phase !== prevPhase) {
    document.body.dataset.phase = state.phase;
    if (state.phase === 'gameOver') {
      saveBest(state.best);
      live.textContent = `Game over. Score ${state.score}. Best ${state.best}.`;
    }
    prevPhase = state.phase;
  } else if (state.score !== prevScore && state.phase === 'playing') {
    live.textContent = `Score ${state.score}`;
  }
  prevScore = state.score;
  requestAnimationFrame(frame);
}
requestAnimationFrame(frame);
