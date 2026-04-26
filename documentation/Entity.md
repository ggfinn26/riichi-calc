# Entity Documentation

This document describes the entities in the Riichi Mahjong Calculator application. Each entity represents a core concept in the domain model.

## Overview

The application uses the following entities:

- [CustomYaku](#customyaku)
- [DiscardedPile](#discardedpile)
- [GameContext](#gamecontext)
- [Hand](#hand)
- [Meld](#meld)
- [Tile](#tile)
- [User](#user)
- [Yaku](#yaku)

## CustomYaku

Represents custom yaku rules that can be defined by users.

### Properties
- `id` (int): Unique identifier
- `name` (string): Name of the custom yaku
- `description` (string): Description of the yaku
- `hanClosed` (int): Han value when hand is closed
- `hanOpened` (int): Han value when hand is opened
- `isYakuman` (bool): Whether this is a yakuman
- `conditions` (array): Logical conditions for the yaku
- `createdAt` (DateTime): Creation timestamp
- `updatedAt` (DateTime): Last update timestamp
- `createdByUserId` (int): ID of the user who created this yaku
- `isDeleted` (bool): Soft delete flag

### Methods
- `getId()`: Returns the ID
- `getName()`: Returns the name
- `getDescription()`: Returns the description
- `getHanClosed()`: Returns han value for closed hand
- `getHanOpened()`: Returns han value for opened hand
- `isYakuman()`: Checks if it's a yakuman
- `getConditions()`: Returns the conditions array
- `getCreatedAt()`: Returns creation timestamp
- `getUpdatedAt()`: Returns last update timestamp
- `getCreatedByUserId()`: Returns creator user ID
- `isDeleted()`: Checks if deleted
- `updateRules(string $newDescription, array $newConditions)`: Updates description and conditions

## DiscardedPile

Represents a discarded tile on the table.

### Properties
- `id` (int): Unique identifier
- `gameContextId` (int): ID of the game context
- `userId` (int): ID of the user who discarded
- `tile` (Tile): The discarded tile object
- `turnOrder` (int): Turn order when discarded
- `isRiichiDeclare` (bool): Whether discarded during riichi declaration
- `isTsumo` (bool): Whether it's a tsumogiri (true) or tebidashi (false)
- `orderIndex` (int): Discard order (1-24)

### Methods
- `getId()`: Returns the ID
- `setId(int $id)`: Sets the ID (used after DB insert)
- `getGameContextId()`: Returns game context ID
- `setGameContextId(int $gameContextId)`: Sets game context ID
- `getUserId()`: Returns user ID
- `getTurnOrder()`: Returns turn order
- `isRiichiDeclare()`: Checks if riichi declaration discard
- `isTsumo()`: Checks if tsumogiri
- `getOrderIndex()`: Returns discard order
- `getTile()`: Returns the tile object

## GameContext

Represents the overall game state and context.

### Properties
- `id` (int): Unique identifier
- `gameId` (int): ID of the game
- `status` (string): Game status
- `roundNumber` (int): Round number (1-4)
- `roundWind` (string): Round wind ('east' or 'south')
- `honba` (int): Number of honba sticks
- `riichiSticks` (int): Number of riichi sticks
- `dealerId` (int): ID of the dealer
- `currentTurnUserId` (int): ID of current turn player
- `nextTurnOrderIndex` (int): Next turn order index
- `leftWallTiles` (int): Remaining tiles in wall
- `doraIndicators` (Tile[]): Array of dora indicator tiles
- `discardPile` (DiscardedPile[]): Array of discarded tiles
- `hands` (Hand[]): Array of player hands
- `activeCustomYakus` (CustomYaku[]): Array of active custom yakus
- `kanCount` (int): Number of kans on table

### Methods
- `getId()`: Returns the ID
- `setId(int $id)`: Sets the ID
- `getGameId()`: Returns game ID
- `getRoundNumber()`: Returns round number
- `getRoundWind()`: Returns round wind
- `getHonba()`: Returns honba count
- `getRiichiSticks()`: Returns riichi sticks count
- `getDealerId()`: Returns dealer ID
- `getCurrentTurnUserId()`: Returns current turn user ID
- `getStatus()`: Returns game status
- `getNextTurnOrderIndex()`: Returns next turn order index
- `getLeftWallTiles()`: Returns remaining wall tiles
- `getKanCount()`: Returns kan count
- `getDoraIndicators()`: Returns dora indicators array
- `getDiscardPile()`: Returns discard pile array
- `getHands()`: Returns hands array
- `getActiveCustomYakus()`: Returns active custom yakus array
- `addDoraIndicator(Tile $tile)`: Adds a dora indicator
- `registerDiscard(DiscardedPile $discard)`: Registers a discard
- `restoreDiscard(DiscardedPile $discard)`: Restores a discard without incrementing turn
- `addActiveCustomYaku(CustomYaku $yaku)`: Adds an active custom yaku
- `addHand(Hand $hand)`: Adds a hand to the game
- `addRiichiStick()`: Adds a riichi stick
- `isGameActive()`: Checks if game is active
- `processKanWallAdjustment(Tile $newDoraIndicator)`: Processes kan wall adjustment
- `calculateTotalRemainingTiles()`: Calculates total remaining tiles
- `isDrawConditionMet()`: Checks if draw condition is met
- `drawTileFromWall()`: Draws a tile from wall

## Hand

Represents a player's hand of tiles.

### Properties
- `id` (int): Unique identifier
- `gameContextId` (int): ID of the game context
- `userId` (int): ID of the user
- `isDealer` (bool): Whether this hand is the dealer
- `isRiichiDeclared` (bool): Whether riichi is declared
- `tiles` (Tile[]): Array of tiles in hand
- `melds` (Meld[]): Array of melds
- `riichiDiscardId` (int|null): ID of riichi discard
- `nagashiManganDiscardId` (int|null): ID of nagashi mangan discard

### Methods
- `getId()`: Returns the ID
- `setId(int $id)`: Sets the ID
- `getGameContextId()`: Returns game context ID
- `setGameContextId(int $gameContextId)`: Sets game context ID
- `getUserId()`: Returns user ID
- `isDealer()`: Checks if dealer
- `isRiichiDeclared()`: Checks if riichi declared
- `getRiichiDiscardId()`: Returns riichi discard ID
- `getNagashiManganDiscardId()`: Returns nagashi mangan discard ID
- `getTiles()`: Returns tiles array
- `getMelds()`: Returns melds array
- `addTile(Tile $tile)`: Adds a tile to hand
- `addMeld(Meld $meld)`: Adds a meld to hand
- `declareRiichi(int $discardActionId)`: Declares riichi
- `setRiichiDeclared(bool $isDeclared, int|null $discardActionId)`: Sets riichi status
- `setNagashiManganDiscardId(int|null $id)`: Sets nagashi mangan discard ID
- `isMenzen()`: Checks if hand is closed (menzenchin)
- `getTotalTilesCount()`: Returns total logical tile count
- `getTotalPhysicalTilesCount()`: Returns total physical tile count
- `isValidTileCount()`: Validates tile count

## Meld

Represents a meld (chi, pon, kan) in a hand.

### Properties
- `id` (int): Unique identifier
- `gameContextId` (int): ID of the game context
- `userId` (int): ID of the user
- `handId` (int): ID of the hand
- `type` (string): Type of meld ('chi', 'pon', 'ankan', 'daiminkan', 'shouminkan')
- `isClosed` (bool): Whether the meld is closed
- `tiles` (Tile[]): Array of tiles in the meld

### Methods
- `getId()`: Returns the ID
- `getGameContextId()`: Returns game context ID
- `getUserId()`: Returns user ID
- `getHandId()`: Returns hand ID
- `getType()`: Returns meld type
- `isClosed()`: Checks if closed
- `getTiles()`: Returns tiles array
- `isChi()`: Checks if chi
- `isPon()`: Checks if pon
- `isAnkan()`: Checks if ankan
- `isDaiminkan()`: Checks if daiminkan
- `isShouminkan()`: Checks if shouminkan
- `isValidMeldCount()`: Validates meld tile count

## Tile

Represents a mahjong tile.

### Properties
- `id` (int): Unique identifier
- `name` (string): Tile name
- `value` (string): Tile value
- `unicode` (string): Unicode representation
- `type` (string): Tile type ('man', 'pin', 'sou', 'honor')
- `color` (string): Tile color

### Methods
- `getId()`: Returns the ID
- `getName()`: Returns the name
- `getValue()`: Returns the value
- `getUnicode()`: Returns the unicode
- `getType()`: Returns the type
- `getColor()`: Returns the color
- `isHonor()`: Checks if honor tile
- `isTerminal()`: Checks if terminal tile (1 or 9)
- `isSimple()`: Checks if simple tile (2-8)

## User

Represents a user of the application.

### Properties
- `id` (int): Unique identifier
- `userName` (string): Username
- `email` (string): Email address
- `passwordHash` (string): Hashed password
- `createdAt` (DateTime): Creation timestamp
- `updatedAt` (DateTime): Last update timestamp
- `isDeleted` (bool): Soft delete flag

### Methods
- `getId()`: Returns the ID
- `getUserName()`: Returns the username
- `getPasswordHash()`: Returns the password hash
- `getEmail()`: Returns the email
- `getCreatedAt()`: Returns creation timestamp
- `getUpdatedAt()`: Returns last update timestamp
- `isDeleted()`: Checks if deleted
- `changeUserName(string $newUserName)`: Changes username
- `changeEmail(string $newEmail)`: Changes email
- `changePassword(string $newPasswordHash)`: Changes password
- `delete()`: Soft deletes the user

## Yaku

Represents a standard yaku in riichi mahjong.

### Properties
- `id` (int): Unique identifier
- `nameJp` (string): Japanese name
- `nameEng` (string): English name
- `description` (string): Description
- `hanClosed` (int): Han value when hand is closed
- `hanOpened` (int): Han value when hand is opened
- `isYakuman` (bool): Whether this is a yakuman

### Methods
- `getId()`: Returns the ID
- `getNameJp()`: Returns Japanese name
- `getNameEng()`: Returns English name
- `getHanClosed()`: Returns han value for closed hand
- `getHanOpened()`: Returns han value for opened hand
- `getisYakuman()`: Checks if yakuman