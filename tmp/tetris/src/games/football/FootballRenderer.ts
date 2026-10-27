import type { FootballSnapshot } from "./FootballGame";

export class FootballRenderer {
  render(ctx: CanvasRenderingContext2D, game: FootballSnapshot) {
    ctx.fillStyle = "#b8bea8";
    ctx.fillRect(0, 0, 240, 320);
    ctx.fillStyle = "#30372b";
    ctx.font = "10px monospace";
    ctx.fillText(`FOOTBALL ${game.playerScore}-${game.cpuScore}`, 8, 14);

    ctx.strokeRect(8, 28, 224, 280);
    ctx.beginPath();
    ctx.moveTo(8, 168);
    ctx.lineTo(232, 168);
    ctx.stroke();
    ctx.strokeRect(75, 28, 90, 35);
    ctx.strokeRect(75, 273, 90, 35);
    ctx.strokeRect(90, 28, 60, 8);
    ctx.strokeRect(90, 300, 60, 8);

    const drawPlayer = (x: number, y: number, cpu = false) => {
      ctx.fillRect(x - 5, y - 5, 10, 10);
      ctx.fillRect(x - 7, y + 4, 4, 6);
      ctx.fillRect(x + 3, y + 4, 4, 6);
      if (cpu) ctx.clearRect(x - 2, y - 2, 4, 4);
    };

    drawPlayer(game.playerX, game.playerY);
    drawPlayer(game.cpuX, game.cpuY, true);
    ctx.beginPath();
    ctx.arc(game.ballX, game.ballY, 4, 0, Math.PI * 2);
    ctx.fill();

    if (game.status === "paused" || game.status === "gameover") {
      ctx.fillStyle = "#b8bea8";
      ctx.fillRect(40, 135, 160, 48);
      ctx.strokeRect(40, 135, 160, 48);
      ctx.textAlign = "center";
      ctx.fillText(game.status === "paused" ? "PAUSED" : "FULL TIME", 120, 154);
      ctx.fillText("PRESS START", 120, 171);
      ctx.textAlign = "left";
    }
  }
}
