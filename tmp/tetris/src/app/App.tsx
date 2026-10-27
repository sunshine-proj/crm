import { useEffect, useMemo, useRef, useState } from "react";
import { Console } from "../components/Console/Console";
import { games } from "../games/registry";
import type { GameAction, GameStatus } from "../games/types";
import { AudioManager } from "../audio/AudioManager";
import { InputManager } from "../input/InputManager";
import "./App.css";

type AppMode = "menu" | "instructions" | "game";

export default function App() {
  const [selectedIndex, setSelectedIndex] = useState(0);
  const [mode, setMode] = useState<AppMode>("menu");
  const [muted, setMuted] = useState(false);
  const [, setRevision] = useState(0);

  const audioRef = useRef<AudioManager | null>(null);

  if (!audioRef.current) {
    audioRef.current = new AudioManager();
  }

  const audio = audioRef.current;
  const selected = games[selectedIndex];

  const game = useMemo(
    () => selected.create({
      playSound: effect => audio.playSound(effect)
    }),
    [selected, audio]
  );

  useEffect(() => {
    return () => game.destroy();
  }, [game]);

  function refresh() {
    setRevision(value => value + 1);
  }

  function nextGame(direction: number) {
    setSelectedIndex(index =>
      (index + direction + games.length) % games.length
    );
  }

  function startOrPause() {
    if (mode === "menu") {
      setMode("instructions");
      refresh();
      audio.playSound("click");
      return;
    }

    if (mode === "instructions") {
      game.start();
      setMode("game");
      refresh();
      audio.playSound("click");
      return;
    }

    const status: GameStatus = game.getStatus();

    if (status === "gameover" || status === "victory" || status === "ready") {
      game.start();
    } else if (status === "playing") {
      game.pause();
    } else if (status === "paused") {
      game.resume();
    }

    refresh();
  }

  function returnToMenu() {
    if (mode === "game") {
      game.pause();
    }

    setMode("menu");
    audio.playSound("click");
    refresh();
  }

  function handleAction(action: GameAction, pressed: boolean) {
    if (mode === "menu") {
      if (!pressed) return;

      if (action === "LEFT" || action === "UP") {
        nextGame(-1);
      } else if (action === "RIGHT" || action === "DOWN") {
        nextGame(1);
      } else if (action === "A" || action === "B") {
        setMode("instructions");
      }

      refresh();
      return;
    }

    if (mode === "instructions") {
      if (pressed && (action === "A" || action === "B")) {
        startOrPause();
      }
      return;
    }

    game.handleAction(action, pressed);
  }

  useEffect(() => {
    const input = new InputManager({
      onAction: handleAction,
      onStart: startOrPause,
      onMenu: returnToMenu
    });

    return input.attach();
  });

  useEffect(() => {
    audio.setMuted(muted);
  }, [audio, muted]);

  useEffect(() => {
    const visibilityChange = () => {
      if (document.hidden && game.getStatus() === "playing") {
        game.pause();
        refresh();
      }
    };

    document.addEventListener("visibilitychange", visibilityChange);

    return () => {
      document.removeEventListener("visibilitychange", visibilityChange);
    };
  }, [game]);

  useEffect(() => {
    return () => {
      void audio.destroy();
    };
  }, [audio]);

  return (
    <div className="app">
      <Console
        mode={mode}
        selected={selected}
        game={game}
        muted={muted}
        onAction={handleAction}
        onStart={startOrPause}
        onMenu={returnToMenu}
        onMute={() => setMuted(value => !value)}
        onPrevious={() => nextGame(-1)}
        onNext={() => nextGame(1)}
      />

      <p className="app-hint">
        {mode === "menu"
          ? "SELECT A GAME · ENTER TO CONTINUE"
          : mode === "instructions"
            ? "READ THE CONTROLS · ENTER TO PLAY"
            : "ARROWS TO MOVE · ESC TO EXIT"}
      </p>
    </div>
  );
}
