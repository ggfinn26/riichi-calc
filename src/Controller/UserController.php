<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Controller;

use Dewa\Mahjong\DTO\LoginRequest;
use Dewa\Mahjong\DTO\RegisterUserRequest;
use Dewa\Mahjong\DTO\UpdateUserRequest;
use Dewa\Mahjong\Entity\User;
use Dewa\Mahjong\Http\Request;
use Dewa\Mahjong\Http\Response;
use Dewa\Mahjong\Infrastructure\PasswordHasher;
use Dewa\Mahjong\Repository\HandRepositoryInterface;
use Dewa\Mahjong\Repository\UserRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * UserController
 * -----------------------------------------------------------------------------
 * CRUD + auth surface for {@see User}.
 *
 * Endpoints:
 *   POST   /api/users                       register
 *   GET    /api/users                       list active
 *   GET    /api/users/{id}                  profile
 *   PUT    /api/users/{id}                  update (username/email/password)
 *   DELETE /api/users/{id}                  soft delete
 *   GET    /api/users/{userId}/history      match history (rounds played)
 *   POST   /api/auth/login                  authenticate, return opaque token
 *
 * Password hashing is delegated to {@see PasswordHasher} which prefers
 * Argon2id and falls back to bcrypt automatically.
 */
final class UserController
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepo,
        private readonly HandRepositoryInterface $handRepo,
        private readonly PasswordHasher          $hasher,
        private readonly LoggerInterface         $logger,
    ) {}

    // =========================================================================
    // CRUD
    // =========================================================================

    /**
     * POST /api/users — register a new user.
     */
    public function register(Request $request): void
    {
        $dto = RegisterUserRequest::fromArray($request->getBody());

        if ($this->userRepo->existByEmail($dto->email)) {
            throw new \InvalidArgumentException("Email '{$dto->email}' is already registered.");
        }
        if ($this->userRepo->existByUsername($dto->username)) {
            throw new \InvalidArgumentException("Username '{$dto->username}' is already taken.");
        }

        $now = new \DateTime();
        $user = new User(
            id:           0,
            userName:     $dto->username,
            email:        $dto->email,
            passwordHash: $this->hasher->hash($dto->password),
            createdAt:    $now,
            updatedAt:    $now,
            isDeleted:    false,
        );

        $saved = $this->userRepo->save($user);

        $this->logger->info('User registered', [
            'user_id'   => $saved->getId(),
            'username'  => $saved->getUserName(),
            'algorithm' => $this->hasher->preferredAlgorithm(),
        ]);

        Response::json($this->serialize($saved), 201);
    }

    /**
     * GET /api/users — list all active (non-deleted) users.
     */
    public function list(Request $request): void
    {
        $users = $this->userRepo->findAllActive();

        Response::json([
            'count' => count($users),
            'users' => array_map(fn(User $u) => $this->serialize($u), $users),
        ]);
    }

    /**
     * GET /api/users/{id}
     */
    public function getById(Request $request, string $id): void
    {
        $user = $this->loadActiveUser((int) $id);
        Response::json($this->serialize($user));
    }

    /**
     * PUT /api/users/{id}
     */
    public function update(Request $request, string $id): void
    {
        $user = $this->loadActiveUser((int) $id);
        $dto  = UpdateUserRequest::fromArray($request->getBody());

        if ($dto->username !== null && $dto->username !== $user->getUserName()) {
            if ($this->userRepo->existByUsername($dto->username)) {
                throw new \InvalidArgumentException("Username '{$dto->username}' is already taken.");
            }
            $user->changeUserName($dto->username);
        }

        if ($dto->email !== null && $dto->email !== $user->getEmail()) {
            if ($this->userRepo->existByEmail($dto->email)) {
                throw new \InvalidArgumentException("Email '{$dto->email}' is already registered.");
            }
            $user->changeEmail($dto->email);
        }

        if ($dto->newPassword !== null) {
            if (!$this->hasher->verify($dto->currentPassword ?? '', $user->getPasswordHash())) {
                throw new \InvalidArgumentException("Current password is incorrect.");
            }
            $user->changePassword($this->hasher->hash($dto->newPassword));
        }

        $saved = $this->userRepo->save($user);

        $this->logger->info('User updated', ['user_id' => $saved->getId()]);
        Response::json($this->serialize($saved));
    }

    /**
     * DELETE /api/users/{id} — soft delete.
     */
    public function delete(Request $request, string $id): void
    {
        $user = $this->loadActiveUser((int) $id);
        $user->delete();
        $this->userRepo->softDelete($user);

        $this->logger->info('User soft-deleted', ['user_id' => $user->getId()]);

        Response::json(['success' => true, 'user_id' => $user->getId()]);
    }

    // =========================================================================
    // HISTORY
    // =========================================================================

    /**
     * GET /api/users/{userId}/history
     */
    public function history(Request $request, string $userId): void
    {
        $user = $this->loadActiveUser((int) $userId);
        $rows = $this->handRepo->findHistoryByUserId($user->getId());

        // Group rounds per game_id for a per-match summary.
        $byGame = [];
        foreach ($rows as $row) {
            $gid = $row['game_id'];
            if (!isset($byGame[$gid])) {
                $byGame[$gid] = [
                    'game_id'      => $gid,
                    'rounds_count' => 0,
                    'rounds'       => [],
                ];
            }
            $byGame[$gid]['rounds_count']++;
            $byGame[$gid]['rounds'][] = [
                'hand_id'            => $row['hand_id'],
                'game_context_id'    => $row['game_context_id'],
                'round_number'       => $row['round_number'],
                'round_wind'         => $row['round_wind'],
                'status'             => $row['status'],
                'is_dealer'          => $row['is_dealer'],
                'is_riichi_declared' => $row['is_riichi_declared'],
            ];
        }

        Response::json([
            'user_id'     => $user->getId(),
            'username'    => $user->getUserName(),
            'games_count' => count($byGame),
            'history'     => array_values($byGame),
        ]);
    }

    // =========================================================================
    // AUTH
    // =========================================================================

    /**
     * POST /api/auth/login
     *
     * Returns an opaque bearer token. Persisting and validating that token
     * across requests is the responsibility of an extended AuthMiddleware
     * (the bundled middleware only supports a single static token).
     */
    public function login(Request $request): void
    {
        $dto = LoginRequest::fromArray($request->getBody());

        $user = filter_var($dto->identifier, FILTER_VALIDATE_EMAIL)
            ? $this->userRepo->findByEmail($dto->identifier)
            : $this->userRepo->findByUsername($dto->identifier);

        if ($user === null || $user->isDeleted()) {
            // Generic message — do not leak whether the account exists.
            Response::error('Invalid credentials.', 401);
            return;
        }

        if (!$this->hasher->verify($dto->password, $user->getPasswordHash())) {
            Response::error('Invalid credentials.', 401);
            return;
        }

        // Opportunistic rehash on successful login (e.g. legacy bcrypt → argon2id).
        if ($this->hasher->needsRehash($user->getPasswordHash())) {
            $user->changePassword($this->hasher->hash($dto->password));
            $this->userRepo->save($user);
            $this->logger->info('Password rehashed on login', [
                'user_id'   => $user->getId(),
                'algorithm' => $this->hasher->preferredAlgorithm(),
            ]);
        }

        $token     = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable('+24 hours'))->format(\DateTimeInterface::ATOM);

        $this->logger->info('User logged in', ['user_id' => $user->getId()]);

        Response::json([
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt,
            'user'       => $this->serialize($user),
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function loadActiveUser(int $id): User
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException("User id must be a positive integer.");
        }
        $user = $this->userRepo->findById($id);
        if ($user === null || $user->isDeleted()) {
            throw new \RuntimeException("User #{$id} not found.");
        }
        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(User $user): array
    {
        return [
            'id'         => $user->getId(),
            'username'   => $user->getUserName(),
            'email'      => $user->getEmail(),
            'created_at' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $user->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'is_deleted' => $user->isDeleted(),
        ];
    }
}
