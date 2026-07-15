import { GameVisualRenderer } from './game-visuals.js';
import { PlinkoBoard } from './plinko-board.js';

const CARD_SUITS = Object.freeze({ clubs: '♣', diamonds: '♦', hearts: '♥', spades: '♠' });

function element(tag, className = '', text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = String(text);
  return node;
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function indexedValues(value) {
  if (Array.isArray(value)) {
    return Object.fromEntries(value.flatMap((item, index) => item === undefined || item === null ? [] : [[index, item]]));
  }
  return isObject(value) ? value : {};
}

function isCard(value) {
  return isObject(value) && typeof value.rank === 'number' && typeof value.suit === 'string';
}

function humanize(value) {
  return String(value ?? '')
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .replace(/[_-]+/g, ' ')
    .replace(/^./, letter => letter.toUpperCase());
}

function renderCard(card) {
  const node = element('span', `game-card-mini${card?.hidden ? ' is-hidden' : ''}`);
  if (card?.hidden) {
    node.textContent = 'Face down';
    node.setAttribute('aria-label', 'Face-down card');
    return node;
  }
  const rank = ({ 11: 'J', 12: 'Q', 13: 'K', 14: 'A' })[card.rank] || card.rank;
  node.textContent = `${rank}${CARD_SUITS[card.suit] || ''}`;
  node.dataset.suit = card.suit;
  node.setAttribute('aria-label', card.label || card.code || `${rank} of ${card.suit}`);
  return node;
}

function renderValue(value, key = '') {
  if (isCard(value) || value?.hidden) return renderCard(value);
  if (Array.isArray(value)) {
    const list = element('ol', `game-value-list game-value-${key}`);
    value.forEach(item => {
      const row = element('li', 'game-value-item');
      row.append(renderValue(item, key));
      list.append(row);
    });
    return list;
  }
  if (isObject(value)) {
    const group = element('dl', `game-value-group game-value-${key}`);
    Object.entries(value).forEach(([childKey, childValue]) => {
      group.append(element('dt', '', humanize(childKey)));
      const description = element('dd');
      description.append(renderValue(childValue, childKey));
      group.append(description);
    });
    return group;
  }
  if (typeof value === 'boolean') return element('span', '', value ? 'Yes' : 'No');
  if (value === null || value === undefined || value === '') return element('span', 'game-value-empty', '—');
  return element('span', '', value);
}

function summaryFor(payload) {
  const outcome = humanize(payload?.outcome || 'Round update');
  if (!payload?.complete) return `Round update: ${outcome}. Choose the next action.`;
  return `Server result: ${outcome}. Fictional payout: ${Number(payload.payout || 0).toLocaleString()} Cozy Coins.`;
}

/**
 * Turns an authoritative API payload into a visual scene and a compact text
 * disclosure. It retains only the API's safe public state between actions so a
 * completed board can settle visually after the server clears its state field.
 */
export class OutcomeRenderer {
  constructor(root, {
    slug = '',
    gameName = '',
    announcer = null,
    skipButton = null,
    reducedMotion = false,
  } = {}) {
    if (!(root instanceof Element)) throw new TypeError('OutcomeRenderer requires a DOM Element root.');
    this.root = root;
    this.slug = slug || root.closest('[data-game-slug]')?.dataset.gameSlug || '';
    this.gameName = gameName || root.closest('[data-game-name]')?.dataset.gameName || humanize(this.slug);
    this.announcer = announcer;
    this.skipButton = skipButton;
    this.reducedMotion = reducedMotion;
    this.publicState = {};
    this.memorySymbols = {};
    this.revealedValues = {};
    this.activeRoundId = null;
    this.motionTimer = 0;
    this.motionResolver = null;
    this.visual = null;
    this.plinko = this.slug === 'plinko'
      ? new PlinkoBoard(root, { reducedMotion })
      : null;

    this.skipButton?.addEventListener('click', () => this.skip());
    if (!this.plinko) this.idle();
  }

  idle() {
    if (this.plinko) {
      this.plinko.renderIdle();
      return;
    }
    const visualHost = element('div', 'game-visual-host');
    this.root.replaceChildren(visualHost);
    this.visual?.destroy();
    this.visual = new GameVisualRenderer(visualHost, { slug: this.slug, reducedMotion: true });
    this.visual.render({
      slug: this.slug,
      outcome: 'ready',
      complete: false,
      payout: 0,
      display: {},
      state: {},
    }, { reducedMotion: true });
    const intro = element('div', 'game-idle-caption');
    intro.append(
      element('strong', '', this.gameName),
      element('span', '', 'Choose your options, then start a server-authoritative round.'),
    );
    this.root.append(intro);
  }

  configure(options = {}) {
    if (!this.plinko) return;
    const rows = Number.parseInt(options.rows, 10);
    const risk = ['low', 'medium', 'high'].includes(options.risk) ? options.risk : undefined;
    this.plinko.renderIdle({
      ...(Number.isInteger(rows) ? { rows } : {}),
      ...(risk ? { risk } : {}),
    });
  }

  async render(payload, { reducedMotion = this.reducedMotion } = {}) {
    this.skip();
    const visualPayload = this.#withRetainedState(payload);
    this.root.dataset.outcome = String(payload?.outcome || '');
    this.reducedMotion = reducedMotion;
    if (this.skipButton) this.skipButton.hidden = Boolean(reducedMotion);

    if (this.plinko) {
      this.plinko.reducedMotion = reducedMotion;
      await this.plinko.renderOutcome(visualPayload, { reducedMotion });
    } else {
      const visualHost = element('div', 'game-visual-host');
      const details = this.#details(visualPayload);
      this.root.replaceChildren(visualHost, details);
      this.visual?.destroy();
      this.visual = new GameVisualRenderer(visualHost, { slug: this.slug, reducedMotion });
      this.visual.render(visualPayload, { slug: this.slug, reducedMotion });
      await this.#waitForMotion(reducedMotion ? 0 : 3600);
    }

    if (this.announcer) this.announcer.textContent = summaryFor(payload);
    if (this.skipButton) this.skipButton.hidden = true;
    if (payload?.complete) {
      this.publicState = {};
      this.memorySymbols = {};
      this.revealedValues = {};
      this.activeRoundId = null;
    }
    return true;
  }

  skip() {
    this.plinko?.skip?.();
    const activeFigure = this.root.querySelector('.pp-game-visual');
    activeFigure?.classList.add('is-reduced-motion', 'is-animated');
    window.clearTimeout(this.motionTimer);
    this.motionTimer = 0;
    if (this.motionResolver) {
      const resolve = this.motionResolver;
      this.motionResolver = null;
      resolve();
    }
    if (this.skipButton) this.skipButton.hidden = true;
  }

  error(message) {
    this.skip();
    const notice = element('div', 'game-error');
    notice.setAttribute('role', 'alert');
    notice.append(element('strong', '', 'The table paused safely'), element('p', '', message));
    this.root.replaceChildren(notice);
    if (this.announcer) this.announcer.textContent = message;
  }

  destroy() {
    this.skip();
    this.visual?.destroy();
    this.plinko?.destroy();
  }

  #withRetainedState(payload) {
    const incomingRoundId = payload?.roundId ?? null;
    const incomingState = isObject(payload?.state) ? payload.state : {};
    if (this.activeRoundId !== null && incomingRoundId !== null && incomingRoundId !== this.activeRoundId) {
      this.publicState = {};
      this.memorySymbols = {};
      this.revealedValues = {};
    }
    if (Object.keys(incomingState).length > 0) this.publicState = incomingState;
    if (incomingRoundId !== null) this.activeRoundId = incomingRoundId;
    const display = isObject(payload?.display) ? payload.display : (isObject(payload?.result) ? payload.result : {});
    let retainedState = Object.keys(incomingState).length > 0 ? incomingState : this.publicState;

    if (this.slug === 'memory-match') {
      Object.assign(this.memorySymbols, indexedValues(incomingState.faceUp), indexedValues(display.flipped));
      if (display.index !== undefined && display.symbol !== undefined) this.memorySymbols[display.index] = display.symbol;
      retainedState = { ...retainedState, memorySymbols: { ...this.memorySymbols } };
    }

    if (this.slug === 'treasure-tiles') {
      if (display.tile !== undefined && display.value !== undefined) this.revealedValues[display.tile] = display.value;
      Object.assign(this.revealedValues, indexedValues(display.treasures));
      retainedState = { ...retainedState, revealedValues: { ...this.revealedValues } };
    }

    return { ...payload, slug: this.slug, state: retainedState };
  }

  #details(payload) {
    const details = element('details', 'game-result-disclosure');
    details.append(element('summary', '', 'Verified result details'));
    const summary = element('p', 'game-outcome-summary', summaryFor(payload));
    details.append(summary);
    const display = isObject(payload?.display) ? payload.display : (isObject(payload?.result) ? payload.result : {});
    const fields = Object.entries(display).filter(([key]) => key !== 'payoutMultiplierBps');
    if (fields.length > 0) {
      const values = element('div', 'game-outcome-details');
      fields.forEach(([key, value]) => {
        const section = element('section', `game-display game-display-${key}`);
        section.append(element('h4', '', humanize(key)), renderValue(value, key));
        values.append(section);
      });
      details.append(values);
    }
    return details;
  }

  async #waitForMotion(maximumMilliseconds) {
    if (maximumMilliseconds <= 0) return;
    // Background tabs may suspend requestAnimationFrame indefinitely. Give the
    // browser two frames to register CSS animations, but never let that visual
    // bookkeeping hold an already-settled server result hostage.
    await new Promise(resolve => {
      let settled = false;
      const finish = () => {
        if (settled) return;
        settled = true;
        window.clearTimeout(fallback);
        resolve();
      };
      const fallback = window.setTimeout(finish, 100);
      requestAnimationFrame(() => requestAnimationFrame(finish));
    });
    const animations = typeof this.root.getAnimations === 'function'
      ? this.root.getAnimations({ subtree: true })
      : [];
    if (animations.length === 0) return;
    await new Promise(resolve => {
      let settled = false;
      const finish = () => {
        if (settled) return;
        settled = true;
        window.clearTimeout(this.motionTimer);
        this.motionTimer = 0;
        if (this.motionResolver === finish) this.motionResolver = null;
        resolve();
      };
      this.motionResolver = finish;
      this.motionTimer = window.setTimeout(finish, maximumMilliseconds);
      Promise.allSettled(animations.map(animation => animation.finished)).then(finish);
    });
  }
}
