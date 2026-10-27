export interface LoopCallbacks {
  update(deltaTime: number): void;
  render(): void;
}

export function startGameLoop(
  callbacks: LoopCallbacks
): () => void {
  let frameId = 0;
  let previousTime = 0;
  let stopped = false;

  function frame(time: number) {
    if (stopped) return;

    const deltaTime = previousTime
      ? Math.min(time - previousTime, 50)
      : 0;

    previousTime = time;

    callbacks.update(deltaTime);
    callbacks.render();

    frameId = requestAnimationFrame(frame);
  }

  frameId = requestAnimationFrame(frame);

  return () => {
    stopped = true;
    cancelAnimationFrame(frameId);
  };
}
