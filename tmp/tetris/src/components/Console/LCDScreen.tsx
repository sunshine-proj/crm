import { useEffect, useRef } from "react";
import type { GameDefinition, GameInstance } from "../../games/types";
import { startGameLoop } from "../../games/shared/GameLoop";
import { clearLCD, drawText } from "../../games/shared/Drawing";

interface LCDScreenProps {
  mode: "menu" | "instructions" | "game";
  selected: GameDefinition;
  game: GameInstance;
}

export function LCDScreen({
  mode,
  selected,
  game
}: LCDScreenProps) {
  const canvasRef = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    const canvas = canvasRef.current;
    const ctx = canvas?.getContext("2d");

    if (!canvas || !ctx) return;

    ctx.imageSmoothingEnabled = false;

    return startGameLoop({
      update: deltaTime => {
        if (mode === "game" && game.getStatus() === "playing") {
          game.update(deltaTime);
        }
      },
      render: () => {
        if (mode === "game") {
          game.render(ctx);
          return;
        }

        clearLCD(ctx, 240, 320);

        drawText(ctx, "BRICK GAME", 120, 35, 14, "center");

        if (mode === "menu") {
          drawText(ctx, "GAME SELECT", 120, 91, 12, "center");
          drawText(
            ctx,
            `${String(selected.number).padStart(2, "0")}  ${selected.name}`,
            120,
            145,
            13,
            "center"
          );

          drawText(ctx, selected.description, 120, 180, 8, "center");
          drawText(ctx, "<  SELECT  >", 120, 226, 10, "center");
          drawText(ctx, "PRESS START", 120, 265, 10, "center");
        } else {
          drawText(ctx, selected.name, 120, 78, 13, "center");

          selected.instructions.forEach((instruction, index) => {
            drawText(
              ctx,
              instruction,
              120,
              126 + index * 25,
              10,
              "center"
            );
          });

          drawText(ctx, "PRESS START", 120, 258, 10, "center");
        }

        drawText(ctx, "MONO LCD", 12, 307, 8);
      }
    });
  }, [mode, selected, game]);

  return (
    <canvas
      ref={canvasRef}
      width={240}
      height={320}
      role="img"
      aria-label={`${selected.name} monochrome game screen`}
    />
  );
}
