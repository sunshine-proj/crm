import type { GameDefinition } from "./types";
import { TetrisGame } from "./tetris/TetrisGame";
import { PingPongGame } from "./ping-pong/PingPongGame";
import { RacingGame } from "./racing/RacingGame";
import { SnakeGame } from "./snake/SnakeGame";
import { TankGame } from "./tank/TankGame";
import { ArkanoidGame } from "./arkanoid/ArkanoidGame";
import { FroggerGame } from "./frogger/FroggerGame";
import { SpaceInvadersGame } from "./space-invaders/SpaceInvadersGame";
import { FootballGame } from "./football/FootballGame";
import { ShootingGalleryGame } from "./shooting-gallery/ShootingGalleryGame";

export const games: GameDefinition[] = [
  {
    id: "tetris",
    number: 1,
    name: "TETRIS",
    description: "Stack blocks and clear lines.",
    instructions: [
      "LEFT / RIGHT: MOVE",
      "UP / A: ROTATE",
      "DOWN: SOFT DROP",
      "B: HARD DROP"
    ],
    create: context => new TetrisGame(context)
  },
  {
    id: "ping-pong",
    number: 2,
    name: "PING-PONG",
    description: "Play against the computer.",
    instructions: [
      "UP / DOWN: MOVE",
      "A / B: MOVE FASTER",
      "FIRST TO 5 WINS",
      "RETURN THE BALL!"
    ],
    create: context => new PingPongGame(context)
  },
  {
    id: "racing",
    number: 3,
    name: "RACING",
    description: "Dodge traffic and survive the road.",
    instructions: [
        "LEFT / RIGHT: STEER",
        "AVOID OTHER CARS",
        "SPEED INCREASES",
        "DRIVE AS FAR AS YOU CAN"
    ],
    create: context => new RacingGame(context)
  },
  {
    id: "snake",
    number: 4,
    name: "SNAKE",
    description: "Eat food and grow without crashing.",
    instructions: [
        "ARROWS: MOVE",
        "EAT FOOD TO GROW",
        "AVOID WALLS",
        "DO NOT HIT YOUR TAIL"
    ],
    create: context => new SnakeGame(context)
  },
  {
    id: "tank",
    number: 5,
    name: "TANK",
    description: "Destroy enemy tanks.",
    instructions: [
        "ARROWS: MOVE",
        "A: FIRE",
        "DESTROY ENEMIES",
        "AVOID ENEMY FIRE"
    ],
    create: context => new TankGame(context)
  },
  {
    id: "arkanoid",
    number: 6,
    name: "ARKANOID",
    description: "Break bricks with a bouncing ball.",
    instructions: [
        "LEFT / RIGHT: MOVE",
        "A: LAUNCH BALL",
        "BREAK ALL BRICKS",
        "DON'T DROP THE BALL"
    ],
    create: context => new ArkanoidGame(context)
  },
  {
    id: "frogger",
    number: 7,
    name: "FROGGER",
    description: "Cross the road and river safely.",
    instructions: [
        "ARROWS: MOVE",
        "AVOID TRAFFIC",
        "RIDE RIVER LOGS",
        "REACH THE TOP"
    ],
    create: context => new FroggerGame(context)
  },
  {
    id: "space-invaders",
    number: 8,
    name: "SPACE INVADERS",
    description: "Defend Earth from alien invaders.",
    instructions: [
        "LEFT / RIGHT: MOVE",
        "A: FIRE",
        "DESTROY THE ALIENS",
        "PROTECT YOUR SHIP"
    ],
    create: context => new SpaceInvadersGame(context)
  },
  {
    id: "football",
    number: 9,
    name: "FOOTBALL",
    description: "Score goals against the computer.",
    instructions: [
        "ARROWS: MOVE",
        "A: KICK",
        "SCORE FIVE GOALS",
        "BEAT THE CPU"
    ],
    create: context => new FootballGame(context)
  },
  {
    id: "shooting-gallery",
    number: 10,
    name: "SHOOTING GALLERY",
    description: "Hit moving targets before time runs out.",
    instructions: [
        "ARROWS: AIM",
        "A: SHOOT",
        "HIT MOVING TARGETS",
        "BEAT THE CLOCK"
    ],
    create: context => new ShootingGalleryGame(context)
  }
];
