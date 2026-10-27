import { useRef } from "react";
import type { GameAction } from "../../games/types";

interface ControlPadProps {
  onAction(action: GameAction, pressed: boolean): void;
  onStart(): void;
  onMenu(): void;
}

export function ControlPad({
  onAction,
  onStart,
  onMenu
}: ControlPadProps) {
  const intervalRef = useRef<number | null>(null);

  function stopRepeat() {
    if (intervalRef.current !== null) {
      window.clearInterval(intervalRef.current);
      intervalRef.current = null;
    }
  }

  function control(action: GameAction, repeat = false) {
    return {
      onPointerDown: (event: React.PointerEvent<HTMLButtonElement>) => {
        event.preventDefault();
        event.currentTarget.setPointerCapture(event.pointerId);
        onAction(action, true);

        if (repeat) {
          stopRepeat();
          intervalRef.current = window.setInterval(() => {
            onAction(action, true);
          }, 100);
        }
      },
      onPointerUp: () => {
        stopRepeat();
        onAction(action, false);
      },
      onPointerCancel: () => {
        stopRepeat();
        onAction(action, false);
      },
      onLostPointerCapture: stopRepeat
    };
  }

  return (
    <div className="physical-controls">
      <div className="dpad" aria-label="Directional pad">
        <button className="key up" aria-label="Up"
          {...control("UP", true)}>▲</button>
        <button className="key left" aria-label="Left"
          {...control("LEFT", true)}>◀</button>
        <button className="key center" disabled aria-hidden="true">●</button>
        <button className="key right" aria-label="Right"
          {...control("RIGHT", true)}>▶</button>
        <button className="key down" aria-label="Down"
          {...control("DOWN", true)}>▼</button>
      </div>

      <div className="action-buttons">
        <button className="key action-a" aria-label="Action A"
          {...control("A")}>A</button>
        <button className="key action-b" aria-label="Action B"
          {...control("B")}>B</button>
      </div>

      <div className="system-buttons">
        <button className="small-btn" onClick={onStart}>
          START / PAUSE
        </button>
        <button className="small-btn" onClick={onMenu}>
          MENU
        </button>
      </div>
    </div>
  );
}
