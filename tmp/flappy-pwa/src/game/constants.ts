// All tunable numbers live here.
export const W = 288;
export const H = 512;
export const GROUND_H = 64;
export const STEP = 1 / 120; // fixed physics timestep (s)

export const GRAVITY = 1500; // px/s^2
export const FLAP_V = -400; // px/s, fixed impulse (not additive)
export const MAX_FALL = 700;

export const BIRD_X = 76;
export const BIRD_R = 12; // hitbox radius, slightly smaller than sprite

export const PIPE_W = 52;
export const PIPE_GAP = 116;
export const PIPE_SPEED = 120;
export const PIPE_SPACING = 170; // horizontal distance between pipe pairs
export const PIPE_MARGIN = 60; // min distance from gap edge to ceiling/ground

export const RESTART_DELAY = 0.45; // s, avoids accidental instant restart
export const BEST_KEY = 'flappy:highScore';
