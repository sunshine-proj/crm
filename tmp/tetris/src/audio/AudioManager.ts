import { SOUND_PRESETS } from "./soundPresets";

export class AudioManager {
  private context: AudioContext | null = null;
  private muted = false;

  setMuted(value: boolean) {
    this.muted = value;
  }

  isMuted() {
    return this.muted;
  }

  playSound(effect: string) {
    if (this.muted) return;

    const preset = SOUND_PRESETS[effect];
    if (!preset) return;

    try {
      this.context ??= new AudioContext();

      if (this.context.state === "suspended") {
        void this.context.resume();
      }

      const oscillator = this.context.createOscillator();
      const gain = this.context.createGain();
      const now = this.context.currentTime;

      oscillator.type = preset.type;
      oscillator.frequency.setValueAtTime(preset.frequency, now);

      gain.gain.setValueAtTime(0.04, now);
      gain.gain.exponentialRampToValueAtTime(
        0.001,
        now + preset.duration
      );

      oscillator.connect(gain);
      gain.connect(this.context.destination);

      oscillator.start(now);
      oscillator.stop(now + preset.duration);
    } catch {
      // Audio must never prevent gameplay.
    }
  }

  async destroy() {
    if (this.context) {
      await this.context.close();
      this.context = null;
    }
  }
}
