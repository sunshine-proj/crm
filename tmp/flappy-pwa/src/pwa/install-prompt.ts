interface InstallEvent extends Event { prompt(): Promise<void>; userChoice: Promise<unknown> }

/** Stashes beforeinstallprompt; CSS only shows the button on the ready screen. */
export function setupInstall(btn: HTMLButtonElement): void {
  let ev: InstallEvent | null = null;
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault(); ev = e as InstallEvent; btn.hidden = false;
  });
  btn.onclick = async () => {
    if (!ev) return;
    await ev.prompt(); await ev.userChoice;
    ev = null; btn.hidden = true;
  };
}
