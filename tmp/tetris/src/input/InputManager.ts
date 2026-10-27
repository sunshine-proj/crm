import type { GameAction } from "../games/types";

export interface InputCallbacks {
  onAction(action: GameAction, pressed: boolean): void;
  onStart(): void;
  onMenu(): void;
}

const KEY_ACTIONS: Record<string, GameAction> = {
  ArrowUp: "UP",
  ArrowDown: "DOWN",
  ArrowLeft: "LEFT",
  ArrowRight: "RIGHT",
  z: "A",
  Z: "A",
  x: "B",
  X: "B"
};

export class InputManager {
  private pressed = new Set<string>();

  constructor(private readonly callbacks: InputCallbacks) {}

  attach(target: Window = window) {
    const down = (event: KeyboardEvent) => {
      if (
        event.target instanceof HTMLElement &&
        event.target.isContentEditable
      ) return;

      const action = KEY_ACTIONS[event.key];

      if (action) {
        event.preventDefault();

        if (!this.pressed.has(event.key)) {
          this.pressed.add(event.key);
          this.callbacks.onAction(action, true);
        } else if (
          action === "LEFT" ||
          action === "RIGHT" ||
          action === "DOWN"
        ) {
          this.callbacks.onAction(action, true);
        }

        return;
      }

      if (event.repeat) return;

      if (event.key === "Enter") {
        event.preventDefault();
        this.callbacks.onStart();
      } else if (event.key === "Escape" || event.key === "Backspace") {
        event.preventDefault();
        this.callbacks.onMenu();
      }
    };

    const up = (event: KeyboardEvent) => {
      const action = KEY_ACTIONS[event.key];

      if (action) {
        this.pressed.delete(event.key);
        this.callbacks.onAction(action, false);
      }
    };

    const blur = () => {
      this.pressed.clear();
    };

    target.addEventListener("keydown", down);
    target.addEventListener("keyup", up);
    target.addEventListener("blur", blur);

    return () => {
      target.removeEventListener("keydown", down);
      target.removeEventListener("keyup", up);
      target.removeEventListener("blur", blur);
      this.pressed.clear();
    };
  }
}
