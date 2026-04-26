<?php
declare(strict_types=1);

/**
 * config/app.php
 * -----------------------------------------------------------------------
 * Application bootstrap / dependency wiring.
 *
 * Returns an instantiated CalculatorController with all dependencies
 * injected — ready to call ->dispatch($method, $path).
 *
 * Usage from public/index.php:
 *   $controller = require __DIR__ . '/../config/app.php';
 *   $controller->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['PATH_INFO'] ?? '/');
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dewa\Mahjong\Controller\CalculatorController;
use Dewa\Mahjong\Infrastructure\Logger;
use Dewa\Mahjong\Repository\CustomYakuRepository;
use Dewa\Mahjong\Repository\DiscardedPileRepository;
use Dewa\Mahjong\Repository\GameContextRepository;
use Dewa\Mahjong\Repository\HandRepository;
use Dewa\Mahjong\Repository\MeldRepository;
use Dewa\Mahjong\Repository\TileRepository;
use Dewa\Mahjong\Repository\UserRepository;
use Dewa\Mahjong\Repository\YakuRepository;
use Dewa\Mahjong\Service\Calculation\FuritenChecker;
use Dewa\Mahjong\Service\Calculation\ScoreCalculator;
use Dewa\Mahjong\Service\Calculation\ScoringService;
use Dewa\Mahjong\Service\Calculation\WaitCalculator;
use Dewa\Mahjong\Service\Calculation\YakuEvaluator;
use Dewa\Mahjong\Service\GameFlow\GameProgressionService;
use Dewa\Mahjong\Service\GameFlow\PlayerActionService;
use Dewa\Mahjong\Service\Recommendation\DefenseEvaluatorService;
use Dewa\Mahjong\Service\Recommendation\DiscardRecommendationService;
use Dewa\Mahjong\Service\Recommendation\ExpectedValueCalculator;
use Dewa\Mahjong\Service\Recommendation\ShantenCalculator;
use Dewa\Mahjong\Service\Recommendation\VisibleTileTrackerService;

// ---------------------------------------------------------------------------
// Load environment variables
// ---------------------------------------------------------------------------

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// ---------------------------------------------------------------------------
// Database connection (PDO)
// ---------------------------------------------------------------------------

$dsn  = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $_ENV['DB_HOST']     ?? '127.0.0.1',
    $_ENV['DB_PORT']     ?? '3306',
    $_ENV['DB_DATABASE'] ?? 'riichi_calc'
);
$user = $_ENV['DB_USERNAME'] ?? 'root';
$pass = $_ENV['DB_PASSWORD'] ?? '';

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

// ---------------------------------------------------------------------------
// Repositories
// ---------------------------------------------------------------------------

$tileRepo        = new TileRepository($pdo);
$yakuRepo        = new YakuRepository($pdo);
$customYakuRepo  = new CustomYakuRepository($pdo);
$meldRepo        = new MeldRepository($pdo);
$handRepo        = new HandRepository($pdo, $meldRepo);
$discardRepo     = new DiscardedPileRepository($pdo);
$gameContextRepo = new GameContextRepository($pdo, $customYakuRepo, $handRepo, $discardRepo);
$userRepo        = new UserRepository($pdo);

// ---------------------------------------------------------------------------
// Services
// ---------------------------------------------------------------------------

$waitCalculator        = new WaitCalculator($tileRepo);
$furitenChecker        = new FuritenChecker($discardRepo);
$yakuEvaluator         = new YakuEvaluator(
    $handRepo,
    $customYakuRepo,
    $yakuRepo,
    $meldRepo,
    $gameContextRepo
);
$scoringService        = new ScoringService();
$playerActionService   = new PlayerActionService(
    $handRepo,
    $gameContextRepo,
    $discardRepo,
    $meldRepo,
    $tileRepo
);
$gameProgressionService = new GameProgressionService(
    $gameContextRepo,
    $waitCalculator
);

// Recommendation services
$shantenCalculator      = new ShantenCalculator($tileRepo);
$defenseEvaluator       = new DefenseEvaluatorService($discardRepo);
$visibleTileTracker     = new VisibleTileTrackerService($tileRepo);
$expectedValueCalc      = new ExpectedValueCalculator($yakuEvaluator, new ScoreCalculator());
$discardRecommendation  = new DiscardRecommendationService(
    $shantenCalculator,
    $visibleTileTracker,
    $defenseEvaluator,
    $expectedValueCalc,
    $waitCalculator
);

$logger = Logger::get();

// ---------------------------------------------------------------------------
// Controller
// ---------------------------------------------------------------------------

return new CalculatorController(
    gameContextRepo:         $gameContextRepo,
    handRepo:                $handRepo,
    tileRepo:                $tileRepo,
    yakuRepo:                $yakuRepo,
    customYakuRepo:          $customYakuRepo,
    meldRepo:                $meldRepo,
    discardRepo:             $discardRepo,
    scoringService:          $scoringService,
    yakuEvaluator:           $yakuEvaluator,
    furitenChecker:          $furitenChecker,
    waitCalculator:          $waitCalculator,
    shantenCalculator:       $shantenCalculator,
    discardRecommendation:   $discardRecommendation,
    defenseEvaluator:        $defenseEvaluator,
    playerActionService:     $playerActionService,
    gameProgressionService:  $gameProgressionService,
    logger:                  $logger,
);