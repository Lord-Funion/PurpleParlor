import { GameApiClient } from './api-client.js';
import { OutcomeRenderer } from './outcome-renderer.js';

function clientSeed() {
  const storageKey = 'purple-parlor-client-seed-v1';
  try {
    let seed = sessionStorage.getItem(storageKey);
    if (!seed) {
      const bytes = new Uint8Array(16);
      crypto.getRandomValues(bytes);
      seed = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
      sessionStorage.setItem(storageKey, seed);
    }
    return seed;
  } catch {
    return 'browser-session';
  }
}

/**
 * Progressive-enhancement controller for any element marked data-game-client.
 * Required descendants: [data-game-outcome], [data-game-actions]. Optional
 * fields use name="option.foo" and are forwarded as choices, never outcomes.
 * Visual motion only settles onto fields returned by the authoritative API.
 */
export class ServerAuthoritativeGameClient {
  constructor(root, options = {}) {
    this.root = root;
    this.slug = root.dataset.gameSlug;
    this.roundId = null;
    this.roundWager = 0;
    this.pending = false;
    this.api = new GameApiClient({
      baseUrl: options.baseUrl || root.dataset.apiBase || '',
      csrfToken: options.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '',
    });
    this.actions = root.querySelector('[data-game-actions]');
    this.controls = root.querySelector('[data-game-controls]');
    this.status = root.querySelector('[data-game-status]');
    this.reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches
      || document.documentElement.dataset.prefReducedMotion === 'true'
      || document.documentElement.dataset.prefAnimations === 'false';
    this.outcome = new OutcomeRenderer(root.querySelector('[data-game-outcome]'), {
      slug: this.slug,
      gameName: root.dataset.gameName || '',
      announcer: root.querySelector('[data-game-announcer]'),
      skipButton: root.querySelector('[data-game-skip-animation]'),
      reducedMotion: this.reducedMotion,
    });
    this.seed = clientSeed();
    this.#renderInitialOptions();
    this.#syncOptionConstraints();
    this.outcome.configure(this.#readOptions());
    this.#bind();
  }

  #bind() {
    this.root.addEventListener('submit', event => {
      event.preventDefault();
      const action = event.submitter?.value || event.submitter?.dataset.action || 'play';
      this.play(action);
    });
    this.actions?.addEventListener('click', event => {
      const button = event.target.closest('[data-action]');
      if (button) {
        const overrides = {};
        if (button.dataset.optionKey) {
          const raw = button.dataset.optionValue;
          overrides[button.dataset.optionKey] = button.dataset.optionType === 'integer' ? Number.parseInt(raw, 10) : raw;
        }
        this.play(button.dataset.action, overrides);
      }
    });
    this.root.addEventListener('click', event => {
      const button = event.target.closest('[data-game-visual-action]');
      if (!button || button.disabled || this.pending) return;
      const overrides = {};
      if (button.dataset.optionKey) {
        overrides[button.dataset.optionKey] = button.dataset.optionType === 'integer'
          ? Number.parseInt(button.dataset.optionValue, 10)
          : button.dataset.optionValue;
      }
      this.play(button.dataset.gameVisualAction, overrides);
    });
    this.root.addEventListener('keydown', event => {
      if (event.key === 'Escape' && this.pending) {
        this.outcome.skip();
        this.#setStatus('Animation finished. Any server request is still in progress; please wait for its result.');
      }
      if (event.key === 'Enter' && event.target === this.root && !this.pending) {
        this.actions?.querySelector('button:not(:disabled)')?.click();
      }
    });
    this.controls?.addEventListener('change', event => {
      if (!this.pending && event.target.matches('[name^="option."]')) {
        this.#syncOptionConstraints();
        this.outcome.configure(this.#readOptions());
      }
    });
    window.addEventListener('parlor:preferences', event => {
      this.reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches
        || event.detail?.reducedMotion === true
        || event.detail?.animations === false;
    });
  }

  async play(action = 'play', optionOverrides = {}) {
    if (this.pending || !this.slug) return;
    const wagerField = this.root.querySelector('[name="wager"]');
    const wager = this.roundId ? this.roundWager : Number.parseInt(wagerField?.value || '0', 10);
    this.#syncOptionConstraints();
    const options = { ...this.#readOptions(), ...optionOverrides };
    if (!this.#validateActionOptions(action, options)) return;
    if (!this.roundId && wager > 0 && this.#wagerConfirmationEnabled()
      && !window.confirm(`Use ${wager} fictional Cozy Coins for this round?`)) {
      this.#setStatus('Round cancelled. No fictional wager was sent to the server.');
      return;
    }
    this.pending = true;
    this.#disable(true);
    this.#setStatus('Waiting for the server-authoritative result…');
    try {
      const payload = this.roundId
        ? await this.api.act(this.slug, this.roundId, action, { wager, options, clientSeed: this.seed })
        : await this.api.start(this.slug, { action, wager, options, clientSeed: this.seed });
      this.roundId = payload.complete ? null : payload.roundId;
      this.roundWager = payload.complete ? 0 : Number(payload.wager);
      this.#setStatus('Animating the server-authoritative result. Use Skip animation or Escape to finish instantly.');
      const reveal = this.outcome.render(payload, { reducedMotion: this.reducedMotion });
      this.#disable(true);
      await reveal;
      this.#renderActions(payload.nextActions || [], payload.complete, payload);
      this.#setStatus(payload.complete ? `Round complete. Fictional payout ${payload.payout} Cozy Coins.` : 'Round saved. Choose the next action.');
      this.root.dispatchEvent(new CustomEvent('parlor:game-result', { bubbles: true, detail: payload }));
    } catch (error) {
      this.outcome.error(error.message || 'The game action failed safely. Your balance was not changed unless the server recorded the round.');
      this.#setStatus('Action failed. Check recent rounds before retrying if the connection was interrupted.');
    } finally {
      this.pending = false;
      this.#disable(false);
    }
  }

  #readOptions() {
    const options = {};
    this.root.querySelectorAll('[name^="option."]').forEach(field => {
      if (field.disabled) return;
      const key = field.name.slice(7);
      if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) return;
      let value = field.value;
      if (field.dataset.type === 'integer') value = Number.parseInt(value, 10);
      if (field.dataset.type === 'integer-list') value = value.split(',').filter(Boolean).map(item => Number.parseInt(item.trim(), 10));
      if (field.dataset.oneBased === 'true' && Number.isInteger(value)) value -= 1;
      if (field.type === 'checkbox') {
        if (!Array.isArray(options[key])) options[key] = [];
        options[key].push(value);
      } else {
        options[key] = value;
      }
    });
    return options;
  }

  #syncOptionConstraints() {
    const field = key => this.root.querySelector(`[name="option.${key}"]`);
    const show = (input, visible) => {
      if (!input) return;
      input.closest('.game-option')?.toggleAttribute('hidden', !visible);
      input.disabled = !visible;
    };
    const syncOneBasedIndex = (input, maximum, label) => {
      if (!input) return;
      input.min = '1';
      input.max = String(maximum);
      if (input.previousElementSibling) input.previousElementSibling.textContent = label;
      const current = Number.parseInt(input.value, 10);
      if (!Number.isInteger(current) || current < 1) input.value = '1';
      else if (current > maximum) input.value = String(maximum);
    };

    if (['european-roulette', 'american-roulette'].includes(this.slug)) {
      const selection = field('selection');
      show(selection, field('bet')?.value === 'straight');
    }

    if (this.slug === 'sic-bo') {
      const bet = field('bet')?.value;
      const selection = field('selection');
      const needsSelection = ['sum', 'triple'].includes(bet);
      show(selection, needsSelection);
      if (selection && needsSelection) {
        const minimum = bet === 'sum' ? 4 : 1;
        const maximum = bet === 'sum' ? 17 : 6;
        selection.min = String(minimum);
        selection.max = String(maximum);
        selection.previousElementSibling.textContent = bet === 'sum' ? 'Exact total (4–17)' : 'Triple face (1–6)';
        const current = Number.parseInt(selection.value, 10);
        if (!Number.isInteger(current) || current < minimum || current > maximum) selection.value = String(bet === 'sum' ? 10 : 4);
      }
    }

    if (this.slug === 'number-draw') {
      const mode = field('mode')?.value || 'exact';
      const guess = field('guess');
      if (guess) {
        guess.min = '0';
        guess.max = mode === 'last_digit' ? '9' : '99';
        guess.previousElementSibling.textContent = mode === 'last_digit' ? 'Last digit (0–9)' : mode === 'range' ? 'Number inside your ten-number band' : 'Exact number (0–99)';
        if (mode === 'last_digit' && Number.parseInt(guess.value, 10) > 9) guess.value = '7';
      }
    }

    if (this.slug === 'klondike-solitaire') {
      const from = field('from')?.value || 'waste';
      const to = field('to')?.value || 'tableau';
      const sourceColumn = field('sourceColumn');
      show(sourceColumn, from === 'tableau');
      if (from === 'tableau') syncOneBasedIndex(sourceColumn, 7, 'Source tableau column (1–7)');
      syncOneBasedIndex(
        field('destination'),
        to === 'foundation' ? 4 : 7,
        to === 'foundation' ? 'Foundation suit (1–4)' : 'Destination tableau column (1–7)',
      );
    }

    if (this.slug === 'freecell') {
      const from = field('from')?.value || 'column';
      const to = field('to')?.value || 'column';
      syncOneBasedIndex(
        field('fromIndex'),
        from === 'column' ? 8 : 4,
        from === 'column' ? 'Source column (1–8)' : 'Source free cell (1–4)',
      );
      syncOneBasedIndex(
        field('toIndex'),
        to === 'column' ? 8 : 4,
        to === 'column'
          ? 'Destination column (1–8)'
          : to === 'freecell' ? 'Destination free cell (1–4)' : 'Foundation suit (1–4)',
      );
    }

    if (this.slug === 'pyramid-solitaire') {
      const selections = Array.from(this.root.querySelectorAll('[data-pyramid-selection] input[name="option.indices"]'));
      const selectedCount = selections.filter(input => input.checked).length;
      selections.forEach(input => {
        input.disabled = selectedCount >= 2 && !input.checked;
      });
      const status = this.root.querySelector('[data-pyramid-selection-status]');
      if (status) status.textContent = `${selectedCount} of 2 cards selected.`;
    }
  }

  #validateActionOptions(action, options) {
    if (this.slug === 'pyramid-solitaire' && action === 'remove') {
      const indices = Array.isArray(options.indices) ? Array.from(new Set(options.indices)) : [];
      if (indices.length < 1 || indices.length > 2) {
        this.#setStatus('Select one King or exactly two cards totaling thirteen before removing.');
        this.root.querySelector('[data-pyramid-selection] input:not(:disabled)')?.focus();
        return false;
      }
    }

    if (this.slug === 'klondike-solitaire' && action === 'move') {
      const destinationMaximum = options.to === 'foundation' ? 3 : 6;
      if ((options.from === 'tableau' && (!Number.isInteger(options.sourceColumn) || options.sourceColumn < 0 || options.sourceColumn > 6))
        || !Number.isInteger(options.destination) || options.destination < 0 || options.destination > destinationMaximum) {
        this.#setStatus('Choose a valid Klondike source and destination for that pile type.');
        return false;
      }
    }

    if (this.slug === 'freecell' && action === 'move') {
      const sourceMaximum = options.from === 'column' ? 7 : 3;
      const destinationMaximum = options.to === 'column' ? 7 : 3;
      if (!Number.isInteger(options.fromIndex) || options.fromIndex < 0 || options.fromIndex > sourceMaximum
        || !Number.isInteger(options.toIndex) || options.toIndex < 0 || options.toIndex > destinationMaximum) {
        this.#setStatus('Choose a valid FreeCell source and destination for those pile types.');
        return false;
      }
    }

    return true;
  }

  #wagerConfirmationEnabled() {
    return document.documentElement.dataset.prefConfirmWagers !== 'false';
  }

  #renderActions(actions, complete, payload = {}) {
    if (!this.actions) return;
    this.actions.replaceChildren();
    if (!complete && this.#renderContextControls(payload)) {
      this.#syncOptionConstraints();
      return;
    }
    const values = complete ? ['play'] : actions;
    values.forEach((action, index) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.dataset.action = action;
      button.textContent = action.replace(/_/g, ' ').replace(/^./, value => value.toUpperCase());
      button.className = index === 0 ? 'button button-primary' : 'button button-secondary';
      button.setAttribute('aria-keyshortcuts', index === 0 ? 'Enter' : '');
      this.actions.append(button);
    });
  }

  #renderInitialOptions() {
    if (!this.controls || !this.actions) return;
    const panel = document.createElement('div');
    panel.className = 'game-option-panel';
    panel.dataset.gameOptions = '';
    const schemas = {
      'plinko': [['rows', 'Rows', 'number', 12, { min: 8, max: 16 }], ['risk', 'Risk', 'select', 'medium', ['low','medium','high']]],
      'european-roulette': [['bet', 'Bet', 'select', 'red', ['straight','red','black','odd','even','low','high','dozen1','dozen2','dozen3']], ['selection', 'Straight pocket', 'text', '17']],
      'american-roulette': [['bet', 'Bet', 'select', 'red', ['straight','red','black','odd','even','low','high','dozen1','dozen2','dozen3']], ['selection', 'Straight pocket', 'text', '17']],
      'baccarat': [['bet', 'Side', 'select', 'player', ['player','banker','tie']]],
      'three-card-poker': [['decision', 'Decision', 'select', 'play', ['play','fold']]],
      'sic-bo': [['bet', 'Bet', 'select', 'small', ['small','big','odd','even','sum','triple']], ['selection', 'Sum or face', 'number', 4, { min: 1, max: 17 }]],
      'keno': [['picks', 'Picks (comma separated)', 'integer-list', '7,14,21,28,35']],
      'over-under-dice': [['choice', 'Direction', 'select', 'under', ['under','over']], ['target', 'Target', 'number', 50, { min: 2, max: 98 }]],
      'coin-flip': [['guess', 'Guess', 'select', 'heads', ['heads','tails']]],
      'number-draw': [['mode', 'Mode', 'select', 'exact', ['exact','last_digit','range']], ['guess', 'Guess', 'number', 17, { min: 0, max: 99 }]],
      'horse-racing': [['horse', 'Horse', 'select', '0', ['0 — Lavender Bolt','1 — Golden Onion','2 — Velvet Comet','3 — Cozy Ember','4 — Moonlit Crown','5 — Royal Whisker']]],
      'lucky-cups': [['cup', 'Cup', 'select', '1', ['1','2','3']]],
      'mines': [['mines', 'Mines', 'number', 5, { min: 1, max: 10 }]],
      'video-poker': [['variant', 'Variant', 'select', 'jacks_or_better', ['jacks_or_better','deuces_wild','bonus_poker']]],
      'texas-holdem': [['difficulty', 'Computer style', 'select', 'friendly', ['friendly','regular','sharp']]],
      'five-card-draw': [['difficulty', 'Computer style', 'select', 'regular', ['friendly','regular','sharp']]],
    };
    (schemas[this.slug] || []).forEach(spec => panel.append(this.#optionField(...spec)));
    if (panel.childElementCount > 0) this.controls.insertBefore(panel, this.actions);
  }

  #optionField(key, labelText, type, value, details = {}) {
    const label = document.createElement('label');
    label.className = 'field game-option';
    const text = document.createElement('span');
    text.textContent = labelText;
    label.append(text);
    let input;
    if (type === 'select') {
      input = document.createElement('select');
      details.forEach(optionValue => {
        const option = document.createElement('option');
        const rawValue = String(optionValue).split(' — ')[0];
        option.value = rawValue;
        option.textContent = String(optionValue).replace(/_/g, ' ');
        option.selected = rawValue === String(value);
        input.append(option);
      });
    } else {
      input = document.createElement('input');
      input.type = type === 'number' ? 'number' : 'text';
      input.value = String(value);
      if (details.min !== undefined) input.min = String(details.min);
      if (details.max !== undefined) input.max = String(details.max);
      if (type === 'number') input.inputMode = 'numeric';
    }
    input.name = `option.${key}`;
    if (type === 'number') input.dataset.type = 'integer';
    if (type === 'integer-list') input.dataset.type = 'integer-list';
    label.append(input);
    return label;
  }

  #renderContextControls(payload) {
    const state = payload.state || {};
    if (['mines', 'treasure-tiles', 'memory-match'].includes(this.slug)) {
      const count = Number(state.tileCount || state.cardCount || 0);
      const unavailable = new Set([...(state.revealed || []), ...(state.matched || []), ...Object.keys(state.faceUp || {}).map(Number)]);
      const action = this.slug === 'memory-match' ? 'flip' : 'reveal';
      const key = this.slug === 'memory-match' ? 'index' : 'tile';
      const grid = document.createElement('div');
      grid.className = 'game-choice-grid';
      grid.setAttribute('aria-label', `Choose a ${key}`);
      for (let index = 0; index < count; index += 1) {
        const button = document.createElement('button');
        button.type = 'button'; button.dataset.action = action; button.dataset.optionKey = key;
        button.dataset.optionValue = String(index); button.dataset.optionType = 'integer';
        button.textContent = String(index + 1); button.disabled = unavailable.has(index); button.dataset.locked = String(button.disabled);
        button.setAttribute('aria-label', `${key} ${index + 1}${button.disabled ? ', already revealed' : ''}`);
        grid.append(button);
      }
      this.actions.append(grid);
      if (payload.nextActions?.includes('cashout')) this.actions.append(this.#actionButton('cashout', true));
      return true;
    }

    if (['video-poker', 'five-card-draw'].includes(this.slug) && payload.nextActions?.includes('draw')) {
      const cards = state.cards || state.playerCards || [];
      const fieldset = document.createElement('fieldset');
      const legend = document.createElement('legend'); legend.textContent = 'Cards to hold'; fieldset.append(legend);
      cards.forEach((card, index) => {
        const label = document.createElement('label'); const checkbox = document.createElement('input');
        checkbox.type = 'checkbox'; checkbox.name = 'option.holds'; checkbox.value = String(index); checkbox.dataset.type = 'integer';
        label.append(checkbox, document.createTextNode(` ${card.label || card.code || `Card ${index + 1}`}`)); fieldset.append(label);
      });
      this.actions.append(fieldset, this.#actionButton('draw', true));
      return true;
    }

    if (this.slug === 'tripeaks-solitaire' && payload.nextActions?.includes('take')) {
      const grid = document.createElement('div'); grid.className = 'game-choice-grid';
      (state.tableau || []).forEach((card, index) => {
        if (!card || card.hidden) return;
        const button = this.#actionButton('take'); button.dataset.optionKey = 'index'; button.dataset.optionValue = String(index); button.dataset.optionType = 'integer'; button.textContent = card.label || card.code || `Card ${index + 1}`; grid.append(button);
      });
      this.actions.append(grid);
      if (payload.nextActions?.includes('draw')) this.actions.append(this.#actionButton('draw', true));
      return true;
    }

    if (this.slug === 'pyramid-solitaire' && payload.nextActions?.includes('remove')) {
      const fieldset = document.createElement('fieldset'); fieldset.dataset.pyramidSelection = ''; const legend = document.createElement('legend'); legend.textContent = 'Choose one King or two cards totaling thirteen (maximum two)'; fieldset.append(legend);
      (state.tableau || []).forEach((card, index) => {
        if (!card || card.hidden) return;
        const label = document.createElement('label'); const box = document.createElement('input'); box.type = 'checkbox'; box.name = 'option.indices'; box.value = String(index); box.dataset.type = 'integer'; label.append(box, document.createTextNode(` ${card.label || card.code}`)); fieldset.append(label);
      });
      if (state.wasteTop) { const label=document.createElement('label'); const box=document.createElement('input'); box.type='checkbox';box.name='option.indices';box.value='-1';box.dataset.type='integer';label.append(box,document.createTextNode(` Waste: ${state.wasteTop.label || state.wasteTop.code}`));fieldset.append(label); }
      const selectionStatus = document.createElement('p'); selectionStatus.className = 'fine-print'; selectionStatus.dataset.pyramidSelectionStatus = ''; selectionStatus.setAttribute('aria-live', 'polite'); selectionStatus.textContent = '0 of 2 cards selected.'; fieldset.append(selectionStatus);
      this.actions.append(fieldset, this.#actionButton('remove', true));
      if (payload.nextActions?.includes('draw')) this.actions.append(this.#actionButton('draw'));
      return true;
    }

    if (this.slug === 'klondike-solitaire' && payload.nextActions?.includes('move')) {
      const panel = document.createElement('div'); panel.className = 'game-move-controls';
      panel.append(this.#optionField('from', 'From', 'select', 'waste', ['waste','tableau']), this.#optionField('sourceColumn', 'Source column (1–7)', 'number', 1, { min: 1, max: 7 }), this.#optionField('to', 'To', 'select', 'tableau', ['tableau','foundation']), this.#optionField('destination', 'Destination (1–7 or suit 1–4)', 'number', 1, { min: 1, max: 7 }));
      // Engine indexes are zero-based; normalize just before sending below.
      panel.querySelectorAll('[name="option.sourceColumn"], [name="option.destination"]').forEach(input => { input.dataset.oneBased = 'true'; });
      this.actions.append(panel, this.#actionButton('move', true));
      if (payload.nextActions?.includes('draw')) this.actions.append(this.#actionButton('draw'));
      return true;
    }

    if (this.slug === 'freecell' && payload.nextActions?.includes('move')) {
      const panel = document.createElement('div'); panel.className = 'game-move-controls';
      panel.append(this.#optionField('from', 'From', 'select', 'column', ['column','freecell']), this.#optionField('fromIndex', 'Source (1–8)', 'number', 1, { min: 1, max: 8 }), this.#optionField('to', 'To', 'select', 'column', ['column','freecell','foundation']), this.#optionField('toIndex', 'Destination (1–8)', 'number', 1, { min: 1, max: 8 }));
      panel.querySelectorAll('[name="option.fromIndex"], [name="option.toIndex"]').forEach(input => { input.dataset.oneBased = 'true'; });
      this.actions.append(panel, this.#actionButton('move', true));
      return true;
    }
    return false;
  }

  #actionButton(action, primary = false) {
    const button = document.createElement('button');
    button.type = 'button'; button.dataset.action = action; button.textContent = action.replace(/_/g, ' ').replace(/^./, value => value.toUpperCase());
    button.className = primary ? 'button button-primary' : 'button button-secondary';
    return button;
  }

  #disable(disabled) {
    this.root.setAttribute('aria-busy', String(disabled));
    this.root.querySelectorAll('button:not([data-game-skip-animation]), input, select').forEach(control => {
      if (disabled) {
        if (control.disabled && control.dataset.disabledByGameClient !== 'true') control.dataset.previouslyDisabled = 'true';
        control.disabled = true;
        control.dataset.disabledByGameClient = 'true';
      } else {
        if (control.dataset.disabledByGameClient !== 'true') return;
        control.disabled = control.dataset.previouslyDisabled === 'true' || control.dataset.locked === 'true';
        delete control.dataset.previouslyDisabled;
        delete control.dataset.disabledByGameClient;
      }
    });
  }

  #setStatus(message) {
    if (this.status) this.status.textContent = message;
  }
}

export function mountGameClients(scope = document) {
  return Array.from(scope.querySelectorAll('[data-game-client]'), root => new ServerAuthoritativeGameClient(root));
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => mountGameClients(), { once: true });
} else {
  mountGameClients();
}
