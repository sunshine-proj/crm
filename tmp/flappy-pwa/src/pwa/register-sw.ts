import { registerSW } from 'virtual:pwa-register';

/** Shows a non-blocking prompt on update; never reloads on its own. */
export function setupSW(banner: HTMLElement, reload: HTMLElement, later: HTMLElement): void {
  const update = registerSW({ onNeedRefresh() { banner.hidden = false; } });
  reload.onclick = () => update(true);
  later.onclick = () => { banner.hidden = true; };
}
