export const LCD = {
  background: "#b8bea8",
  pixel: "#30372b",
  shadow: "#68705d",
  grid: "#9ca48e"
} as const;

export function drawText(
  ctx: CanvasRenderingContext2D,
  value: string,
  x: number,
  y: number,
  size = 10,
  align: CanvasTextAlign = "left"
) {
  ctx.fillStyle = LCD.pixel;
  ctx.font = `bold ${size}px monospace`;
  ctx.textAlign = align;
  ctx.fillText(value, x, y);
}

export function drawPixel(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  size: number
) {
  ctx.fillStyle = LCD.pixel;
  ctx.fillRect(x, y, size, size);

  ctx.fillStyle = LCD.shadow;
  ctx.fillRect(x + 1, y + 1, Math.max(1, size - 3), 1);
  ctx.fillRect(x + 1, y + 1, 1, Math.max(1, size - 3));
}

export function clearLCD(
  ctx: CanvasRenderingContext2D,
  width: number,
  height: number
) {
  ctx.fillStyle = LCD.background;
  ctx.fillRect(0, 0, width, height);
}
