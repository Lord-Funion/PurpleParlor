const SVG_NS = 'http://www.w3.org/2000/svg';
const MIN_ROWS = 8;
const MAX_ROWS = 16;
const RISKS = new Set(['low', 'medium', 'high']);
const VIEWBOX = Object.freeze({ width: 1000, height: 720 });
let instanceSequence = 0;

function clamp(value, minimum, maximum) {
  return Math.min(maximum, Math.max(minimum, value));
}

function htmlElement(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = String(text);
  return node;
}

function svgElement(tag, attributes = {}) {
  const node = document.createElementNS(SVG_NS, tag);
  Object.entries(attributes).forEach(([name, value]) => node.setAttribute(name, String(value)));
  return node;
}

function normalizedRows(value, fallback = 12) {
  const rows = Number.parseInt(value, 10);
  return Number.isInteger(rows) && rows >= MIN_ROWS && rows <= MAX_ROWS ? rows : fallback;
}

function normalizedRisk(value, fallback = 'medium') {
  return RISKS.has(value) ? value : fallback;
}

function resultObject(payload) {
  if (payload?.result && typeof payload.result === 'object' && !Array.isArray(payload.result)) return payload.result;
  if (payload?.display && typeof payload.display === 'object' && !Array.isArray(payload.display)) return payload.display;
  return null;
}

function displayObject(payload) {
  return payload?.display && typeof payload.display === 'object' && !Array.isArray(payload.display)
    ? payload.display
    : null;
}

/**
 * A strict shape check for the server-returned Plinko result. This verifies
 * internal consistency only; it never creates, changes, or predicts a result.
 */
export function isPlinkoPayload(payload) {
  const result = resultObject(payload);
  if (!result || (payload.slug && payload.slug !== 'plinko') || payload.complete !== true) return false;

  const rows = Number(result.rows);
  const bin = Number(result.bin);
  const path = result.path;
  if (!Number.isInteger(rows) || rows < MIN_ROWS || rows > MAX_ROWS) return false;
  if (!Number.isInteger(bin) || bin < 0 || bin > rows) return false;
  if (!Array.isArray(path) || path.length !== rows || path.some(step => step !== 'L' && step !== 'R')) return false;

  return path.filter(step => step === 'R').length === bin;
}

function binomialCoefficient(total, chosen) {
  let k = Math.min(chosen, total - chosen);
  let value = 1;
  for (let index = 1; index <= k; index += 1) value = (value * (total - k + index)) / index;
  return value;
}

function rawPresentationMultiplier(distance, risk) {
  if (risk === 'low') return 0.55 + 2.45 * (distance ** 2.4);
  if (risk === 'high') return 0.08 + 14.92 * (distance ** 4.2);
  return 0.3 + 5.7 * (distance ** 3.1);
}

/**
 * Builds labels for the currently disclosed paytable. These values are visual
 * labels only. The selected bin, recorded payout, and winning multiplier are
 * always read from the server payload in renderOutcome().
 */
function presentationMultipliers(rows, risk) {
  let expectedRaw = 0;
  for (let bin = 0; bin <= rows; bin += 1) {
    const distance = Math.abs(bin - (rows / 2)) / (rows / 2);
    expectedRaw += (binomialCoefficient(rows, bin) / (2 ** rows)) * rawPresentationMultiplier(distance, risk);
  }

  return Array.from({ length: rows + 1 }, (_, bin) => {
    const distance = Math.abs(bin - (rows / 2)) / (rows / 2);
    return Math.max(0, Math.round(rawPresentationMultiplier(distance, risk) * (0.94 / expectedRaw) * 10000));
  });
}

function formatMultiplier(basisPoints) {
  const value = Number(basisPoints) / 10000;
  if (!Number.isFinite(value)) return '—';
  return `${value.toLocaleString(undefined, { minimumFractionDigits: value < 1 ? 2 : 1, maximumFractionDigits: 2 })}×`;
}

function formatCoins(value) {
  const amount = Number(value);
  return Number.isFinite(amount) ? Math.max(0, amount).toLocaleString() : '0';
}

function riskLabel(risk) {
  return `${risk.slice(0, 1).toUpperCase()}${risk.slice(1)} risk`;
}

function boardGeometry(rows) {
  const horizontalStep = 840 / rows;
  const top = 94;
  const bottom = 526;
  const verticalStep = (bottom - top) / (rows - 1);
  const peg = (row, column) => ({
    x: 500 + ((column - (row / 2)) * horizontalStep),
    y: top + (row * verticalStep),
  });
  const bin = column => ({ x: 80 + (column * horizontalStep), y: 632 });
  const firstBinCenter = bin(0).x / VIEWBOX.width;
  const halfBin = (horizontalStep / 2) / VIEWBOX.width;

  return {
    horizontalStep,
    verticalStep,
    peg,
    bin,
    start: { x: 500, y: 34 },
    binEdgePercent: (firstBinCenter - halfBin) * 100,
  };
}

function setSvgPosition(node, point) {
  node.setAttribute('transform', `translate(${point.x.toFixed(2)} ${point.y.toFixed(2)})`);
}

/**
 * Production Plinko presentation driven exclusively by a completed server
 * payload. renderOutcome() resolves after the visual drop is finished so the
 * owning game controller can keep controls locked for the entire reveal.
 */
export class PlinkoBoard {
  constructor(outcomeRoot, options = {}) {
    if (!(outcomeRoot instanceof Element)) throw new TypeError('PlinkoBoard requires an outcome root element.');

    this.root = outcomeRoot;
    this.options = options;
    this.instanceId = `pp-plinko-${++instanceSequence}`;
    this.animationToken = 0;
    this.animationFrame = 0;
    this.segmentResolve = null;
    this.activeModel = null;
    this.activeLandedMultiplier = null;
    this.destroyed = false;
    this.reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    this.reducedMotion = options.reducedMotion ?? (this.reducedMotionQuery.matches
      || document.documentElement.dataset.prefReducedMotion === 'true'
      || document.documentElement.dataset.prefAnimations === 'false');
    this.onMotionPreference = event => {
      this.reducedMotion = event.matches
        || document.documentElement.dataset.prefReducedMotion === 'true'
        || document.documentElement.dataset.prefAnimations === 'false';
      this.#applyMotionPreference();
    };
    this.onAppPreference = event => {
      this.reducedMotion = this.reducedMotionQuery.matches
        || event.detail?.reducedMotion === true
        || event.detail?.animations === false
        || document.documentElement.dataset.prefReducedMotion === 'true'
        || document.documentElement.dataset.prefAnimations === 'false';
      this.#applyMotionPreference();
    };
    this.reducedMotionQuery.addEventListener?.('change', this.onMotionPreference);
    window.addEventListener?.('parlor:preferences', this.onAppPreference);

    this.renderIdle({ rows: options.rows, risk: options.risk });
  }

  renderIdle({ rows = 12, risk = 'medium' } = {}) {
    if (this.destroyed) return false;
    this.#cancelAnimation();
    const model = {
      rows: normalizedRows(rows),
      risk: normalizedRisk(risk),
      bin: null,
      path: [],
      winningBasisPoints: null,
      multiplierBasisPoints: null,
      payout: null,
    };
    this.#build(model);
    this.#setResult('Board ready', 'Choose rows and risk, then start a server-authoritative drop.');
    this.liveStatus.textContent = `Plinko board ready with ${model.rows} rows and ${riskLabel(model.risk).toLowerCase()}.`;
    return true;
  }

  async renderOutcome(payload) {
    if (this.destroyed) return false;
    if (!isPlinkoPayload(payload)) {
      this.#renderUnavailable();
      return false;
    }

    const result = resultObject(payload);
    const display = displayObject(payload);
    const multiplierStrip = Array.isArray(display?.multipliersBps)
      && display.multipliersBps.length === Number(result.rows) + 1
      && display.multipliersBps.every(value => Number.isFinite(Number(value)) && Number(value) >= 0)
      ? display.multipliersBps.map(Number)
      : null;
    const model = {
      rows: Number(result.rows),
      risk: normalizedRisk(result.risk),
      bin: Number(result.bin),
      path: [...result.path],
      winningBasisPoints: Number(display?.payoutMultiplierBps ?? result.payoutMultiplierBps),
      multiplierBasisPoints: multiplierStrip,
      payout: Number(payload.payout),
    };
    this.#cancelAnimation();
    this.#build(model);

    const landedMultiplier = Number.isFinite(model.winningBasisPoints)
      ? formatMultiplier(model.winningBasisPoints)
      : 'the server-recorded multiplier';
    this.#setResult('Server path received', `Following ${model.rows} verified bounces…`);
    this.liveStatus.textContent = `Server result received. Animating ${model.rows} supplied left and right bounces.`;
    this.activeModel = model;
    this.activeLandedMultiplier = landedMultiplier;

    if (this.reducedMotion) {
      this.#placeAtLanding(model, landedMultiplier);
      return true;
    }

    const completed = await this.#animateDrop(model);
    if (completed === 'skipped') return true;
    if (completed) this.#placeAtLanding(model, landedMultiplier);
    return completed === true;
  }

  /** Immediately completes an active reveal without changing its server result. */
  skip() {
    if (this.destroyed || !this.activeModel || !this.board?.classList.contains('is-dropping')) return false;
    const model = this.activeModel;
    const landedMultiplier = this.activeLandedMultiplier;
    if (this.animationFrame) cancelAnimationFrame(this.animationFrame);
    this.animationFrame = 0;
    const resolveSegment = this.segmentResolve;
    this.segmentResolve = null;
    this.#placeAtLanding(model, landedMultiplier);
    resolveSegment?.('skipped');
    return true;
  }

  get isAnimating() {
    return Boolean(this.activeModel && this.board?.classList.contains('is-dropping'));
  }

  destroy() {
    if (this.destroyed) return;
    this.destroyed = true;
    this.#cancelAnimation();
    this.reducedMotionQuery.removeEventListener?.('change', this.onMotionPreference);
    window.removeEventListener?.('parlor:preferences', this.onAppPreference);
    this.root.classList.remove('pp-plinko-host');
  }

  #cancelAnimation() {
    this.animationToken += 1;
    if (this.animationFrame) cancelAnimationFrame(this.animationFrame);
    this.animationFrame = 0;
    const resolveSegment = this.segmentResolve;
    this.segmentResolve = null;
    resolveSegment?.(false);
    this.activeModel = null;
    this.activeLandedMultiplier = null;
  }

  #renderUnavailable() {
    this.#cancelAnimation();
    this.root.classList.add('pp-plinko-host');
    const notice = htmlElement('div', 'pp-plinko__unavailable');
    notice.setAttribute('role', 'status');
    notice.append(
      htmlElement('strong', '', 'The server result could not be visualized.'),
      htmlElement('span', '', 'No client-side result was substituted. Review the recorded round before retrying.'),
    );
    this.root.replaceChildren(notice);
  }

  #build(model) {
    this.model = model;
    this.geometry = boardGeometry(model.rows);
    this.pegsByKey = new Map();
    this.binElements = [];
    this.root.classList.add('pp-plinko-host');
    this.root.replaceChildren();

    const board = htmlElement('section', 'pp-plinko');
    board.dataset.risk = model.risk;
    board.dataset.reducedMotion = this.reducedMotion ? 'true' : 'false';
    board.classList.toggle('is-reduced-motion', this.reducedMotion);
    board.setAttribute('role', 'group');
    board.setAttribute('aria-label', `Parlor Plinko, ${model.rows} rows, ${riskLabel(model.risk)}`);

    const hud = htmlElement('header', 'pp-plinko__hud');
    const titleGroup = htmlElement('div', 'pp-plinko__title-group');
    titleGroup.append(
      htmlElement('span', 'pp-plinko__eyebrow', 'PARLOR PLINKO'),
      htmlElement('h2', 'pp-plinko__title', 'Neon Drop'),
    );
    const verification = htmlElement('div', 'pp-plinko__verification');
    verification.append(htmlElement('span', 'pp-plinko__verification-dot'));
    verification.append(document.createTextNode(' SERVER PATH'));
    const settings = htmlElement('div', 'pp-plinko__settings', `${model.rows} ROWS · ${model.risk.toUpperCase()}`);
    const hudRight = htmlElement('div', 'pp-plinko__hud-right');
    hudRight.append(verification, settings);
    hud.append(titleGroup, hudRight);

    const viewport = htmlElement('div', 'pp-plinko__viewport');
    viewport.style.setProperty('--pp-bin-edge', `${this.geometry.binEdgePercent.toFixed(3)}%`);
    const aura = htmlElement('div', 'pp-plinko__aura');
    const scan = htmlElement('div', 'pp-plinko__scan');
    const svg = svgElement('svg', {
      class: 'pp-plinko__svg',
      viewBox: `0 0 ${VIEWBOX.width} ${VIEWBOX.height}`,
      preserveAspectRatio: 'xMidYMid meet',
      'aria-hidden': 'true',
      focusable: 'false',
    });
    this.#drawSvg(svg, model);

    this.effects = htmlElement('div', 'pp-plinko__effects');
    this.effects.setAttribute('aria-hidden', 'true');
    const bins = this.#buildBins(model);
    viewport.append(aura, scan, svg, this.effects, bins);

    const result = htmlElement('footer', 'pp-plinko__result');
    const resultIcon = htmlElement('span', 'pp-plinko__result-icon', '◇');
    resultIcon.setAttribute('aria-hidden', 'true');
    const resultCopy = htmlElement('div', 'pp-plinko__result-copy');
    this.resultTitle = htmlElement('strong', 'pp-plinko__result-title');
    this.resultDetail = htmlElement('span', 'pp-plinko__result-detail');
    resultCopy.append(this.resultTitle, this.resultDetail);
    this.resultValue = htmlElement('output', 'pp-plinko__result-value', '—');
    this.resultValue.setAttribute('aria-label', 'Fictional payout');
    result.append(resultIcon, resultCopy, this.resultValue);

    this.liveStatus = htmlElement('p', 'pp-plinko__sr-status');
    // The owning OutcomeRenderer provides one concise external live region.
    // Keep this text available for standalone integrators without announcing it twice.
    this.liveStatus.setAttribute('aria-hidden', 'true');
    board.append(hud, viewport, result, this.liveStatus);
    this.root.append(board);
    this.board = board;
  }

  #applyMotionPreference() {
    if (!this.board) return;
    this.board.dataset.reducedMotion = this.reducedMotion ? 'true' : 'false';
    this.board.classList.toggle('is-reduced-motion', this.reducedMotion);
    if (this.reducedMotion && this.activeModel && this.board.classList.contains('is-dropping')) this.skip();
  }

  #drawSvg(svg, model) {
    const defs = svgElement('defs');
    const pegGlow = svgElement('filter', { id: `${this.instanceId}-peg-glow`, x: '-180%', y: '-180%', width: '460%', height: '460%' });
    pegGlow.append(svgElement('feGaussianBlur', { stdDeviation: '5', result: 'blur' }));
    const merge = svgElement('feMerge');
    merge.append(svgElement('feMergeNode', { in: 'blur' }), svgElement('feMergeNode', { in: 'SourceGraphic' }));
    pegGlow.append(merge);
    const chipGlow = svgElement('filter', { id: `${this.instanceId}-chip-glow`, x: '-150%', y: '-150%', width: '400%', height: '400%' });
    chipGlow.append(svgElement('feGaussianBlur', { in: 'SourceGraphic', stdDeviation: '8', result: 'blur' }));
    const chipMerge = svgElement('feMerge');
    chipMerge.append(svgElement('feMergeNode', { in: 'blur' }), svgElement('feMergeNode', { in: 'SourceGraphic' }));
    chipGlow.append(chipMerge);
    defs.append(pegGlow, chipGlow);

    const decor = svgElement('g', { class: 'pp-plinko__decor' });
    decor.append(
      svgElement('path', { d: 'M -80 270 Q 500 -150 1080 270', class: 'pp-plinko__arch pp-plinko__arch--outer' }),
      svgElement('path', { d: 'M -100 540 Q 500 140 1100 540', class: 'pp-plinko__arch pp-plinko__arch--inner' }),
      svgElement('path', { d: 'M 75 604 Q 500 522 925 604', class: 'pp-plinko__landing-glow' }),
      svgElement('circle', { cx: '160', cy: '155', r: '2.4', class: 'pp-plinko__star' }),
      svgElement('circle', { cx: '832', cy: '208', r: '1.8', class: 'pp-plinko__star pp-plinko__star--late' }),
      svgElement('circle', { cx: '730', cy: '92', r: '1.4', class: 'pp-plinko__star' }),
    );

    const pegs = svgElement('g', { class: 'pp-plinko__pegs', filter: `url(#${this.instanceId}-peg-glow)` });
    for (let row = 0; row < model.rows; row += 1) {
      for (let column = 0; column <= row; column += 1) {
        const point = this.geometry.peg(row, column);
        const peg = svgElement('circle', {
          class: 'pp-plinko__peg',
          cx: point.x.toFixed(2),
          cy: point.y.toFixed(2),
          r: row < 2 ? '8.6' : '7.4',
        });
        pegs.append(peg);
        this.pegsByKey.set(`${row}:${column}`, peg);
      }
    }

    this.trail = svgElement('path', { class: 'pp-plinko__trail', d: '' });
    const chip = svgElement('g', { class: 'pp-plinko__chip', filter: `url(#${this.instanceId}-chip-glow)` });
    chip.append(
      svgElement('circle', { class: 'pp-plinko__chip-halo', cx: '0', cy: '0', r: '22' }),
      svgElement('circle', { class: 'pp-plinko__chip-rim', cx: '0', cy: '0', r: '17' }),
      svgElement('circle', { class: 'pp-plinko__chip-core', cx: '0', cy: '0', r: '10.5' }),
      svgElement('circle', { class: 'pp-plinko__chip-shine', cx: '-4.5', cy: '-5', r: '3.2' }),
    );
    setSvgPosition(chip, this.geometry.start);
    this.chip = chip;
    svg.append(defs, decor, this.trail, pegs, chip);
  }

  #buildBins(model) {
    const list = htmlElement('ol', 'pp-plinko__bins');
    list.setAttribute('aria-label', 'Plinko multiplier bins');
    list.style.gridTemplateColumns = `repeat(${model.rows + 1}, minmax(0, 1fr))`;
    const multipliers = model.multiplierBasisPoints
      ? [...model.multiplierBasisPoints]
      : presentationMultipliers(model.rows, model.risk);
    list.dataset.source = model.multiplierBasisPoints ? 'server' : 'presentation';
    if (Number.isFinite(model.winningBasisPoints) && model.bin !== null) multipliers[model.bin] = model.winningBasisPoints;

    multipliers.forEach((basisPoints, bin) => {
      const distance = Math.abs(bin - (model.rows / 2)) / (model.rows / 2);
      const item = htmlElement('li', 'pp-plinko__bin');
      item.style.setProperty('--pp-bin-hue', String(Math.round(226 + (distance * 84))));
      item.style.setProperty('--pp-bin-delay', `${(Math.abs(bin - (model.rows / 2)) * 24).toFixed(0)}ms`);
      item.dataset.bin = String(bin);
      item.setAttribute('aria-label', `Bin ${bin + 1}: ${formatMultiplier(basisPoints)} multiplier`);
      const cap = htmlElement('span', 'pp-plinko__bin-cap');
      const label = htmlElement('span', 'pp-plinko__bin-label', formatMultiplier(basisPoints));
      label.setAttribute('aria-hidden', 'true');
      item.append(cap, label);
      list.append(item);
      this.binElements.push(item);
    });
    return list;
  }

  #setResult(title, detail, value = '—') {
    this.resultTitle.textContent = title;
    this.resultDetail.textContent = detail;
    this.resultValue.textContent = value;
  }

  #pathPoints(model) {
    const points = [{ ...this.geometry.start, kind: 'start', direction: 0 }];
    let rights = 0;
    for (let row = 0; row < model.rows; row += 1) {
      const direction = model.path[row] === 'R' ? 1 : -1;
      points.push({ ...this.geometry.peg(row, rights), kind: 'peg', row, column: rights, direction });
      if (direction > 0) rights += 1;
    }
    points.push({ ...this.geometry.bin(model.bin), kind: 'bin', direction: 0 });
    return points;
  }

  async #animateDrop(model) {
    const token = ++this.animationToken;
    const points = this.#pathPoints(model);
    this.trail.setAttribute('d', points.map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x.toFixed(2)} ${point.y.toFixed(2)}`).join(' '));
    this.board.classList.add('is-dropping');
    const requestedDuration = Number(this.options.duration);
    const totalDuration = Number.isFinite(requestedDuration)
      ? clamp(requestedDuration, 700, 6000)
      : clamp(1200 + (model.rows * 65), 1700, 2400);
    const firstDuration = totalDuration * 0.12;
    const lastDuration = totalDuration * 0.15;
    const bounceDuration = (totalDuration - firstDuration - lastDuration) / Math.max(1, model.rows - 1);

    for (let index = 1; index < points.length; index += 1) {
      const from = points[index - 1];
      const to = points[index];
      const duration = index === 1 ? firstDuration : (index === points.length - 1 ? lastDuration : bounceDuration);
      const direction = from.kind === 'peg' ? from.direction : 0;
      const completed = await this.#animateSegment(from, to, duration, direction, token);
      if (completed === 'skipped') {
        this.board.classList.remove('is-dropping');
        return 'skipped';
      }
      if (!completed) return false;
      if (to.kind === 'peg') this.#impactPeg(to);
    }

    if (token !== this.animationToken || this.destroyed) return false;
    this.board.classList.remove('is-dropping');
    this.board.classList.add('is-landed');
    this.trail.classList.add('is-visible');
    return true;
  }

  #animateSegment(from, to, duration, direction, token) {
    return new Promise(resolve => {
      let settled = false;
      const finish = value => {
        if (settled) return;
        settled = true;
        if (this.segmentResolve === finish) this.segmentResolve = null;
        resolve(value);
      };
      this.segmentResolve = finish;
      const started = performance.now();
      const frame = now => {
        if (token !== this.animationToken || this.destroyed) {
          finish(false);
          return;
        }
        const progress = clamp((now - started) / duration, 0, 1);
        const falling = progress ** 1.45;
        const arc = direction * Math.sin(Math.PI * progress) * Math.min(13, this.geometry.horizontalStep * 0.12);
        const point = {
          x: from.x + ((to.x - from.x) * progress) + arc,
          y: from.y + ((to.y - from.y) * falling),
        };
        setSvgPosition(this.chip, point);
        this.chip.style.setProperty('--pp-chip-tilt', `${direction * Math.sin(Math.PI * progress) * 18}deg`);
        if (progress < 1) {
          this.animationFrame = requestAnimationFrame(frame);
        } else {
          this.animationFrame = 0;
          finish(true);
        }
      };
      this.animationFrame = requestAnimationFrame(frame);
    });
  }

  #impactPeg(point) {
    const peg = this.pegsByKey.get(`${point.row}:${point.column}`);
    if (peg) peg.classList.add('is-hit');
    this.#emitParticles(point.x, point.y, point.direction);
  }

  #emitParticles(x, y, direction) {
    for (let index = 0; index < 4; index += 1) {
      const particle = htmlElement('i', 'pp-plinko__particle');
      const angle = ((index - 1.5) * 36) + (direction * 18);
      const distance = 14 + (index * 4);
      particle.style.setProperty('--pp-particle-x', `${(x / VIEWBOX.width) * 100}%`);
      particle.style.setProperty('--pp-particle-y', `${(y / VIEWBOX.height) * 100}%`);
      particle.style.setProperty('--pp-particle-dx', `${Math.cos((angle * Math.PI) / 180) * distance}px`);
      particle.style.setProperty('--pp-particle-dy', `${Math.sin((angle * Math.PI) / 180) * distance}px`);
      particle.style.setProperty('--pp-particle-delay', `${index * 16}ms`);
      particle.addEventListener('animationend', () => particle.remove(), { once: true });
      this.effects.append(particle);
    }
  }

  #placeAtLanding(model, landedMultiplier) {
    setSvgPosition(this.chip, this.geometry.bin(model.bin));
    this.board.classList.remove('is-dropping');
    this.board.classList.add('is-landed');
    this.trail.classList.add('is-visible');
    const winningBin = this.binElements[model.bin];
    if (winningBin) {
      winningBin.classList.add('is-winner');
      winningBin.setAttribute('aria-current', 'true');
    }
    const payout = formatCoins(model.payout);
    this.#setResult(`Landed on ${landedMultiplier}`, `Bin ${model.bin + 1} of ${model.rows + 1} · ${riskLabel(model.risk)}`, `${payout} CC`);
    this.liveStatus.textContent = `Server result: landed in bin ${model.bin + 1} of ${model.rows + 1}, ${landedMultiplier} multiplier, fictional payout ${payout} Cozy Coins.`;
    this.activeModel = null;
    this.activeLandedMultiplier = null;
  }
}

export function mountPlinkoBoard(outcomeRoot, options = {}) {
  return new PlinkoBoard(outcomeRoot, options);
}
