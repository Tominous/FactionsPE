<?php /** @noinspection PhpUndefinedMethodInspection */

namespace factions\engine;

use pocketmine\level\Level;
use pocketmine\level\format\Chunk;
use pocketmine\level\particle\Particle;
use pocketmine\level\particle\RedstoneParticle;
use pocketmine\level\particle\DustParticle;
use pocketmine\math\Vector3;

use localizer\Localizer;

use factions\entity\Member;
use factions\utils\Gameplay;
use factions\event\member\MemberTraceEvent;
use factions\utils\Text;
use factions\relation\Relation;

class SeeChunkEngine extends Engine {

    /** 
     * Holds player and chunk objects
     * @var mixed[] 
     */
    protected $chunks = [];

    /**
     * Space between each particle in a line. Note: must divide 16 with no remainder
     * @var int
     */
    protected $step = 2;
    protected $density = 1;
    protected $yOffset = 0.8;
    /**
     * How many borders will be drawn vertically. Note: must be even number
     * @var int
     */
    protected $yLevels = 4;

    /**
     * Space between two borders vertically
     * @var int
     */
    protected $yMargin = 5;

    const PLAYER               = 0;
    const CHUNK                = 1;
    const BRUSH 			   = 2;
    const LEVEL 			   = 3;
    const DEFAULT_INTERVAL     = 5;

    public function setup()
    {
        # Do nothing
    }

    /**
     * @priority MONITOR
     * @param MemberTraceEvent $event
     * @throws \Exception
     */
    public function onTrace(MemberTraceEvent $event) {
        /** @var Member $member */
    	$member = $event->getMember();
    	if($member->isSeeingChunk()) {
    		$this->removeChunk($member);
    		$this->setChunk($member, $event->getTo()->getChunk(), $event->getTo()->getLevel());
    	}
    }

    /**
     * Creates particles which will be used to show edges of chunk
     * @param Chunk $chunk
     * @param float $step Must divide 16 with no remain
     * @param string $particle class name
     * @param float $y Usually player's y if there is no blocks around
     * @param Member $player = null
     *
     * @return Particle[]
     */
    public function createBrush(Chunk $chunk, string $particle, float $y, float $step = 2, Member $player = null): array {
    	if(16 % $step) throw new \InvalidArgumentException("invalid step(=$step) for chunk {$chunk->getX()}:{$chunk->getZ()} remainder: " . 16 % $step);
    	$particles = [];
    	$minX = $chunk->getX() << 4;
    	$minZ = $chunk->getZ() << 4;
  		$endX = $minX + 16;
  		$endZ = $minZ + 16;
    	for($x = $minX; $x <= $endX; $x += $step) {
    		# Side 1
    		$particles[] = $this->makeParticleFor($x, $y, $minZ, $particle, $player);
    	}
    	for($z = $minZ + $step; $z <= $endZ; $z += $step) {
    		# Side 2
    		$particles[] = $this->makeParticleFor($endX, $y, $z, $particle, $player);
    	}
    	for($x = $endX - $step; $x >= $minX; $x -= $step) {
    		# Side 3
    		$particles[] = $this->makeParticleFor($x, $y, $endZ, $particle, $player);
    	}
    	for($z = $endZ - $step; $z >= $minZ + $step; $z -= $step) {
    		# Side 2
    		$particles[] = $this->makeParticleFor($minX, $y, $z, $particle, $player);
    	}
    	return $particles;
    }

    /**
     * @param float $x
     * @param float $y
     * @param float $z
     * @param string $particle class name
     * @param Member $player some particles may require Member object
     *
     * @return Particle
     */
    public function makeParticleFor(float $x, float $y, float $z, string $particle, Member $player = null): Particle {
    	if($particle === RedstoneParticle::class) {
    		return new $particle(new Vector3($x, $y, $z), self::DEFAULT_INTERVAL);
    	}
    	if($particle === DustParticle::class) {
    		$rel = $player->getRelationToPlot();
    		$rel = Relation::isRankValid($rel) ? Relation::ALLY : $rel;
    		$rgb = Text::getRGB($player ? Text::getRelationColor($rel) : Relation::NEUTRAL);
    		return new $particle(new Vector3($x, $y, $z), $rgb[0], $rgb[1], $rgb[2], 255);
    	}
    	return new $particle(new Vector3($x, $y, $z));
    }

    /**
     * Set the chunk player sees. Only one chunk per player
     * @param Member $player
     * @param Chunk $chunk
     * @param Level $level
     * @throws \Exception
     */
    public function setChunk(Member $player, Chunk $chunk, Level $level): void {
        $this->chunks[strtolower($player->getName())] = [
            self::PLAYER => $player,
            self::CHUNK => $chunk,
            self::BRUSH => $this->createBrush($chunk, DustParticle::CLASS, $player->getPlayer()->getFloorY() + $this->yOffset, $this->step, $player),
            self::LEVEL => $level
        ];
        if(!$this->isLooping()) $this->startLoop(Gameplay::get('seechunk-task-interval', self::DEFAULT_INTERVAL));
    }

    /**
     * @param $player
     * @throws \Exception
     */
    public function removeChunk($player) {
        $player = $player instanceof Member ? strtolower($player->getName()) : strtolower($player);
        unset($this->chunks[$player]);
        if(empty($this->chunks) && $this->isLooping()) $this->stopLoop(); // Don't waste CPU
    }

    public function isSeeingChunk(Member $player, bool $test = true): ?bool {
        /** @var Chunk|null $c */
        $c = $this->chunks[strtolower($player->getName())][self::CHUNK] ?? null;
        if($sc = $player->isSeeingChunk()) {
            return $c !== null;
        // Drawing this chunk without request (seechunk disabled, without telling the Engine)
        } elseif ($test && !$sc && $c !== null) {
            throw new \LogicException("player '{$player->getName()}' is seeing chunk {$c->getX()}:{$c->getZ()} with invalid request");
        }
        return false;
    }

    /**
     * @param string $name
     */
    public function drawChunk(string $name): void {
        if(!$this->chunks[$name][self::LEVEL]->isClosed()) {

        	// This will draw the borders and follow the player as it moves.
        	foreach($this->chunks[$name][self::BRUSH] as $particle) {

        		$y = max(0, $this->chunks[$name][self::PLAYER]->getPlayer()->getY() - ($this->yLevels / 2) * $this->yMargin);
        		$endY = $endY = $y + $this->yLevels + ($this->yLevels / 2) * $this->yMargin;

        		for($y+=0; $y <= $endY; $y += $this->yMargin) {
        			$particle->y = $y + $this->yMargin / 2 + 0.5;
        			$this->chunks[$name][self::LEVEL]->addParticle($particle, [$this->chunks[$name][self::PLAYER]->getPlayer()]);
        		}

        	}

        }
    }

    public function onCancel() {
    	foreach($this->chunks as $data) {
    		$data[self::PLAYER]->setSeeChunk(false);
    		$data[self::PLAYER]->sendMessage(Localizer::translatable('see-chunk-disabled-engine-stop'));
    	}
    	$this->chunks = [];
    }

    /**
     * Draws chunks
     *
     * @param int $currentTick
     */
    public function onRun(int $currentTick)
    {
        foreach($this->chunks as $name => $data) {
            $this->drawChunk($name);
        }
    }
	
}