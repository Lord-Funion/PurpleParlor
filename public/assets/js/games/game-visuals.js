/**
 * Polished visualizations for Purple Parlor's server-authoritative game results.
 *
 * This module never generates or infers an outcome. Every highlighted pocket,
 * reel symbol, card, die, finish order, and revealed tile comes from `payload`.
 * CSS supplies motion between known states; it does not choose a state.
 *
 * Integration:
 *   const visuals = new GameVisualRenderer(node, { slug: 'coin-flip' });
 *   visuals.render(apiPayload, { reducedMotion: false });
 */

const SVG_NS = 'http://www.w3.org/2000/svg';
const CARD_SUITS = Object.freeze({ clubs: '♣', diamonds: '♦', hearts: '♥', spades: '♠' });
const RED_SUITS = new Set(['diamonds', 'hearts']);
const ROULETTE_REDS = new Set(['1','3','5','7','9','12','14','16','18','19','21','23','25','27','30','32','34','36']);
const PRIZE_LABELS = Object.freeze(['0x', '.25x', '.75x', '.75x', '1x', '1.5x', '0x', '2x', '.25x', '.75x', '0x', '2.5x', '.5x', '1x', '0x', '4x']);
const MEMORY_SYMBOLS = Object.freeze(['onion', 'lavender', 'crown', 'book', 'ember', 'moon', 'teacup', 'star']);

export const GAME_VISUAL_ARCHETYPES = Object.freeze({
  'classic-three-reel-slots': 'slots',
  'five-reel-video-slots': 'slots',
  'european-roulette': 'wheel',
  'american-roulette': 'wheel',
  'prize-wheel': 'wheel',
  'craps': 'dice',
  'sic-bo': 'dice',
  'over-under-dice': 'dice',
  'coin-flip': 'coin',
  'blackjack': 'cards',
  'baccarat': 'cards',
  'video-poker': 'cards',
  'texas-holdem': 'cards',
  'three-card-poker': 'cards',
  'caribbean-stud': 'cards',
  'casino-war': 'cards',
  'red-dog': 'cards',
  'let-it-ride': 'cards',
  'pai-gow-poker': 'cards',
  'hi-lo': 'cards',
  'five-card-draw': 'cards',
  'teen-patti-practice': 'cards',
  'parlor-switch': 'cards',
  'higher-lower-streak': 'cards',
  'klondike-solitaire': 'solitaire',
  'pyramid-solitaire': 'solitaire',
  'tripeaks-solitaire': 'solitaire',
  'freecell': 'solitaire',
  'horse-racing': 'racing',
  'lucky-cups': 'cups',
  'pachinko': 'pachinko',
  'gem-drop': 'gem-grid',
  'scratch-cards': 'scratch',
  'mines': 'board',
  'treasure-tiles': 'board',
  'memory-match': 'memory',
  'keno': 'numbers',
  'bingo': 'bingo',
  'number-draw': 'number-reveal',
});

const ARCHETYPE_LABELS = Object.freeze({
  slots: 'Reel result', wheel: 'Wheel result', dice: 'Dice result', coin: 'Coin result',
  cards: 'Card table', solitaire: 'Solitaire table', racing: 'Race result', cups: 'Cup result',
  pachinko: 'Pachinko result', 'gem-grid': 'Gem grid', scratch: 'Scratch card', board: 'Game board',
  memory: 'Memory board', numbers: 'Number draw', bingo: 'Bingo card', 'number-reveal': 'Number result',
  reveal: 'Round result',
});

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function element(tag, className = '', text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = String(text);
  return node;
}

function svgElement(tag, attributes = {}, text) {
  const node = document.createElementNS(SVG_NS, tag);
  Object.entries(attributes).forEach(([name, value]) => node.setAttribute(name, String(value)));
  if (text !== undefined) node.textContent = String(text);
  return node;
}

function humanize(value) {
  return String(value ?? '')
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .replace(/[_-]+/g, ' ')
    .replace(/^./, letter => letter.toUpperCase());
}

function rankLabel(rank) {
  return ({ 11: 'J', 12: 'Q', 13: 'K', 14: 'A' })[Number(rank)] || String(rank ?? '?');
}

function multiplierLabel(basisPoints) {
  const value = Number(basisPoints) / 10000;
  if (!Number.isFinite(value)) return '—';
  return `${value.toLocaleString(undefined, { minimumFractionDigits: value > 0 && value < 1 ? 2 : 0, maximumFractionDigits: 2 })}×`;
}

function safeArray(value) {
  return Array.isArray(value) ? value : [];
}

function safeIndexSet(value) {
  return new Set(safeArray(value).map(item => Number(item)).filter(Number.isFinite));
}

function indexedValues(value) {
  if (Array.isArray(value)) {
    return Object.fromEntries(value.flatMap((item, index) => item === undefined || item === null ? [] : [[index, item]]));
  }
  return isObject(value) ? value : {};
}

function dataFor(payload) {
  const state = isObject(payload?.state) ? payload.state : {};
  const result = isObject(payload?.result) ? payload.result : {};
  const display = isObject(payload?.display) && Object.keys(payload.display).length ? payload.display : result;
  return { ...state, ...display };
}

function ordinal(index) {
  const number = Number(index) + 1;
  const suffix = number % 10 === 1 && number % 100 !== 11 ? 'st'
    : number % 10 === 2 && number % 100 !== 12 ? 'nd'
      : number % 10 === 3 && number % 100 !== 13 ? 'rd' : 'th';
  return `${number}${suffix}`;
}

function setAnimationIndex(node, index) {
  node.style.setProperty('--pp-i', String(index));
  return node;
}

function symbolSvg(name) {
  const normalized = String(name || 'mystery').toLowerCase();
  const svg = svgElement('svg', { viewBox: '0 0 64 64', 'aria-hidden': 'true', focusable: 'false' });
  const group = svgElement('g', { class: `pp-symbol-art pp-symbol-art--${normalized}` });
  svg.append(group);

  if (normalized === 'onion') {
    group.append(
      svgElement('path', { d: 'M32 10c-2 8-12 10-16 20-6 16 3 26 16 26s22-10 16-26c-4-10-14-12-16-20Z', class: 'pp-fill-main' }),
      svgElement('path', { d: 'M32 13V5m-1 8-8-7m10 7 8-7M23 31c2 11 6 17 9 22m9-22c-2 11-6 17-9 22', class: 'pp-stroke-light' }),
    );
  } else if (normalized === 'lavender') {
    group.append(svgElement('path', { d: 'M32 56V16M31 30 18 21m14 18 14-10m-14 2 11-17', class: 'pp-stroke-main' }));
    [[18,18],[25,13],[34,12],[43,13],[46,25],[20,29],[27,37],[39,34]].forEach(([cx,cy]) => group.append(svgElement('circle', { cx, cy, r: 5, class: 'pp-fill-main' })));
  } else if (normalized === 'crown') {
    group.append(
      svgElement('path', { d: 'm10 20 12 11 10-19 10 19 12-11-5 29H15Z', class: 'pp-fill-gold' }),
      svgElement('path', { d: 'M16 48h32', class: 'pp-stroke-light' }),
      svgElement('circle', { cx: 32, cy: 37, r: 4, class: 'pp-fill-main' }),
    );
  } else if (normalized === 'book') {
    group.append(
      svgElement('path', { d: 'M8 14h19c4 0 5 3 5 6v34c0-5-3-7-7-7H8Zm48 0H37c-4 0-5 3-5 6v34c0-5 3-7 7-7h17Z', class: 'pp-fill-main' }),
      svgElement('path', { d: 'M32 20v34M13 24h12m-12 8h12m14-8h12m-12 8h12', class: 'pp-stroke-light' }),
    );
  } else if (['fireplace', 'ember'].includes(normalized)) {
    if (normalized === 'fireplace') group.append(svgElement('path', { d: 'M8 12h48v44H44V30H20v26H8Z', class: 'pp-fill-main' }));
    group.append(
      svgElement('path', { d: 'M32 54c-11 0-17-7-14-16 2-6 8-9 7-19 8 5 9 11 8 16 4-2 6-6 7-10 8 9 9 17 5 23-3 4-7 6-13 6Z', class: 'pp-fill-gold' }),
      svgElement('path', { d: 'M31 51c-5-2-7-6-4-11 2-3 4-5 4-9 6 5 8 15 0 20Z', class: 'pp-fill-bright' }),
    );
  } else if (normalized === 'moon') {
    group.append(svgElement('path', { d: 'M48 45A24 24 0 1 1 31 8a20 20 0 0 0 17 37Z', class: 'pp-fill-gold' }));
  } else if (normalized === 'seven') {
    group.append(svgElement('text', { x: 32, y: 49, 'text-anchor': 'middle', class: 'pp-symbol-seven' }, '7'));
  } else if (normalized === 'wild') {
    group.append(svgElement('path', { d: 'm32 5 7 17 19 1-15 12 5 19-16-10-16 10 5-19L6 23l19-1Z', class: 'pp-fill-gold' }), svgElement('text', { x: 32, y: 39, 'text-anchor': 'middle', class: 'pp-symbol-word' }, 'W'));
  } else if (normalized === 'scatter' || normalized === 'star') {
    group.append(svgElement('path', { d: 'm32 5 8 18 19 2-15 13 5 19-17-10-17 10 5-19L5 25l19-2Z', class: 'pp-fill-main' }));
  } else if (['amethyst', 'ruby', 'topaz', 'pearl', 'emerald'].includes(normalized)) {
    if (normalized === 'pearl') group.append(svgElement('circle', { cx: 32, cy: 32, r: 22, class: 'pp-fill-main' }), svgElement('circle', { cx: 25, cy: 24, r: 7, class: 'pp-fill-bright' }));
    else group.append(svgElement('path', { d: 'm16 13 32 0 10 15-26 29L6 28Z', class: 'pp-fill-main' }), svgElement('path', { d: 'm16 13 7 15 9 29 9-29 7-15M6 28h52M23 28l9-15 9 15', class: 'pp-stroke-light' }));
  } else if (normalized === 'teacup') {
    group.append(svgElement('path', { d: 'M13 19h35v16c0 12-7 18-18 18S13 47 13 35Zm35 6h5c9 0 9 14 0 14h-6M9 55h43', class: 'pp-fill-main pp-stroke-light' }));
  } else {
    group.append(svgElement('path', { d: 'm32 6 8 17 19 3-14 13 3 19-16-9-16 9 3-19L5 26l19-3Z', class: 'pp-fill-main' }));
  }
  return svg;
}

function slotSymbol(name, index = 0) {
  const tile = setAnimationIndex(element('div', `pp-slot-symbol pp-slot-symbol--${String(name).toLowerCase()}`), index);
  tile.append(symbolSvg(name), element('span', 'pp-slot-symbol__label', humanize(name)));
  tile.setAttribute('aria-label', humanize(name));
  return tile;
}

function cardNode(card, index = 0, { compact = false } = {}) {
  const hidden = !card || card.hidden;
  const node = setAnimationIndex(element('div', `pp-playing-card${hidden ? ' is-hidden' : ''}${compact ? ' is-compact' : ''}`), index);
  if (hidden) {
    node.setAttribute('aria-label', 'Face-down card');
    node.append(element('span', 'pp-card-back-mark', '♦'));
    return node;
  }
  const suit = CARD_SUITS[card.suit] || '';
  const rank = rankLabel(card.rank);
  node.dataset.suit = card.suit || '';
  node.classList.toggle('is-red', RED_SUITS.has(card.suit));
  node.setAttribute('aria-label', card.label || card.code || `${rank} of ${card.suit}`);
  node.append(
    element('span', 'pp-card-corner', `${rank}${suit}`),
    element('span', 'pp-card-suit', suit),
    element('span', 'pp-card-corner pp-card-corner--bottom', `${rank}${suit}`),
  );
  return node;
}

function cardsFrom(value) {
  if (Array.isArray(value)) return value.filter(item => isObject(item));
  if (Number.isInteger(value) && value > 0 && value <= 10) return Array.from({ length: value }, () => ({ hidden: true }));
  return isObject(value) && (value.hidden || value.rank) ? [value] : [];
}

function visualHeader(payload, archetype) {
  const header = element('div', 'pp-visual-header');
  const title = element('div', 'pp-visual-title');
  title.append(element('span', 'pp-visual-kicker', ARCHETYPE_LABELS[archetype] || ARCHETYPE_LABELS.reveal), element('strong', '', humanize(payload?.outcome || 'Round update')));
  const payout = Number(payload?.payout || 0);
  const badge = element('span', `pp-result-badge pp-result-badge--${payout > 0 ? 'positive' : 'neutral'}`);
  badge.textContent = payload?.outcome === 'ready'
    ? 'Ready to play'
    : (payload?.complete ? `${payout.toLocaleString()} Cozy Coins` : 'Round in progress');
  header.append(title, badge);
  return header;
}

function renderSlots(payload, data) {
  const scene = element('div', `pp-slots-scene pp-slots-scene--${Array.isArray(data.grid) ? 'video' : 'classic'}`);
  const cabinet = element('div', 'pp-slot-cabinet');
  cabinet.append(element('div', 'pp-slot-marquee', payload.slug === 'classic-three-reel-slots' ? 'ROYAL ONION' : 'MOONLIT LIBRARY'));
  const windowNode = element('div', 'pp-reel-window');
  const grid = Array.isArray(data.grid) ? data.grid : null;
  const reels = grid || safeArray(data.reels).map(symbol => [symbol]);
  const winningCells = new Set();
  const scatterOnlyWin = Boolean(grid && safeArray(data.lineWins).length === 0 && Number(data.scatters || 0) >= 3);
  if (grid) {
    const lines = [[0,0,0,0,0],[1,1,1,1,1],[2,2,2,2,2],[0,1,2,1,0],[2,1,0,1,2]];
    safeArray(data.lineWins).forEach(win => {
      const line = lines[Number(win?.line)];
      if (!line) return;
      for (let reel = 0; reel < Math.min(Number(win.count) || 0, line.length); reel += 1) winningCells.add(`${reel}:${line[reel]}`);
    });
  } else if (data.winningLine !== null && data.winningLine !== undefined) {
    reels.forEach((_, reel) => winningCells.add(`${reel}:0`));
  }

  reels.forEach((column, reelIndex) => {
    const reel = setAnimationIndex(element('div', 'pp-reel'), reelIndex);
    safeArray(column).forEach((symbol, rowIndex) => {
      const tile = slotSymbol(symbol, reelIndex * 3 + rowIndex);
      const isWinning = winningCells.has(`${reelIndex}:${rowIndex}`) || (scatterOnlyWin && symbol === 'scatter');
      if (isWinning) {
        tile.classList.add('is-winning');
        tile.setAttribute('aria-label', `${humanize(symbol)}, winning symbol`);
      }
      reel.append(tile);
    });
    windowNode.append(reel);
  });
  if (grid && safeArray(data.lineWins).length > 0) {
    const paths = svgElement('svg', { viewBox: '0 0 500 300', preserveAspectRatio: 'none', class: 'pp-slot-paylines', 'aria-hidden': 'true' });
    const lines = [[0,0,0,0,0],[1,1,1,1,1],[2,2,2,2,2],[0,1,2,1,0],[2,1,0,1,2]];
    safeArray(data.lineWins).forEach((win, index) => {
      const line = lines[Number(win?.line)];
      const count = Math.min(Number(win?.count) || 0, line?.length || 0);
      if (!line || count < 1) return;
      const points = Array.from({ length: count }, (_, reel) => `${50 + reel * 100},${50 + line[reel] * 100}`).join(' ');
      paths.append(svgElement('polyline', { points, class: `pp-slot-payline-path pp-slot-payline-path--${index % 3}` }));
    });
    windowNode.append(paths);
  } else if (!grid && winningCells.size > 0) {
    const payline = element('span', 'pp-slot-payline');
    payline.setAttribute('aria-hidden', 'true');
    windowNode.append(payline);
  }
  const meters = element('div', 'pp-slot-meters');
  if (grid) {
    meters.append(element('span', '', '5 fixed lines'), element('strong', '', `${Number(data.scatters || 0)} scatters`));
  } else {
    meters.append(element('span', '', '1 center line'), element('strong', '', data.winningLine === null ? 'Spin complete' : 'Line winner'));
  }
  cabinet.append(windowNode, meters);
  scene.append(cabinet);
  return scene;
}

function polar(cx, cy, radius, angle) {
  const radians = (angle - 90) * Math.PI / 180;
  return { x: cx + radius * Math.cos(radians), y: cy + radius * Math.sin(radians) };
}

function wheelWedgePath(cx, cy, radius, startAngle, endAngle) {
  const start = polar(cx, cy, radius, endAngle);
  const end = polar(cx, cy, radius, startAngle);
  const largeArc = endAngle - startAngle <= 180 ? 0 : 1;
  return `M ${cx} ${cy} L ${start.x} ${start.y} A ${radius} ${radius} 0 ${largeArc} 0 ${end.x} ${end.y} Z`;
}

function roulettePockets(slug) {
  const european = ['0','32','15','19','4','21','2','25','17','34','6','27','13','36','11','30','8','23','10','5','24','16','33','1','20','14','31','9','22','18','29','7','28','12','35','3','26'];
  const american = ['0','28','9','26','30','11','7','20','32','17','5','22','34','15','3','24','36','13','1','00','27','10','25','29','12','8','19','31','18','6','21','33','16','4','23','35','14','2'];
  return slug === 'american-roulette' ? american : european;
}

function renderWheel(payload, data) {
  const prize = payload.slug === 'prize-wheel';
  const suppliedSegments = safeArray(data.segmentMultipliersBps);
  const segmentCount = Number(data.segments || PRIZE_LABELS.length);
  const labels = prize
    ? (suppliedSegments.length === segmentCount ? suppliedSegments.map(multiplierLabel) : PRIZE_LABELS.slice(0, segmentCount))
    : roulettePockets(payload.slug);
  const hasSelection = prize ? Number.isInteger(Number(data.segment)) : data.pocket !== undefined;
  const selected = prize ? Math.max(0, Math.min(labels.length - 1, Number(data.segment || 0))) : Math.max(0, labels.indexOf(String(data.pocket)));
  const step = 360 / labels.length;
  const finalAngle = -(selected * step + step / 2);
  const scene = element('div', `pp-wheel-scene${prize ? ' pp-wheel-scene--prize' : ''}`);
  const wrap = element('div', 'pp-wheel-wrap');
  wrap.style.setProperty('--pp-wheel-angle', `${finalAngle}deg`);
  const pointer = element('span', 'pp-wheel-pointer');
  pointer.setAttribute('aria-hidden', 'true');
  const svg = svgElement('svg', { viewBox: '0 0 420 420', class: 'pp-wheel', role: 'img', 'aria-label': hasSelection ? `Selected ${labels[selected]}` : 'Wheel ready' });
  const wheelGroup = svgElement('g', { class: 'pp-wheel-disc' });
  labels.forEach((label, index) => {
    const start = index * step;
    const end = start + step;
    let className = 'pp-wheel-wedge';
    if (prize) className += ` pp-wheel-wedge--${index % 4}`;
    else if (label === '0' || label === '00') className += ' pp-wheel-wedge--green';
    else className += ROULETTE_REDS.has(label) ? ' pp-wheel-wedge--red' : ' pp-wheel-wedge--black';
    if (hasSelection && index === selected) className += ' is-selected';
    wheelGroup.append(svgElement('path', { d: wheelWedgePath(210, 210, 193, start, end), class: className }));
    const point = polar(210, 210, prize ? 154 : 166, start + step / 2);
    const textNode = svgElement('text', { x: point.x.toFixed(2), y: point.y.toFixed(2), class: 'pp-wheel-label', 'text-anchor': 'middle', transform: `rotate(${start + step / 2} ${point.x.toFixed(2)} ${point.y.toFixed(2)})` }, label);
    wheelGroup.append(textNode);
  });
  wheelGroup.append(svgElement('circle', { cx: 210, cy: 210, r: prize ? 77 : 112, class: 'pp-wheel-inner' }), svgElement('circle', { cx: 210, cy: 210, r: 38, class: 'pp-wheel-hub' }));
  svg.append(wheelGroup);
  wrap.append(svg, pointer, element('span', 'pp-wheel-pin'));
  const result = element('div', 'pp-wheel-readout');
  result.append(
    element('span', '', hasSelection ? (prize ? 'Winning segment' : 'Winning pocket') : 'Wheel ready'),
    element('strong', '', hasSelection ? (prize && data.label ? data.label.replace('x', '×') : labels[selected]) : '—'),
  );
  if (!prize && data.color) result.append(element('small', '', `${humanize(data.color)} • ${humanize(data.bet || 'bet')}`));
  wrap.append(result);
  scene.append(wrap);
  return scene;
}

function dieNode(value, index = 0) {
  const number = Math.max(1, Math.min(6, Number(value || 1)));
  const die = setAnimationIndex(element('div', 'pp-die'), index);
  die.setAttribute('aria-label', `Die showing ${number}`);
  die.dataset.value = String(number);
  for (let pip = 1; pip <= 9; pip += 1) die.append(element('span', `pp-pip pp-pip--${pip}`));
  return die;
}

function renderDice(payload, data) {
  const scene = element('div', 'pp-dice-scene');
  if (payload.slug === 'over-under-dice') {
    const gauge = element('div', 'pp-d100-gauge');
    const target = Math.max(0, Math.min(100, Number(data.target || 50)));
    const roll = Number(data.roll || 0);
    gauge.style.setProperty('--pp-target', `${target}%`);
    gauge.style.setProperty('--pp-roll', `${Math.max(0, Math.min(100, roll))}%`);
    gauge.append(element('span', 'pp-d100-zone pp-d100-zone--low'), element('span', 'pp-d100-zone pp-d100-zone--high'), element('span', 'pp-d100-target', `${target}`), element('span', 'pp-d100-marker', roll.toFixed(2)));
    const readout = element('div', 'pp-d100-readout');
    readout.append(element('span', '', `${humanize(data.choice)} ${target}`), element('strong', '', roll.toFixed(2)));
    scene.append(gauge, readout);
  } else {
    const tray = element('div', 'pp-dice-tray');
    safeArray(data.dice).forEach((value, index) => tray.append(dieNode(value, index)));
    scene.append(tray);
    const note = element('div', 'pp-dice-total');
    note.append(element('span', '', data.point ? `Point ${data.point}` : humanize(payload.outcome)), element('strong', '', `Total ${Number(data.sum || 0)}`));
    if (data.triple) note.append(element('em', '', 'Triple'));
    scene.append(note);
  }
  return scene;
}

function renderCoin(payload, data) {
  const side = data.side === 'tails' ? 'tails' : 'heads';
  const scene = element('div', 'pp-coin-scene');
  const coin = element('div', `pp-coin pp-coin--${side}`);
  coin.style.setProperty('--pp-coin-final', side === 'heads' ? '1080deg' : '1260deg');
  const heads = element('span', 'pp-coin-face pp-coin-face--heads');
  heads.append(element('span', 'pp-coin-crown', '♛'), element('small', '', 'HEADS'));
  const tails = element('span', 'pp-coin-face pp-coin-face--tails');
  tails.append(element('span', 'pp-coin-onion', '◉'), element('small', '', 'TAILS'));
  coin.append(heads, tails);
  const caption = element('div', 'pp-coin-caption');
  caption.append(element('span', '', `You chose ${humanize(data.guess)}`), element('strong', '', humanize(side)));
  scene.append(coin, caption);
  return scene;
}

function cardGroup(label, cards, startIndex = 0) {
  const group = element('section', 'pp-card-hand');
  group.append(element('h4', '', label));
  const row = element('div', 'pp-card-row');
  cards.forEach((card, index) => row.append(cardNode(card, startIndex + index)));
  group.append(row);
  return group;
}

function renderCards(payload, data) {
  const table = element('div', 'pp-card-table');
  const groups = [];
  const isBaccarat = payload.slug === 'baccarat';
  const preferred = isBaccarat
    ? [['Player', 'playerCards'], ['Banker', 'bankerCards']]
    : [
      ['Dealer', 'dealerCards'], ['Computer', 'botCards'],
      ['Community', 'communityCards'], ['Your cards', 'playerCards'], ['Cards', 'cards'],
      ['High hand', 'playerHigh'], ['Low hand', 'playerLow'], ['Dealer high', 'dealerHigh'], ['Dealer low', 'dealerLow'],
      ['Current card', 'currentCard'], ['Next card', 'nextCard'], ['Player', 'playerCard'], ['Dealer', 'dealerCard'], ['Third card', 'thirdCard'],
    ];
  preferred.forEach(([label, key]) => {
    const cards = cardsFrom(data[key]);
    if (cards.length) groups.push([label, cards]);
  });
  safeArray(data.hands).forEach((hand, index) => {
    const cards = cardsFrom(hand);
    if (cards.length) groups.push([`Hand ${index + 1}`, cards]);
  });

  if (!groups.length) {
    table.append(renderReveal(payload, data));
    return table;
  }

  let cardIndex = 0;
  groups.forEach(([label, cards]) => {
    table.append(cardGroup(label, cards, cardIndex));
    cardIndex += cards.length;
  });
  const metrics = [
    ['playerTotal', isBaccarat ? 'Player total' : 'Your total'], ['dealerTotal', 'Dealer total'], ['bankerTotal', 'Banker total'],
    ['playerHand', 'Your hand'], ['dealerHand', 'Dealer hand'], ['botHand', 'Computer hand'],
    ['hand', 'Hand'], ['spread', 'Spread'], ['streak', 'Streak'], ['unitsRiding', 'Units riding'],
  ].filter(([key]) => data[key] !== undefined && data[key] !== null);
  if (metrics.length) {
    const stats = element('dl', 'pp-table-stats');
    metrics.forEach(([key, label]) => stats.append(element('dt', '', label), element('dd', '', data[key])));
    table.append(stats);
  }
  return table;
}

function pileNode(label, card, count) {
  const pile = element('div', 'pp-solitaire-pile');
  pile.append(element('span', 'pp-pile-label', label));
  if (card) pile.append(cardNode(card, 0, { compact: true }));
  else {
    const empty = element('span', 'pp-empty-pile', count !== undefined ? String(count) : '');
    empty.setAttribute('aria-label', `${label}${count !== undefined ? `, ${count} cards` : ', empty'}`);
    pile.append(empty);
  }
  return pile;
}

function renderSolitaire(payload, data) {
  const scene = element('div', 'pp-solitaire-table');
  const toolbar = element('div', 'pp-solitaire-toolbar');
  toolbar.append(pileNode(`Stock${data.stockCount !== undefined ? ` (${data.stockCount})` : ''}`, data.stockCount ? { hidden: true } : null), pileNode('Waste', data.wasteTop || null));
  if (isObject(data.foundations)) {
    Object.entries(data.foundations).forEach(([suit, count]) => {
      const pile = pileNode(CARD_SUITS[suit] || humanize(suit), null, count);
      pile.dataset.suit = suit;
      toolbar.append(pile);
    });
  }
  safeArray(data.freecells).forEach((card, index) => toolbar.append(pileNode(`Free ${index + 1}`, card)));
  scene.append(toolbar);

  const columns = Array.isArray(data.columns) ? data.columns : Array.isArray(data.tableau) && Array.isArray(data.tableau[0]) ? data.tableau : null;
  if (columns) {
    const tableau = element('div', `pp-solitaire-columns pp-solitaire-columns--${columns.length}`);
    columns.forEach((column, columnIndex) => {
      const stack = element('div', 'pp-solitaire-column');
      safeArray(column).forEach((card, cardIndex) => {
        const node = cardNode(card, columnIndex * 10 + cardIndex, { compact: true });
        node.style.setProperty('--pp-card-row', String(cardIndex));
        stack.append(node);
      });
      tableau.append(stack);
    });
    scene.append(tableau);
  } else if (Array.isArray(data.tableau)) {
    const shape = payload.slug === 'pyramid-solitaire' ? 'pyramid' : 'tripeaks';
    const tableau = element('div', `pp-solitaire-layout pp-solitaire-layout--${shape}`);
    data.tableau.forEach((card, index) => {
      const slot = setAnimationIndex(element('div', 'pp-solitaire-layout-slot'), index);
      slot.dataset.cardIndex = String(index);
      if (shape === 'pyramid') {
        const row = Math.floor((Math.sqrt(8 * index + 1) - 1) / 2);
        const offset = index - row * (row + 1) / 2;
        slot.style.setProperty('--pp-layout-x', `${50 + (offset - row / 2) * 13.5}%`);
        slot.style.setProperty('--pp-layout-y', `${row * 14.2}%`);
      } else {
        const rowStarts = [0, 3, 9, 18, 28];
        const row = rowStarts.findIndex((start, rowIndex) => index >= start && index < rowStarts[rowIndex + 1]);
        const positions = [
          [16.7, 50, 83.3],
          [10, 23.3, 43.3, 56.7, 76.7, 90],
          [5, 16.25, 27.5, 38.75, 50, 61.25, 72.5, 83.75, 95],
          [3, 13.45, 23.9, 34.35, 44.8, 55.2, 65.65, 76.1, 86.55, 97],
        ];
        slot.style.setProperty('--pp-layout-x', `${positions[row]?.[index - rowStarts[row]] ?? 50}%`);
        slot.style.setProperty('--pp-layout-y', `${row * 25.5}%`);
      }
      if (card) slot.append(cardNode(card, index, { compact: true }));
      tableau.append(slot);
    });
    scene.append(tableau);
  }
  if (data.moves !== undefined) scene.append(element('p', 'pp-solitaire-moves', `${Number(data.moves)} moves`));
  return scene;
}

function horseIcon() {
  const svg = svgElement('svg', { viewBox: '0 0 94 54', 'aria-hidden': 'true' });
  svg.append(
    svgElement('path', { d: 'M8 39c11-5 14-15 22-20 8-5 18-3 27 2l8-10 14 2-7 10c8 3 12 9 13 18H62l-10-8-11 7-7 13h-9l5-16-8 4-7 12H6Z', class: 'pp-horse-body' }),
    svgElement('circle', { cx: 70, cy: 17, r: 2, class: 'pp-horse-eye' }),
  );
  return svg;
}

function renderRacing(payload, data) {
  const scene = element('div', 'pp-race-scene');
  const finish = safeArray(data.finish).map(Number);
  const horses = safeArray(data.horses);
  const position = new Map(finish.map((horse, index) => [horse, index]));
  horses.forEach((name, horseIndex) => {
    const place = position.has(horseIndex) ? position.get(horseIndex) : horseIndex;
    const lane = setAnimationIndex(element('div', `pp-race-lane${Number(data.pick) === horseIndex ? ' is-picked' : ''}${Number(data.winner) === horseIndex ? ' is-winner' : ''}`), horseIndex);
    const label = element('span', 'pp-race-name', `${horseIndex + 1}. ${name}`);
    const runner = element('span', 'pp-race-horse');
    const progress = 95 - place * 7;
    runner.style.setProperty('--pp-finish', `${progress}%`);
    runner.append(horseIcon(), element('span', 'pp-jockey-silk', String(horseIndex + 1)));
    lane.append(label, runner, element('span', 'pp-race-place', ordinal(place)));
    scene.append(lane);
  });
  return scene;
}

function cupSvg() {
  const svg = svgElement('svg', { viewBox: '0 0 84 70', 'aria-hidden': 'true' });
  svg.append(svgElement('path', { d: 'M13 13h58l-8 46H21Z', class: 'pp-cup-body' }), svgElement('ellipse', { cx: 42, cy: 13, rx: 29, ry: 9, class: 'pp-cup-rim' }), svgElement('ellipse', { cx: 42, cy: 59, rx: 21, ry: 6, class: 'pp-cup-base' }));
  return svg;
}

function renderCups(payload, data) {
  const scene = element('div', 'pp-cups-scene');
  const order = safeArray(data.shuffleAnimation).length === 3 ? data.shuffleAnimation.map(Number) : [1,2,3];
  [1,2,3].forEach((number, index) => {
    const cup = setAnimationIndex(element('div', `pp-cup${Number(data.winner) === number ? ' is-winner' : ''}${Number(data.guess) === number ? ' is-picked' : ''}`), index);
    cup.style.order = String(Math.max(0, order.indexOf(number)));
    cup.append(cupSvg(), element('span', 'pp-cup-number', String(number)));
    if (Number(data.winner) === number) cup.append(element('span', 'pp-cup-ball', '●'));
    scene.append(cup);
  });
  const label = element('p', 'pp-cups-caption', `The ball was under cup ${Number(data.winner || 0)}.`);
  scene.append(label);
  return scene;
}

function pachinkoPoint(row, column, rows) {
  const gap = 360 / (rows + 1);
  return { x: 210 - row * gap / 2 + column * gap, y: 30 + row * (330 / rows) };
}

function renderPachinko(payload, data) {
  const path = safeArray(data.path).map(Number);
  const rows = path.length || 15;
  const hasResult = path.length > 0 && Number.isInteger(Number(data.slot));
  const svg = svgElement('svg', { viewBox: '0 0 420 460', class: 'pp-pachinko', role: 'img', 'aria-label': hasResult ? `Ball landed in slot ${Number(data.slot) + 1}` : 'Pachinko board ready' });
  const defs = svgElement('defs');
  const gradient = svgElement('linearGradient', { id: 'pp-pachinko-ball-gradient', x1: 0, y1: 0, x2: 1, y2: 1 });
  gradient.append(svgElement('stop', { offset: 0, 'stop-color': '#fff' }), svgElement('stop', { offset: .45, 'stop-color': '#ff62ee' }), svgElement('stop', { offset: 1, 'stop-color': '#7427ff' }));
  defs.append(gradient); svg.append(defs);
  for (let row = 0; row < rows; row += 1) {
    for (let col = 0; col <= row; col += 1) {
      const point = pachinkoPoint(row, col, rows);
      svg.append(svgElement('circle', { cx: point.x, cy: point.y, r: 5, class: 'pp-pachinko-peg' }));
    }
  }
  const points = [{ x: 210, y: 4 }];
  let rights = 0;
  path.forEach((direction, row) => {
    points.push(pachinkoPoint(row, rights, rows));
    if (direction > 0) rights += 1;
  });
  const landingX = 210 - rows * (360 / (rows + 1)) / 2 + Number(data.slot || rights) * (360 / (rows + 1));
  points.push({ x: landingX, y: 424 });
  if (hasResult) {
    const pathPoints = points.map(point => `${point.x},${point.y}`).join(' ');
    const motionPath = points.map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x} ${point.y}`).join(' ');
    const polyline = svgElement('polyline', { points: pathPoints, class: 'pp-pachinko-path', 'aria-hidden': 'true' });
    const ball = svgElement('circle', { cx: 0, cy: 0, r: 12, class: 'pp-pachinko-ball' });
    ball.style.offsetPath = `path("${motionPath}")`;
    ball.style.offsetRotate = '0deg';
    svg.append(polyline, ball);
  }
  const slots = element('div', 'pp-pachinko-slots');
  const multipliers = safeArray(data.multipliersBps);
  for (let index = 0; index <= rows; index += 1) {
    const label = multipliers.length === rows + 1 ? multiplierLabel(multipliers[index]) : String(index + 1);
    const slot = element('span', hasResult && index === Number(data.slot) ? 'is-selected' : '', label);
    slot.setAttribute('aria-label', multipliers.length === rows + 1 ? `Slot ${index + 1}, ${label}` : `Slot ${index + 1}`);
    slots.append(slot);
  }
  const scene = element('div', 'pp-pachinko-scene');
  scene.append(svg, slots);
  return scene;
}

function renderGemGrid(payload, data) {
  const scene = element('div', 'pp-gem-scene');
  const grid = element('div', 'pp-gem-grid');
  const winning = new Set();
  safeArray(data.clusters).forEach(cluster => {
    const end = Number(cluster.endColumn);
    for (let col = end - Number(cluster.length) + 1; col <= end; col += 1) winning.add(`${Number(cluster.row)}:${col}`);
  });
  safeArray(data.grid).forEach((row, rowIndex) => safeArray(row).forEach((gem, colIndex) => {
    const tile = setAnimationIndex(element('div', `pp-gem${winning.has(`${rowIndex}:${colIndex}`) ? ' is-winning' : ''}`), rowIndex * 6 + colIndex);
    tile.append(symbolSvg(gem));
    tile.setAttribute('aria-label', `${humanize(gem)}${winning.has(`${rowIndex}:${colIndex}`) ? ', winning cluster' : ''}`);
    grid.append(tile);
  }));
  scene.append(grid);
  if (safeArray(data.clusters).length) scene.append(element('p', 'pp-gem-caption', `${data.clusters.length} matching cluster${data.clusters.length === 1 ? '' : 's'}`));
  return scene;
}

function renderScratch(payload, data) {
  const scene = element('div', 'pp-scratch-scene');
  const ticket = element('div', 'pp-scratch-ticket');
  ticket.append(element('div', 'pp-scratch-title', 'PURPLE PARLOR • FREE SCRATCH'));
  const grid = element('div', 'pp-scratch-grid');
  safeArray(data.grid).forEach((symbol, index) => {
    const cell = setAnimationIndex(element('div', `pp-scratch-cell${symbol === data.matchedSymbol ? ' is-match' : ''}`), index);
    cell.append(symbolSvg(symbol), element('span', '', humanize(symbol)), element('i', 'pp-scratch-foil'));
    grid.append(cell);
  });
  ticket.append(grid, element('div', 'pp-scratch-footer', `${Number(data.matches || 0)} matching • ${Number(data.practicePoints || 0)} practice points`));
  scene.append(ticket);
  return scene;
}

function renderBoard(payload, data) {
  const treasure = payload.slug === 'treasure-tiles';
  const count = Math.max(0, Number(data.tileCount || (treasure ? 20 : 25)));
  const revealed = safeIndexSet(data.revealed);
  if (data.tile !== undefined) revealed.add(Number(data.tile));
  const hazards = safeIndexSet(treasure ? data.traps : data.mines);
  const treasures = indexedValues(data.treasures);
  const revealedValues = indexedValues(data.revealedValues);
  const scene = element('div', `pp-board-scene pp-board-scene--${treasure ? 'treasure' : 'mines'}`);
  const board = element('div', `pp-tile-board pp-tile-board--${treasure ? 'five-by-four' : 'five-by-five'}`);
  for (let index = 0; index < count; index += 1) {
    const isHazard = hazards.has(index);
    const isRevealed = revealed.has(index) || (payload.complete && hazards.size > 0);
    const actionable = !payload.complete && !isRevealed;
    const cell = setAnimationIndex(element(actionable ? 'button' : 'div', `pp-board-tile${isRevealed ? ' is-revealed' : ''}${isHazard ? ' is-hazard' : ''}`), index);
    if (actionable) {
      cell.type = 'button';
      cell.dataset.gameVisualAction = 'reveal';
      cell.dataset.optionKey = 'tile';
      cell.dataset.optionValue = String(index);
      cell.dataset.optionType = 'integer';
    }
    let content = '';
    if (isRevealed && isHazard) content = treasure ? '☠' : '✹';
    else if (isRevealed && treasure && Number(treasures[index] ?? revealedValues[index] ?? (Number(data.tile) === index ? data.value : 0)) > 0) content = '◈';
    else if (isRevealed) content = treasure ? '·' : '✦';
    else content = treasure ? '∴' : '◇';
    cell.append(element('span', 'pp-board-tile__face', content));
    cell.setAttribute('aria-label', `Tile ${index + 1}, ${isRevealed ? (isHazard ? (treasure ? 'trap' : 'mine') : 'safe') : 'hidden'}`);
    board.append(cell);
  }
  scene.append(board);
  const meter = element('div', 'pp-board-meter');
  if (treasure) meter.append(element('span', '', `${revealed.size} explored`), element('strong', '', `${Number(data.score || 0)} treasure score`));
  else meter.append(element('span', '', `${revealed.size} safe tiles`), element('strong', '', `${Number(data.mineCount || hazards.size || 0)} mines`));
  scene.append(meter);
  return scene;
}

function renderMemory(payload, data) {
  const count = Math.max(0, Number(data.cardCount || 16));
  const matched = safeIndexSet(data.matched);
  const faceUp = indexedValues(data.faceUp);
  const flipped = indexedValues(data.flipped);
  const visible = { ...faceUp, ...flipped };
  const knownSymbols = indexedValues(data.memorySymbols);
  const scene = element('div', 'pp-memory-scene');
  const board = element('div', 'pp-memory-board');
  for (let index = 0; index < count; index += 1) {
    const visibleSymbol = visible[index];
    const symbol = visibleSymbol !== undefined ? visibleSymbol : (matched.has(index) ? knownSymbols[index] : undefined);
    const shown = visibleSymbol !== undefined || matched.has(index);
    const actionable = !payload.complete && !shown;
    const card = setAnimationIndex(element(actionable ? 'button' : 'div', `pp-memory-card${shown ? ' is-face-up' : ''}${matched.has(index) ? ' is-matched' : ''}`), index);
    if (actionable) {
      card.type = 'button';
      card.dataset.gameVisualAction = 'flip';
      card.dataset.optionKey = 'index';
      card.dataset.optionValue = String(index);
      card.dataset.optionType = 'integer';
    }
    const front = element('span', 'pp-memory-face pp-memory-face--front');
    const symbolName = symbol !== undefined ? (MEMORY_SYMBOLS[Number(symbol)] || String(symbol)) : 'star';
    front.append(symbolSvg(symbolName));
    const back = element('span', 'pp-memory-face pp-memory-face--back', '♦');
    card.append(front, back);
    card.setAttribute('aria-label', `Card ${index + 1}, ${shown ? humanize(symbolName) : 'face down'}`);
    board.append(card);
  }
  scene.append(board);
  if (data.moves !== undefined) scene.append(element('p', 'pp-memory-caption', `${Number(data.moves)} moves • ${matched.size / 2} pairs found`));
  return scene;
}

function renderNumbers(payload, data) {
  const picks = new Set(safeArray(data.picks).map(Number));
  const drawn = new Set(safeArray(data.drawn).map(Number));
  const matches = new Set(safeArray(data.matches).map(Number));
  const drawPosition = new Map(safeArray(data.drawOrder).map((number, index) => [Number(number), index]));
  const scene = element('div', 'pp-keno-scene');
  const board = element('div', 'pp-number-board');
  for (let number = 1; number <= 80; number += 1) {
    const selected = picks.has(number);
    const called = drawn.has(number);
    const match = matches.has(number);
    const animationIndex = drawPosition.has(number) ? drawPosition.get(number) : number - 1;
    const ball = setAnimationIndex(element('span', `pp-number-ball${selected ? ' is-picked' : ''}${called ? ' is-drawn' : ''}${match ? ' is-match' : ''}`, number), animationIndex);
    ball.setAttribute('aria-label', `${number}${selected ? ', selected' : ''}${called ? ', drawn' : ''}${match ? ', match' : ''}`);
    board.append(ball);
  }
  scene.append(board, element('p', 'pp-number-caption', `${matches.size} match${matches.size === 1 ? '' : 'es'} from ${picks.size} picks`));
  return scene;
}

function renderBingo(payload, data) {
  const scene = element('div', 'pp-bingo-scene');
  const card = element('div', 'pp-bingo-card');
  ['B','I','N','G','O'].forEach(letter => card.append(element('strong', 'pp-bingo-letter', letter)));
  const matrix = safeArray(data.card);
  const marked = safeArray(data.marked);
  for (let row = 0; row < 5; row += 1) {
    for (let col = 0; col < 5; col += 1) {
      const value = matrix[row]?.[col];
      const isMarked = Boolean(marked[row]?.[col]) || value === 0;
      const cell = setAnimationIndex(element('span', `pp-bingo-cell${isMarked ? ' is-marked' : ''}`, value === 0 ? 'FREE' : value ?? '—'), row * 5 + col);
      cell.setAttribute('aria-label', `${['B','I','N','G','O'][col]} ${value === 0 ? 'free space' : value}${isMarked ? ', marked' : ''}`);
      card.append(cell);
    }
  }
  scene.append(card);
  const calls = element('div', 'pp-bingo-calls');
  safeArray(data.drawn).slice(0, 30).forEach((number, index) => calls.append(setAnimationIndex(element('span', '', number), index)));
  scene.append(calls, element('p', 'pp-bingo-caption', `${Number(data.lines || 0)} completed line${Number(data.lines || 0) === 1 ? '' : 's'}`));
  return scene;
}

function renderNumberReveal(payload, data) {
  const scene = element('div', 'pp-number-reveal-scene');
  const machine = element('div', 'pp-number-machine');
  const value = String(Number(data.number || 0)).padStart(2, '0');
  [...value].forEach((digit, index) => machine.append(setAnimationIndex(element('span', 'pp-number-drum', digit), index)));
  const guess = element('div', 'pp-number-guess');
  const numericGuess = Math.max(0, Math.min(99, Number(data.guess || 0)));
  const bandStart = Math.floor(numericGuess / 10) * 10;
  const guessLabel = data.mode === 'range'
    ? `${String(bandStart).padStart(2, '0')}–${String(bandStart + 9).padStart(2, '0')}`
    : String(numericGuess).padStart(data.mode === 'last_digit' ? 1 : 2, '0');
  guess.append(
    element('span', '', data.mode === 'range' ? 'Selected band' : `${humanize(data.mode)} guess`),
    element('strong', '', guessLabel),
  );
  scene.append(machine, guess);
  return scene;
}

function renderReveal(payload, data) {
  const scene = element('div', 'pp-generic-reveal');
  scene.append(symbolSvg(payload?.complete && Number(payload?.payout || 0) > 0 ? 'crown' : 'moon'));
  const content = element('div');
  content.append(element('span', '', payload?.complete ? 'Server result' : 'Round update'), element('strong', '', humanize(payload?.outcome || 'Ready')));
  const primitive = Object.entries(data || {}).find(([key, value]) => key !== 'payoutMultiplierBps' && ['string','number'].includes(typeof value));
  if (primitive) content.append(element('small', '', `${humanize(primitive[0])}: ${primitive[1]}`));
  scene.append(content);
  return scene;
}

function renderAttract(payload, archetype) {
  if (archetype === 'pachinko') return renderPachinko(payload, {});

  const scene = element('div', `pp-attract-scene pp-attract-scene--${archetype}`);
  const art = element('div', 'pp-attract-art');
  art.setAttribute('aria-hidden', 'true');

  if (archetype === 'slots') {
    ['onion', 'crown', 'book'].forEach((symbol, index) => art.append(slotSymbol(symbol, index)));
  } else if (archetype === 'wheel') {
    art.append(element('span', 'pp-attract-wheel'), element('i', 'pp-attract-pointer'));
  } else if (archetype === 'dice') {
    art.append(dieNode(2), dieNode(5), ...(payload.slug === 'sic-bo' ? [dieNode(6, 2)] : []));
  } else if (archetype === 'coin') {
    const coin = element('div', 'pp-coin pp-coin--heads');
    const heads = element('span', 'pp-coin-face pp-coin-face--heads');
    heads.append(element('span', 'pp-coin-crown', '♛'), element('small', '', 'HEADS'));
    const tails = element('span', 'pp-coin-face pp-coin-face--tails');
    tails.append(element('span', 'pp-coin-onion', '◉'), element('small', '', 'TAILS'));
    coin.append(heads, tails); art.append(coin);
  } else if (['cards', 'solitaire'].includes(archetype)) {
    for (let index = 0; index < (archetype === 'solitaire' ? 7 : 5); index += 1) art.append(cardNode({ hidden: true }, index, { compact: archetype === 'solitaire' }));
  } else if (archetype === 'racing') {
    for (let index = 0; index < 3; index += 1) {
      const horse = element('span', 'pp-attract-horse'); horse.append(horseIcon()); art.append(horse);
    }
  } else if (archetype === 'cups') {
    for (let index = 0; index < 3; index += 1) {
      const cup = element('span', 'pp-attract-cup'); cup.append(cupSvg()); art.append(cup);
    }
  } else if (archetype === 'gem-grid') {
    ['amethyst','ruby','topaz','emerald','pearl','amethyst','topaz','ruby','emerald','pearl','amethyst','topaz'].forEach(symbol => art.append(symbolSvg(symbol)));
  } else if (archetype === 'scratch') {
    for (let index = 0; index < 9; index += 1) art.append(element('span', 'pp-attract-scratch-cell', '?'));
  } else if (archetype === 'board') {
    for (let index = 0; index < (payload.slug === 'treasure-tiles' ? 20 : 25); index += 1) art.append(element('span', 'pp-attract-tile', payload.slug === 'treasure-tiles' ? '∴' : '◇'));
  } else if (archetype === 'memory') {
    for (let index = 0; index < 16; index += 1) art.append(element('span', 'pp-attract-memory-card', '♦'));
  } else if (archetype === 'numbers') {
    for (let number = 1; number <= 20; number += 1) art.append(element('span', 'pp-number-ball', number));
  } else if (archetype === 'bingo') {
    ['B','I','N','G','O'].forEach(letter => art.append(element('strong', 'pp-bingo-letter', letter)));
  } else if (archetype === 'number-reveal') {
    art.append(element('span', 'pp-number-drum', '?'), element('span', 'pp-number-drum', '?'));
  } else {
    art.append(symbolSvg('crown'));
  }

  const copy = element('div', 'pp-attract-copy');
  copy.append(element('span', '', 'Illustrated table ready'), element('strong', '', 'Start a round to animate the server result'));
  scene.append(art, copy);
  return scene;
}

const RENDERERS = Object.freeze({
  slots: renderSlots,
  wheel: renderWheel,
  dice: renderDice,
  coin: renderCoin,
  cards: renderCards,
  solitaire: renderSolitaire,
  racing: renderRacing,
  cups: renderCups,
  pachinko: renderPachinko,
  'gem-grid': renderGemGrid,
  scratch: renderScratch,
  board: renderBoard,
  memory: renderMemory,
  numbers: renderNumbers,
  bingo: renderBingo,
  'number-reveal': renderNumberReveal,
  reveal: renderReveal,
});

export function getGameVisualArchetype(slug) {
  return GAME_VISUAL_ARCHETYPES[String(slug || '')] || (slug === 'plinko' ? null : 'reveal');
}

export function supportsGameVisual(slug) {
  return getGameVisualArchetype(slug) !== null;
}

export class GameVisualRenderer {
  constructor(root, { slug = '', reducedMotion } = {}) {
    if (!(root instanceof Element)) throw new TypeError('GameVisualRenderer requires a DOM Element root.');
    this.root = root;
    this.slug = slug || root.dataset.gameSlug || root.closest('[data-game-slug]')?.dataset.gameSlug || '';
    this.reducedMotion = reducedMotion ?? (matchMedia('(prefers-reduced-motion: reduce)').matches
      || document.documentElement.dataset.prefReducedMotion === 'true'
      || document.documentElement.dataset.prefAnimations === 'false');
    this.animationFrame = 0;
  }

  render(payload, { slug = this.slug || payload?.slug || '', reducedMotion = this.reducedMotion } = {}) {
    const archetype = getGameVisualArchetype(slug);
    if (!archetype) return false;
    const normalized = isObject(payload) ? { ...payload, slug } : { slug, outcome: 'round_update', result: {} };
    const figure = element('figure', `pp-game-visual pp-game-visual--${archetype}`);
    figure.dataset.gameVisual = archetype;
    figure.dataset.outcome = String(normalized.outcome || '');
    figure.setAttribute('role', 'group');
    figure.setAttribute('aria-label', `${ARCHETYPE_LABELS[archetype] || 'Game result'}: ${humanize(normalized.outcome || 'round update')}`);
    figure.append(visualHeader(normalized, archetype));
    const stage = element('div', 'pp-visual-stage');
    const render = RENDERERS[archetype] || RENDERERS.reveal;
    try {
      stage.append(normalized.outcome === 'ready' ? renderAttract(normalized, archetype) : render(normalized, dataFor(normalized)));
    } catch {
      stage.replaceChildren(renderReveal(normalized, dataFor(normalized)));
    }
    figure.append(stage);
    if (reducedMotion) figure.classList.add('is-reduced-motion');
    this.root.replaceChildren(figure);
    cancelAnimationFrame(this.animationFrame);
    if (!reducedMotion) {
      this.animationFrame = requestAnimationFrame(() => figure.isConnected && figure.classList.add('is-animated'));
    }
    this.slug = slug;
    this.reducedMotion = reducedMotion;
    return true;
  }

  clear() {
    cancelAnimationFrame(this.animationFrame);
    this.root.replaceChildren();
  }

  destroy() {
    this.clear();
  }
}

export function renderGameVisual(root, payload, options = {}) {
  const renderer = new GameVisualRenderer(root, options);
  renderer.render(payload, options);
  return renderer;
}
