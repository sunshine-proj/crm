import type { GameDefinition, GameInstance, GameAction } from "../../games/types";
import { LCDScreen } from "./LCDScreen";
import { ControlPad } from "./ControlPad";
import "./Console.css";

interface ConsoleProps {
  mode: "menu" | "instructions" | "game";
  selected: GameDefinition;
  game: GameInstance;
  muted: boolean;
  onAction(action: GameAction, pressed: boolean): void;
  onStart(): void;
  onMenu(): void;
  onMute(): void;
  onPrevious(): void;
  onNext(): void;
}

export function Console({
  mode,
  selected,
  game,
  muted,
  onAction,
  onStart,
  onMenu,
  onMute,
  onPrevious,
  onNext
}: ConsoleProps) {
  return (
    <main className="console">
      <header className="console-brand">
        BRICK GAME
        <span>RETRO ELECTRONIC SYSTEM</span>
      </header>

      <section className="lcd-bezel" aria-label="Handheld LCD">
        <LCDScreen mode={mode} selected={selected} game={game} />
      </section>

      <div className="console-label">
        <span>● POWER</span>
        <button className="mute-button" onClick={onMute}>
          SOUND: {muted ? "OFF" : "ON"}
        </button>
      </div>

      {mode === "menu" && (
        <div className="selection-buttons">
          <button className="small-btn" onClick={onPrevious}>
            PREVIOUS
          </button>
          <button className="small-btn" onClick={onNext}>
            NEXT
          </button>
        </div>
      )}

      <ControlPad
        onAction={onAction}
        onStart={onStart}
        onMenu={onMenu}
      />

      <div className="speaker" aria-hidden="true">
        {Array.from({ length: 7 }, (_, i) => <i key={i} />)}
      </div>

      <footer className="console-footer">
        MODEL BG-1998 · MONOCHROME LCD
      </footer>
    </main>
  );
}
