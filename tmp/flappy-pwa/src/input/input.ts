/** Pointer + keyboard -> one "flap" callback. Input never mutates game state directly. */
export function setupInput(target: HTMLElement, onFlap: () => void): void {
  target.addEventListener('pointerdown', (e) => { e.preventDefault(); target.focus(); onFlap(); });
  window.addEventListener('keydown', (e) => {
    if (e.repeat) return;
    if (e.code === 'Space' || e.code === 'ArrowUp' || e.code === 'Enter') { e.preventDefault(); onFlap(); }
  });
}
