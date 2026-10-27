import type { ShootingGallerySnapshot } from "./ShootingGalleryGame";

export class ShootingGalleryRenderer {
  render(ctx: CanvasRenderingContext2D, game: ShootingGallerySnapshot) {
    ctx.fillStyle = "#b8bea8";
    ctx.fillRect(0, 0, 240, 320);

    ctx.fillStyle = "#30372b";
    ctx.font = "10px monospace";

    ctx.fillText("SHOOTING GALLERY", 8, 14);
    ctx.fillText(`SCORE ${game.score}`, 8, 30);
    ctx.fillText(`AMMO ${game.ammo}`, 160, 30);
    ctx.fillText(`TIME ${Math.ceil(game.timeLeft)}`, 8, 44);

    // Target range.
    ctx.strokeRect(8, 52, 224, 204);

    for (const target of game.targets) {
      ctx.beginPath();
      ctx.arc(target.x, target.y, 11, 0, Math.PI * 2);
      ctx.fill();

      ctx.fillStyle = "#b8bea8";
      ctx.beginPath();
      ctx.arc(target.x, target.y, 6, 0, Math.PI * 2);
      ctx.fill();

      ctx.fillStyle = "#30372b";
      ctx.fillRect(target.x - 2, target.y - 2, 4, 4);
    }

    // Crosshair.
    ctx.strokeStyle = "#30372b";
    ctx.beginPath();
    ctx.moveTo(game.aimX - 10, game.aimY);
    ctx.lineTo(game.aimX + 10, game.aimY);
    ctx.moveTo(game.aimX, game.aimY - 10);
    ctx.lineTo(game.aimX, game.aimY + 10);
    ctx.stroke();

    ctx.fillText("A: FIRE", 8, 280);
    ctx.fillText("HIT TARGETS", 8, 296);

    if (game.status === "paused" || game.status === "gameover") {
      ctx.fillStyle = "#b8bea8";
      ctx.fillRect(38, 130, 164, 52);
      ctx.strokeRect(38, 130, 164, 52);

      ctx.textAlign = "center";
      ctx.fillText(
        game.status === "paused" ? "PAUSED" : "TIME UP!",
        120,
        150
      );
      ctx.fillText(`SCORE ${game.score}`, 120, 166);
      ctx.fillText("PRESS START", 120, 178);
      ctx.textAlign = "left";
    }
  }
}
