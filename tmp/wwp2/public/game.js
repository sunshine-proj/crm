(() => {
  "use strict";

  const canvas = document.getElementById("gameCanvas");
  const ctx = canvas.getContext("2d");

  const WIDTH = canvas.width;
  const HEIGHT = canvas.height;

  const angleSlider = document.getElementById("angleSlider");
  const powerSlider = document.getElementById("powerSlider");

  const angleValue = document.getElementById("angleValue");
  const powerValue = document.getElementById("powerValue");

  const fireButton = document.getElementById("fireButton");
  const restartButton = document.getElementById("restartButton");

  const turnLabel = document.getElementById("turnLabel");
  const statusMessage = document.getElementById("statusMessage");

  const GRAVITY = 240;
  const WORM_RADIUS = 14;
  const EXPLOSION_RADIUS = 48;
  const MAX_HEALTH = 100;

  // Terrain lives on a separate canvas so explosions can erase it.
  const terrainCanvas = document.createElement("canvas");
  terrainCanvas.width = WIDTH;
  terrainCanvas.height = HEIGHT;

  const terrainCtx = terrainCanvas.getContext("2d", {
    willReadFrequently: true
  });

  let worms = [];
  let activePlayer = 0;
  let projectile = null;
  let particles = [];
  let gameOver = false;
  let transitioning = false;
  let turnNumber = 1;
  let lastFrame = 0;

  let terrainHeights = new Array(WIDTH).fill(0);

  const random = (min, max) =>
    min + Math.random() * (max - min);

  function setStatus(message) {
    statusMessage.textContent = message;
  }

  function generateTerrain() {
    terrainCtx.clearRect(0, 0, WIDTH, HEIGHT);

    // Build smooth rolling hills.
    const points = [];
    let height = 355;

    for (let x = 0; x <= WIDTH; x += 8) {
      height += random(-12, 12);
      height = Math.max(270, Math.min(420, height));

      points.push({
        x,
        y: height
      });
    }

    // Smooth the terrain so it looks more natural.
    const smoothPoints = points.map((point, i) => {
      const previous = points[Math.max(0, i - 1)];
      const next = points[Math.min(points.length - 1, i + 1)];

      return {
        x: point.x,
        y: (previous.y + point.y * 2 + next.y) / 4
      };
    });

    const ground = terrainCtx.createLinearGradient(
      0, 280, 0, HEIGHT
    );

    ground.addColorStop(0, "#86c440");
    ground.addColorStop(0.18, "#4d9b36");
    ground.addColorStop(0.19, "#8b5a2b");
    ground.addColorStop(1, "#4a2d20");

    terrainCtx.beginPath();
    terrainCtx.moveTo(0, HEIGHT);

    for (const point of smoothPoints) {
      terrainCtx.lineTo(point.x, point.y);
    }

    terrainCtx.lineTo(WIDTH, HEIGHT);
    terrainCtx.closePath();

    terrainCtx.fillStyle = ground;
    terrainCtx.fill();

    // Small grass details.
    terrainCtx.strokeStyle = "#b5e85b";
    terrainCtx.lineWidth = 2;

    for (const point of smoothPoints) {
      terrainCtx.beginPath();
      terrainCtx.moveTo(point.x, point.y);
      terrainCtx.lineTo(point.x + 3, point.y - 3);
      terrainCtx.stroke();
    }

    // Record the surface for initial worm placement.
    terrainHeights = new Array(WIDTH);

    for (let x = 0; x < WIDTH; x++) {
      terrainHeights[x] = findGroundY(x);
    }
  }

  function findGroundY(x) {
    x = Math.max(0, Math.min(WIDTH - 1, Math.floor(x)));

    const data = terrainCtx.getImageData(
      x, 0, 1, HEIGHT
    ).data;

    for (let y = 0; y < HEIGHT; y++) {
      if (data[y * 4 + 3] > 0) {
        return y;
      }
    }

    return HEIGHT;
  }

  function createWorm(x, color, name) {
    const y = findGroundY(x);

    return {
      x,
      y: y - WORM_RADIUS,
      color,
      name,
      health: MAX_HEALTH,
      alive: true
    };
  }

  function resetGame() {
    activePlayer = 0;
    projectile = null;
    particles = [];
    gameOver = false;
    transitioning = false;
    turnNumber = 1;

    generateTerrain();

    worms = [
      createWorm(170, "#ef4444", "Red Worm"),
      createWorm(790, "#3b82f6", "Blue Worm")
    ];

    angleSlider.value = 45;
    powerSlider.value = 65;

    updateControls();
    updateHUD();
    setStatus("Ready to fire!");

    render();
  }

  function updateControls() {
    angleValue.textContent = `${angleSlider.value}°`;
    powerValue.textContent = `${powerSlider.value}%`;

    fireButton.disabled =
      gameOver || projectile !== null || transitioning;
  }

  function updateHUD() {
    worms.forEach((worm, index) => {
      const number = index + 1;

      document.getElementById(`health${number}`).style.width =
        `${Math.max(0, worm.health)}%`;

      document.getElementById(`healthText${number}`).textContent =
        `${Math.max(0, worm.health)} HP`;

      document
        .getElementById(`player${number}Card`)
        .classList.toggle(
          "active",
          index === activePlayer && !gameOver
        );
    });

    turnLabel.textContent = gameOver
      ? "Game Over"
      : `${worms[activePlayer].name}'s turn`;
  }

  function getAimDirection() {
    const angle = Number(angleSlider.value) * Math.PI / 180;

    return {
      x: Math.cos(angle),
      y: -Math.sin(angle)
    };
  }

  function drawBackground() {
    const sky = ctx.createLinearGradient(0, 0, 0, HEIGHT);

    sky.addColorStop(0, "#38bdf8");
    sky.addColorStop(0.7, "#bae6fd");
    sky.addColorStop(1, "#e0f2fe");

    ctx.fillStyle = sky;
    ctx.fillRect(0, 0, WIDTH, HEIGHT);

    // Distant hills.
    ctx.fillStyle = "#93c5a0";
    ctx.beginPath();
    ctx.moveTo(0, 300);

    for (let x = 0; x <= WIDTH; x += 40) {
      const y = 275 + Math.sin(x / 85) * 25;
      ctx.lineTo(x, y);
    }

    ctx.lineTo(WIDTH, HEIGHT);
    ctx.lineTo(0, HEIGHT);
    ctx.closePath();
    ctx.fill();

    // Decorative clouds.
    drawCloud(150, 95);
    drawCloud(490, 135);
    drawCloud(780, 75);
  }

  function drawCloud(x, y) {
    ctx.fillStyle = "rgba(255,255,255,0.75)";

    ctx.beginPath();
    ctx.arc(x, y, 20, 0, Math.PI * 2);
    ctx.arc(x + 22, y - 9, 26, 0, Math.PI * 2);
    ctx.arc(x + 48, y, 19, 0, Math.PI * 2);
    ctx.fill();
  }

  function drawWorm(worm, index) {
    if (!worm.alive) return;

    ctx.save();
    ctx.translate(worm.x, worm.y);

    // Body.
    ctx.fillStyle = worm.color;
    ctx.beginPath();
    ctx.ellipse(0, 0, 18, 14, 0, 0, Math.PI * 2);
    ctx.fill();

    ctx.strokeStyle = "rgba(0,0,0,0.25)";
    ctx.lineWidth = 2;
    ctx.stroke();

    // Eye direction follows the opponent.
    const opponent = worms[1 - index];
    const facing = opponent.x >= worm.x ? 1 : -1;

    ctx.fillStyle = "#fff";
    ctx.beginPath();
    ctx.arc(facing * 8, -5, 5, 0, Math.PI * 2);
    ctx.fill();

    ctx.fillStyle = "#111827";
    ctx.beginPath();
    ctx.arc(facing * 10, -5, 2.5, 0, Math.PI * 2);
    ctx.fill();

    // Small smile.
    ctx.strokeStyle = "#7f1d1d";
    ctx.lineWidth = 1.5;

    ctx.beginPath();
    ctx.arc(facing * 3, 2, 6, 0.15, Math.PI - 0.15);
    ctx.stroke();

    // Player indicator.
    if (index === activePlayer && !gameOver) {
      ctx.fillStyle = "#facc15";
      ctx.beginPath();
      ctx.moveTo(0, -29);
      ctx.lineTo(-6, -38);
      ctx.lineTo(6, -38);
      ctx.closePath();
      ctx.fill();
    }

    ctx.restore();
  }

  function drawAim() {
    if (gameOver || projectile || transitioning) return;

    const worm = worms[activePlayer];
    if (!worm.alive) return;

    const direction = getAimDirection();
    const length = 48;

    const startX = worm.x + direction.x * 20;
    const startY = worm.y + direction.y * 20;

    ctx.save();

    ctx.strokeStyle = "#ffffff";
    ctx.lineWidth = 3;
    ctx.setLineDash([6, 5]);

    ctx.beginPath();
    ctx.moveTo(startX, startY);
    ctx.lineTo(
      startX + direction.x * length,
      startY + direction.y * length
    );

    ctx.stroke();
    ctx.setLineDash([]);

    // Predicted trajectory.
    const power = Number(powerSlider.value) / 100;
    const speed = 300 + power * 280;

    ctx.fillStyle = "rgba(255,255,255,0.65)";

    for (let t = 0.15; t <= 0.9; t += 0.15) {
      const px = startX + direction.x * speed * t;
      const py =
        startY +
        direction.y * speed * t +
        0.5 * GRAVITY * t * t;

      if (
        px < 0 || px >= WIDTH ||
        py < 0 || py >= HEIGHT
      ) {
        break;
      }

      ctx.beginPath();
      ctx.arc(px, py, 3, 0, Math.PI * 2);
      ctx.fill();
    }

    ctx.restore();
  }

  function drawProjectile() {
    if (!projectile) return;

    ctx.save();

    ctx.fillStyle = "#292524";
    ctx.beginPath();
    ctx.arc(
      projectile.x,
      projectile.y,
      6,
      0,
      Math.PI * 2
    );
    ctx.fill();

    ctx.strokeStyle = "#f97316";
    ctx.lineWidth = 3;

    ctx.beginPath();
    ctx.moveTo(projectile.x - 5, projectile.y - 3);
    ctx.lineTo(projectile.x - 12, projectile.y - 10);
    ctx.stroke();

    ctx.restore();
  }

  function drawParticles() {
    for (const particle of particles) {
      ctx.globalAlpha = Math.max(
        0,
        particle.life / particle.maxLife
      );

      ctx.fillStyle = particle.color;

      ctx.beginPath();
      ctx.arc(
        particle.x,
        particle.y,
        particle.radius,
        0,
        Math.PI * 2
      );
      ctx.fill();
    }

    ctx.globalAlpha = 1;
  }

  function render() {
    drawBackground();

    ctx.drawImage(terrainCanvas, 0, 0);

    worms.forEach(drawWorm);

    drawAim();
    drawProjectile();
    drawParticles();
  }

  function fire() {
    if (
      gameOver ||
      projectile ||
      transitioning ||
      !worms[activePlayer].alive
    ) {
      return;
    }

    const worm = worms[activePlayer];
    const direction = getAimDirection();

    const power = Number(powerSlider.value) / 100;
    const speed = 300 + power * 280;

    projectile = {
      x: worm.x + direction.x * 22,
      y: worm.y + direction.y * 22,

      vx: direction.x * speed,
      vy: direction.y * speed,

      owner: activePlayer,
      age: 0
    };

    setStatus("Projectile in flight!");

    updateControls();
  }

  function projectileHitsTerrain(x, y) {
    const px = Math.floor(x);
    const py = Math.floor(y);

    if (
      px < 0 || px >= WIDTH ||
      py < 0 || py >= HEIGHT
    ) {
      return false;
    }

    return terrainCtx.getImageData(px, py, 1, 1).data[3] > 0;
  }

  function updateProjectile(dt) {
    if (!projectile) return;

    projectile.age += dt;

    projectile.vy += GRAVITY * dt;
    projectile.x += projectile.vx * dt;
    projectile.y += projectile.vy * dt;

    if (
      projectile.x < 0 ||
      projectile.x >= WIDTH ||
      projectile.y >= HEIGHT ||
      projectile.age > 8
    ) {
      explode(
        Math.max(0, Math.min(WIDTH - 1, projectile.x)),
        Math.max(0, Math.min(HEIGHT - 1, projectile.y))
      );

      return;
    }

    if (projectileHitsTerrain(projectile.x, projectile.y)) {
      explode(projectile.x, projectile.y);
      return;
    }

    for (const worm of worms) {
      if (!worm.alive) continue;

      const dx = projectile.x - worm.x;
      const dy = projectile.y - worm.y;

      if (Math.hypot(dx, dy) < WORM_RADIUS + 6) {
        explode(projectile.x, projectile.y);
        return;
      }
    }
  }

  function createExplosionParticles(x, y) {
    const colors = [
      "#f97316",
      "#facc15",
      "#dc2626",
      "#a8a29e",
      "#57534e"
    ];

    for (let i = 0; i < 35; i++) {
      const angle = Math.random() * Math.PI * 2;
      const speed = random(50, 230);
      const life = random(0.3, 0.9);

      particles.push({
        x,
        y,

        vx: Math.cos(angle) * speed,
        vy: Math.sin(angle) * speed - 60,

        radius: random(2, 6),
        color: colors[
          Math.floor(Math.random() * colors.length)
        ],

        life,
        maxLife: life
      });
    }
  }

  function explode(x, y) {
    const owner = projectile ? projectile.owner : activePlayer;

    projectile = null;

    // Erase a circular crater from the terrain.
    terrainCtx.save();
    terrainCtx.globalCompositeOperation = "destination-out";
    terrainCtx.beginPath();

    terrainCtx.arc(
      x,
      y,
      EXPLOSION_RADIUS,
      0,
      Math.PI * 2
    );

    terrainCtx.fill();
    terrainCtx.restore();

    createExplosionParticles(x, y);

    // Apply radial damage to both worms.
    for (const worm of worms) {
      if (!worm.alive) continue;

      const dx = worm.x - x;
      const dy = worm.y - y;

      const distance = Math.hypot(dx, dy);

      if (distance < EXPLOSION_RADIUS + WORM_RADIUS) {
        const damage = Math.round(
          55 *
          Math.max(
            0,
            1 - distance / (EXPLOSION_RADIUS + WORM_RADIUS)
          )
        );

        worm.health = Math.max(0, worm.health - damage);

        if (worm.health === 0) {
          worm.alive = false;
        }

        // Push worms away from the blast.
        const force = Math.max(0, 1 - distance / 140);

        worm.x +=
          (dx / Math.max(1, distance)) * 30 * force;

        worm.x = Math.max(
          WORM_RADIUS,
          Math.min(WIDTH - WORM_RADIUS, worm.x)
        );
      }
    }

    // Place worms on the new terrain surface.
    for (const worm of worms) {
      if (!worm.alive) continue;

      const groundY = findGroundY(worm.x);

      if (groundY < HEIGHT) {
        worm.y = Math.min(
          Math.max(worm.y, groundY - WORM_RADIUS),
          HEIGHT - WORM_RADIUS
        );
      }
    }

    updateHUD();

    const winner = worms.find(worm => !worm.alive);

    if (winner) {
      gameOver = true;

      const victor = worms.find(worm => worm.alive);

      setStatus(
        victor
          ? `${victor.name} wins!`
          : "Draw! Both worms were defeated."
      );

      updateControls();
      render();

      return;
    }

    setStatus(
      `Explosion! ${worms[owner].name} fired.`
    );

    transitioning = true;
    updateControls();

    // Let the explosion animation finish before changing turns.
    setTimeout(() => {
      if (gameOver) return;

      activePlayer = 1 - activePlayer;
      turnNumber++;

      // Start the next worm facing its opponent.
      angleSlider.value = activePlayer === 0 ? 45 : 135;

      transitioning = false;

      updateControls();
      updateHUD();

      setStatus(
        `Turn ${turnNumber}: ${worms[activePlayer].name}'s turn`
      );

      render();
    }, 850);
  }

  function updateParticles(dt) {
    for (const particle of particles) {
      particle.life -= dt;

      particle.x += particle.vx * dt;
      particle.y += particle.vy * dt;

      particle.vy += GRAVITY * dt * 0.5;

      particle.vx *= 0.98;
    }

    particles = particles.filter(
      particle => particle.life > 0
    );
  }

  function gameLoop(timestamp) {
    const dt = lastFrame
      ? Math.min((timestamp - lastFrame) / 1000, 0.033)
      : 0;

    lastFrame = timestamp;

    updateProjectile(dt);
    updateParticles(dt);

    render();

    requestAnimationFrame(gameLoop);
  }

  angleSlider.addEventListener("input", updateControls);
  powerSlider.addEventListener("input", updateControls);

  fireButton.addEventListener("click", fire);

  restartButton.addEventListener("click", resetGame);

  document.addEventListener("keydown", event => {
    // Avoid triggering game controls while using another input.
    if (
      event.target instanceof HTMLInputElement ||
      event.target instanceof HTMLTextAreaElement
    ) {
      return;
    }

    if (
      event.code === "Space" ||
      event.code === "ArrowUp" ||
      event.code === "ArrowDown" ||
      event.code === "ArrowLeft" ||
      event.code === "ArrowRight"
    ) {
      event.preventDefault();
    }

    if (event.code === "Space") {
      fire();
    }

    if (event.code === "KeyA" || event.code === "ArrowLeft") {
      angleSlider.value = Math.max(
        5,
        Number(angleSlider.value) - 2
      );

      updateControls();
    }

    if (event.code === "KeyD" || event.code === "ArrowRight") {
      angleSlider.value = Math.min(
        175,
        Number(angleSlider.value) + 2
      );

      updateControls();
    }

    if (event.code === "KeyW" || event.code === "ArrowUp") {
      powerSlider.value = Math.min(
        100,
        Number(powerSlider.value) + 2
      );

      updateControls();
    }

    if (event.code === "KeyS" || event.code === "ArrowDown") {
      powerSlider.value = Math.max(
        20,
        Number(powerSlider.value) - 2
      );

      updateControls();
    }
  });

  // Register the PWA service worker.
  if ("serviceWorker" in navigator) {
    window.addEventListener("load", () => {
      navigator.serviceWorker.register("/sw.js")
        .catch(error => {
          console.error(
            "Service worker registration failed:",
            error
          );
        });
    });
  }

  resetGame();
  requestAnimationFrame(gameLoop);
})();