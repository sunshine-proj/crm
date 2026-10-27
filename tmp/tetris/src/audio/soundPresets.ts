export const SOUND_PRESETS: Record<
  string,
  { frequency: number; duration: number; type: OscillatorType }
> = {
  click: { frequency: 260, duration: 0.035, type: "square" },
  move: { frequency: 320, duration: 0.025, type: "square" },
  rotate: { frequency: 500, duration: 0.045, type: "square" },
  drop: { frequency: 140, duration: 0.09, type: "triangle" },
  score: { frequency: 620, duration: 0.09, type: "square" },
  hit: { frequency: 190, duration: 0.055, type: "square" },
  victory: { frequency: 760, duration: 0.16, type: "square" },
  gameover: { frequency: 170, duration: 0.22, type: "triangle" }
};
