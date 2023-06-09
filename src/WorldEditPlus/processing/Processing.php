<?php
/**
 * Copyright (c) 2018 CreeParker
 *
 * <English>
 * This plugin is released under the MIT License.
 * http://opensource.org/licenses/mit-license.php
 *
 * <日本語>
 * このプラグインは、MITライセンスのもとで公開されています。
 * http://opensource.org/licenses/mit-license.php
 */

declare(strict_types=1);

namespace WorldEditPlus\processing;

use pocketmine\block\BlockIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\command\CommandSender;
use pocketmine\item\LegacyStringToItemParser;
use pocketmine\item\LegacyStringToItemParserException;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use pocketmine\world\World;
use pocketmine\worldLevel;
use pocketmine\worldPosition;
use WorldEditPlus\Language;
use WorldEditPlus\level\Range;
use WorldEditPlus\WorldEditPlus;

abstract class Processing extends Range {
    /** @var array */
    private static array $message = [];
    public static array $scheduler = [];
    /** @var int */
    private int $id;
    /** @var CommandSender */
    public CommandSender $sender;
    /** @var float */
    public float $meter;
    /** @var float */
    public float $gage = 0;
    /** @var int */
    public int $stopper;
    /** @var int */
    public int $restriction = 0;

    /**
     * @param CommandSender $sender
     * @param Position $pos1
     * @param Position $pos2
     */
    public function __construct(CommandSender $sender, Position $pos1, Position $pos2) {
        parent::__construct($pos1, $pos2);
        $this->sender = $sender;
        static $count = 0;
        $this->id = $count++;
        $this->air = VanillaBlocks::AIR();
        $this->stopper = WorldEditPlus::$instance->getConfig()->get('stopper', null) ?? 500;
    }

    /**
     * @return bool
     */
    abstract public function onCheck(CommandSender $sender): bool;

    /**
     * @return iterable
     */
    abstract public function onRun(): iterable;

    public function start(): void {
        $this->level = $this->getLevel();
        if ($this->level === null) {
            $this->sender->sendMessage(TextFormat::RED . Language::get('processing.level.null.error'));
            return;
        }
        if (!$this->isLevel()) {
            $this->sender->sendMessage(TextFormat::RED . Language::get('processing.level.error'));
            return;
        }
        if (!$this->onCheck($this->sender))
            return;
        $task = new class($this) extends Task {
            public function __construct(Processing $owner) {
                $this->generator = $owner->onRun();
                $this->owner = $owner;
            }

            public function onRun(): void {
                if ($this->generator->current())
                    $this->getHandler()->cancel();
                else
                    $this->generator->next();
            }

            public function onCancel(): void {
                $this->owner->remove();
            }
        };
        $id = $this->id;
        $period = WorldEditPlus::$instance->getConfig()->get('period', null) ?? 1;
        self::$scheduler[$id] = WorldEditPlus::$instance->getScheduler()->scheduleRepeatingTask($task, $period);
    }

    public function remove(): void {
        $id = $this->id;
        unset(self::$message[$id], self::$scheduler[$id]);
    }

    /**
     * @param string $string
     *
     * @return array|null
     */
    public function fromString(string $string): ?array {

        $items = [];
        foreach (explode(",", $string) as $b) {
            try {
                $items[] = LegacyStringToItemParser::getInstance()->parse($b);
            } catch (LegacyStringToItemParserException) {
                return null;
            }
        }
        $blocks = [];
        foreach ($items as $item) {
            $item_name = $item->getName();
            $block = $item->getBlock();
            $block_name = $block->getName();
            if ($item_name !== $block_name) return null;
            $blocks[(string)$block] = $block;
        }
        return $blocks;
    }

    public function hasHeightLimit(int $y): bool {
        return $y < 0 or $y > World::Y_MAX;
    }

    public function checkChunkLoaded(World $level, int $x, int $z): void {
        if (!$level->isChunkLoaded($x >> 4, $z >> 4))
            $level->loadChunk($x >> 4, $z >> 4, true);
    }

    public function hasBlockRestriction(int $count = 1): bool {
        $this->restriction += $count;
        if ($this->restriction < $this->stopper)
            return false;
        $this->restriction = 0;
        return true;
    }

    /**
     * @param float $value
     */
    public function setMeter(float $value): void {
        $this->meter = 100 / $value;
    }

    public function addMeter(): void {
        $round = round($this->gage += $this->meter);
        $name = $this->sender->getName();
        $id = $this->id;
        self::$message[$id] = Language::get('processing.meter', $id, $name, $round);
        foreach (self::$message as $message)
            $list = isset($list) ? $list . TextFormat::EOL . $message : $message;
        Server::getInstance()->broadcastTip($list);
    }

    public function startMessage(string $command): void {
        $id = $this->id;
        $name = $this->sender->getName();
        $size = $this->getSize();
        #Server::getInstance()->broadcastMessage(Language::get('processing.start', $id, $name, $command, $size));
    }

    public function endMessage(string $command): void {
        $id = $this->id;
        $name = $this->sender->getName();
        #Server::getInstance()->broadcastMessage(Language::get('processing.end', $id, $name, $command));
    }
}