<?php

declare(strict_types=1);

namespace PurpleParlor\Games\Strategy;

use PurpleParlor\Games\Cards\Card;
use PurpleParlor\Games\Cards\Deck;
use PurpleParlor\Games\Contracts\RandomSource;
use PurpleParlor\Games\DTO\GameOutcome;
use PurpleParlor\Games\DTO\GameRound;
use PurpleParlor\Games\Exceptions\GameException;

final class SolitaireGameStrategy implements StrategyInterface
{
    use StrategySupport;

    public function __construct(private readonly RandomSource $random) {}

    public function resolve(GameRound $round, array $configuration): GameOutcome
    {
        return match ($round->slug) {
            'klondike-solitaire' => $this->klondike($round),
            'pyramid-solitaire' => $this->pyramid($round),
            'tripeaks-solitaire' => $this->tripeaks($round),
            'freecell' => $this->freecell($round),
            default => throw new GameException('No solitaire strategy is registered for this game.', 'strategy_missing', 500),
        };
    }

    private function klondike(GameRound $round): GameOutcome
    {
        if ($round->serverState === []) {
            $this->assertStart($round); $deck = new Deck($this->random); $tableau = []; $faceUp = [];
            for ($column = 0; $column < 7; ++$column) { $tableau[$column] = $this->cardCodes($deck->drawMany($column + 1)); $faceUp[$column] = 1; }
            $state = $this->baseState($round) + ['tableau' => $tableau, 'faceUp' => $faceUp, 'stock' => $deck->remainingCodes(), 'waste' => [], 'foundations' => ['clubs' => [], 'diamonds' => [], 'hearts' => [], 'spades' => []], 'moves' => 0];
            return $this->solitairePending('deal', ['variant' => 'Klondike'], ['draw', 'move'], $state, $this->klondikePublic($state));
        }
        $state = $this->requireState($round);
        if ($round->action === 'draw') {
            if ($state['stock'] === []) {
                if ($state['waste'] === []) throw new GameException('No cards remain to draw.');
                $state['stock'] = array_reverse($state['waste']); $state['waste'] = [];
            } else {
                $state['waste'][] = array_pop($state['stock']);
            }
            ++$state['moves']; return $this->solitairePending('drew_card', ['wasteTop' => $state['waste'] === [] ? null : Card::fromCode(end($state['waste']))->jsonSerialize()], ['draw', 'move'], $state, $this->klondikePublic($state));
        }
        if ($round->action !== 'move') throw new GameException('Klondike accepts draw or move.');
        $from = $this->choice($round->input['from'] ?? '', ['waste', 'tableau'], 'source'); $to = $this->choice($round->input['to'] ?? '', ['tableau', 'foundation'], 'destination');
        $sourceColumn = $this->integerOption($round->input['sourceColumn'] ?? 0, 0, 6, 'source column'); $destination = $this->integerOption($round->input['destination'] ?? 0, 0, $to === 'tableau' ? 6 : 3, 'destination');
        if ($from === 'waste') { if ($state['waste'] === []) throw new GameException('The waste pile is empty.'); $code = array_pop($state['waste']); }
        else { if ($state['tableau'][$sourceColumn] === []) throw new GameException('That tableau column is empty.'); $code = array_pop($state['tableau'][$sourceColumn]); }
        $card = Card::fromCode($code);
        if ($to === 'foundation') {
            $suitIndex = array_search($card->suit, Card::SUITS, true); if ($destination !== $suitIndex) throw new GameException('Move the card to its matching suit foundation.');
            $foundation = $state['foundations'][$card->suit]; $expected = count($foundation) + 2; if (count($foundation) === 0) $expected = 14;
            // Foundations are Ace (14) then 2..King; normalize by card count.
            $validRank = count($foundation) === 0 ? 14 : count($foundation) + 1;
            if ($card->rank !== $validRank) { $this->restoreKlondikeSource($state, $from, $sourceColumn, $code); throw new GameException('Foundation cards must build upward from Ace.'); }
            $state['foundations'][$card->suit][] = $code;
        } else {
            $target = $state['tableau'][$destination] === [] ? null : Card::fromCode(end($state['tableau'][$destination]));
            if ($target !== null && (!self::oppositeColor($card, $target) || $card->rank !== $target->rank - 1)) { $this->restoreKlondikeSource($state, $from, $sourceColumn, $code); throw new GameException('Tableau cards build downward in alternating colors.'); }
            if ($target === null && $card->rank !== 13) { $this->restoreKlondikeSource($state, $from, $sourceColumn, $code); throw new GameException('Only a King may fill an empty tableau column.'); }
            $state['tableau'][$destination][] = $code;
        }
        ++$state['moves']; $complete = array_sum(array_map('count', $state['foundations'])) === 52;
        return $complete ? $this->solitaireComplete('solved', ['moves' => $state['moves']], $this->klondikePublic($state)) : $this->solitairePending('moved', ['card' => $card->jsonSerialize()], ['draw', 'move'], $state, $this->klondikePublic($state));
    }

    private function pyramid(GameRound $round): GameOutcome
    {
        if ($round->serverState === []) {
            $this->assertStart($round); $deck = new Deck($this->random); $tableau = $this->cardCodes($deck->drawMany(28)); $waste = [$deck->draw()->code()];
            $state = $this->baseState($round) + ['tableau' => $tableau, 'removed' => [], 'stock' => $deck->remainingCodes(), 'waste' => $waste, 'moves' => 0];
            return $this->solitairePending('deal', ['variant' => 'Pyramid'], ['remove', 'draw'], $state, $this->pyramidPublic($state));
        }
        $state = $this->requireState($round);
        if ($round->action === 'draw') {
            if ($state['stock'] === []) throw new GameException('The Pyramid stock is empty.');
            $state['waste'][] = array_pop($state['stock']); ++$state['moves']; return $this->solitairePending('drew_card', [], ['remove', 'draw'], $state, $this->pyramidPublic($state));
        }
        if ($round->action !== 'remove') throw new GameException('Pyramid accepts remove or draw.');
        $indices = $round->input['indices'] ?? []; if (!is_array($indices) || count($indices) < 1 || count($indices) > 2) throw new GameException('Select one King or two cards totaling thirteen.', 'invalid_option');
        $indices = array_values(array_unique(array_map(fn ($v): int => $this->integerOption($v, -1, 27, 'card index'), $indices)));
        $cards = [];
        foreach ($indices as $index) {
            if ($index === -1) { if ($state['waste'] === []) throw new GameException('The waste pile is empty.'); $cards[] = Card::fromCode(end($state['waste'])); }
            else { if (!$this->pyramidExposed($index, $state['removed']) || in_array($index, $state['removed'], true)) throw new GameException('That Pyramid card is covered.'); $cards[] = Card::fromCode($state['tableau'][$index]); }
        }
        $values = array_map(static fn (Card $card): int => $card->rank === 14 ? 1 : min($card->rank, 13), $cards); $valid = count($cards) === 1 ? $values[0] === 13 : array_sum($values) === 13;
        if (!$valid) throw new GameException('Pyramid removals must be a King or a pair totaling thirteen.');
        foreach ($indices as $index) { if ($index === -1) array_pop($state['waste']); else $state['removed'][] = $index; }
        ++$state['moves']; $complete = count($state['removed']) === 28;
        return $complete ? $this->solitaireComplete('pyramid_cleared', ['moves' => $state['moves']], $this->pyramidPublic($state)) : $this->solitairePending('removed', ['indices' => $indices], ['remove', 'draw'], $state, $this->pyramidPublic($state));
    }

    private function tripeaks(GameRound $round): GameOutcome
    {
        if ($round->serverState === []) {
            $this->assertStart($round); $deck = new Deck($this->random); $tableau = $this->cardCodes($deck->drawMany(28)); $waste = [$deck->draw()->code()];
            $state = $this->baseState($round) + ['tableau' => $tableau, 'removed' => [], 'stock' => $deck->remainingCodes(), 'waste' => $waste, 'moves' => 0];
            return $this->solitairePending('deal', ['variant' => 'TriPeaks'], ['take', 'draw'], $state, $this->tripeaksPublic($state));
        }
        $state = $this->requireState($round);
        if ($round->action === 'draw') {
            if ($state['stock'] === []) throw new GameException('The TriPeaks stock is empty.');
            $state['waste'][] = array_pop($state['stock']); ++$state['moves']; return $this->solitairePending('drew_card', [], ['take', 'draw'], $state, $this->tripeaksPublic($state));
        }
        if ($round->action !== 'take') throw new GameException('TriPeaks accepts take or draw.');
        $index = $this->integerOption($round->input['index'] ?? null, 0, 27, 'card index'); if (!$this->tripeaksExposed($index, $state['removed'])) throw new GameException('That TriPeaks card is covered.');
        $card = Card::fromCode($state['tableau'][$index]); $waste = Card::fromCode(end($state['waste'])); $difference = abs($card->rank - $waste->rank);
        if (!($difference === 1 || $difference === 12)) throw new GameException('Choose a card one rank above or below the waste card.');
        $state['removed'][] = $index; $state['waste'][] = $card->code(); ++$state['moves']; $complete = count($state['removed']) === 28;
        return $complete ? $this->solitaireComplete('peaks_cleared', ['moves' => $state['moves']], $this->tripeaksPublic($state)) : $this->solitairePending('card_taken', ['index' => $index], ['take', 'draw'], $state, $this->tripeaksPublic($state));
    }

    private function freecell(GameRound $round): GameOutcome
    {
        if ($round->serverState === []) {
            $this->assertStart($round); $deck = new Deck($this->random); $columns = array_fill(0, 8, []); $column = 0;
            while ($deck->remaining() > 0) { $columns[$column % 8][] = $deck->draw()->code(); ++$column; }
            $state = $this->baseState($round) + ['columns' => $columns, 'freecells' => [null, null, null, null], 'foundations' => ['clubs' => [], 'diamonds' => [], 'hearts' => [], 'spades' => []], 'moves' => 0];
            return $this->solitairePending('deal', ['variant' => 'FreeCell'], ['move'], $state, $this->freecellPublic($state));
        }
        if ($round->action !== 'move') throw new GameException('FreeCell accepts move actions.');
        $state = $this->requireState($round); $from = $this->choice($round->input['from'] ?? '', ['column', 'freecell'], 'source'); $to = $this->choice($round->input['to'] ?? '', ['column', 'freecell', 'foundation'], 'destination');
        $fromIndex = $this->integerOption($round->input['fromIndex'] ?? 0, 0, $from === 'column' ? 7 : 3, 'source index'); $toIndex = $this->integerOption($round->input['toIndex'] ?? 0, 0, $to === 'column' ? 7 : 3, 'destination index');
        if ($from === 'column') { if ($state['columns'][$fromIndex] === []) throw new GameException('That column is empty.'); $code = array_pop($state['columns'][$fromIndex]); }
        else { if ($state['freecells'][$fromIndex] === null) throw new GameException('That free cell is empty.'); $code = $state['freecells'][$fromIndex]; $state['freecells'][$fromIndex] = null; }
        $card = Card::fromCode($code); $valid = true;
        if ($to === 'freecell') { $valid = $state['freecells'][$toIndex] === null; if ($valid) $state['freecells'][$toIndex] = $code; }
        elseif ($to === 'column') { $target = $state['columns'][$toIndex] === [] ? null : Card::fromCode(end($state['columns'][$toIndex])); $valid = $target === null || (self::oppositeColor($card, $target) && $card->rank === $target->rank - 1); if ($valid) $state['columns'][$toIndex][] = $code; }
        else { $suit = Card::SUITS[$toIndex]; $foundation = $state['foundations'][$suit]; $valid = $card->suit === $suit && $card->rank === (count($foundation) === 0 ? 14 : count($foundation) + 1); if ($valid) $state['foundations'][$suit][] = $code; }
        if (!$valid) { if ($from === 'column') $state['columns'][$fromIndex][] = $code; else $state['freecells'][$fromIndex] = $code; throw new GameException('That FreeCell move is not legal.'); }
        ++$state['moves']; $complete = array_sum(array_map('count', $state['foundations'])) === 52;
        return $complete ? $this->solitaireComplete('freecell_solved', ['moves' => $state['moves']], $this->freecellPublic($state)) : $this->solitairePending('moved', ['card' => $card->jsonSerialize()], ['move'], $state, $this->freecellPublic($state));
    }

    private function assertStart(GameRound $round): void { if ($round->action !== 'play') throw new GameException('Start this solitaire game with play.'); }
    /** @return array<string,mixed> */ private function baseState(GameRound $round): array { return ['slug' => $round->slug, 'roundId' => $round->id, 'wager' => $round->wager, 'version' => 1]; }
    /** @return array<string,mixed> */ private function requireState(GameRound $round): array { $s=$round->serverState; if (($s['slug']??null)!==$round->slug||($s['roundId']??null)!==$round->id||($s['wager']??null)!==$round->wager) throw new GameException('Stored solitaire state does not match this round.','state_conflict',409); return $s; }
    private static function oppositeColor(Card $a, Card $b): bool { $red = static fn(Card $c): bool => in_array($c->suit,['diamonds','hearts'],true); return $red($a)!==$red($b); }

    /** @param array<string,mixed> $state */
    private function klondikePublic(array $state): array { $columns=[]; foreach($state['tableau'] as $i=>$cards){$hidden=max(0,count($cards)-(int)$state['faceUp'][$i]);$columns[$i]=array_merge(array_fill(0,$hidden,['hidden'=>true]),array_map(fn($c)=>Card::fromCode($c)->jsonSerialize(),array_slice($cards,$hidden)));} return ['tableau'=>$columns,'stockCount'=>count($state['stock']),'wasteTop'=>$state['waste']===[]?null:Card::fromCode(end($state['waste']))->jsonSerialize(),'foundations'=>array_map('count',$state['foundations']),'moves'=>$state['moves']]; }
    /** @param array<string,mixed> $state */ private function restoreKlondikeSource(array &$state,string $from,int $column,string $code):void { if($from==='waste')$state['waste'][]=$code;else $state['tableau'][$column][]=$code; }
    /** @param list<int> $removed */ private function pyramidExposed(int $index,array $removed):bool { $row=(int)floor((sqrt(8*$index+1)-1)/2); if($row===6)return true; $start=intdiv($row*($row+1),2);$offset=$index-$start;$next=intdiv(($row+1)*($row+2),2);return in_array($next+$offset,$removed,true)&&in_array($next+$offset+1,$removed,true); }
    /** @param array<string,mixed> $state */ private function pyramidPublic(array $state):array{$cards=[];foreach($state['tableau'] as $i=>$code)$cards[$i]=in_array($i,$state['removed'],true)?null:($this->pyramidExposed($i,$state['removed'])?Card::fromCode($code)->jsonSerialize():['hidden'=>true]);return ['tableau'=>$cards,'stockCount'=>count($state['stock']),'wasteTop'=>$state['waste']===[]?null:Card::fromCode(end($state['waste']))->jsonSerialize(),'removed'=>$state['removed'],'moves'=>$state['moves']];}
    /** @param list<int> $removed */ private function tripeaksExposed(int $index,array $removed):bool{$cover=[0=>[3,4],1=>[5,6],2=>[7,8],3=>[9,10],4=>[10,11],5=>[12,13],6=>[13,14],7=>[15,16],8=>[16,17],9=>[18,19],10=>[19,20],11=>[20,21],12=>[21,22],13=>[22,23],14=>[23,24],15=>[24,25],16=>[25,26],17=>[26,27]];return !in_array($index,$removed,true)&&(!isset($cover[$index])||(in_array($cover[$index][0],$removed,true)&&in_array($cover[$index][1],$removed,true)));}
    /** @param array<string,mixed> $state */ private function tripeaksPublic(array $state):array{$cards=[];foreach($state['tableau'] as $i=>$code)$cards[$i]=in_array($i,$state['removed'],true)?null:($this->tripeaksExposed($i,$state['removed'])?Card::fromCode($code)->jsonSerialize():['hidden'=>true]);return ['tableau'=>$cards,'stockCount'=>count($state['stock']),'wasteTop'=>Card::fromCode(end($state['waste']))->jsonSerialize(),'removed'=>$state['removed'],'moves'=>$state['moves']];}
    /** @param array<string,mixed> $state */ private function freecellPublic(array $state):array{return ['columns'=>array_map(fn($col)=>array_map(fn($c)=>Card::fromCode($c)->jsonSerialize(),$col),$state['columns']),'freecells'=>array_map(fn($c)=>$c===null?null:Card::fromCode($c)->jsonSerialize(),$state['freecells']),'foundations'=>array_map('count',$state['foundations']),'moves'=>$state['moves']];}
    /** @param array<string,mixed> $result @param array<string,mixed> $public */ private function solitaireComplete(string $code,array $result,array $public):GameOutcome{$result['payoutMultiplierBps']=0;return new GameOutcome($code,$result,$result,[],[],$public,true);}
    /** @param array<string,mixed> $result @param list<string> $actions @param array<string,mixed> $state @param array<string,mixed> $public */ private function solitairePending(string $code,array $result,array $actions,array $state,array $public):GameOutcome{$result['payoutMultiplierBps']=0;return new GameOutcome($code,$result,$result,$actions,$state,$public,false);}
}
