const STORAGE_KEY = 'purple-parlor.preferences.v1';

const defaults = Object.freeze({
  theme: 'purple-parlor',
  appearance: 'dark',
  animations: true,
  particles: true,
  reducedMotion: false,
  largeText: false,
  compact: false,
  highContrast: false,
  colorblindSuits: false,
  confirmWagers: true,
  effectsVolume: 70,
  musicVolume: 35,
  sessionReminder: 'off',
});

const installedThemes = new Set([
  'purple-parlor', 'midnight-lavender', 'cozy-fireplace',
  'royal-plum', 'soft-daylight', 'high-contrast',
]);
const serverThemes = String(document.documentElement.dataset.allowedThemes || 'purple-parlor')
  .split(',')
  .map((theme) => theme.trim())
  .filter((theme) => installedThemes.has(theme));
const allowedThemes = new Set(serverThemes.length ? serverThemes : ['purple-parlor']);

function safePreferences(input) {
  const source = input && typeof input === 'object' ? input : {};
  const boolean = (name) => typeof source[name] === 'boolean' ? source[name] : defaults[name];
  const volume = (name) => Math.min(100, Math.max(0, Number(source[name] ?? defaults[name]) || 0));
  const appearance = ['dark', 'light', 'system'].includes(source.appearance) ? source.appearance : defaults.appearance;
  const reminder = ['off', '30', '45', '60'].includes(String(source.sessionReminder)) ? String(source.sessionReminder) : defaults.sessionReminder;
  return {
    theme: allowedThemes.has(source.theme) ? source.theme : defaults.theme,
    appearance,
    animations: boolean('animations'),
    particles: boolean('particles'),
    reducedMotion: boolean('reducedMotion'),
    largeText: boolean('largeText'),
    compact: boolean('compact'),
    highContrast: boolean('highContrast'),
    colorblindSuits: boolean('colorblindSuits'),
    confirmWagers: boolean('confirmWagers'),
    effectsVolume: volume('effectsVolume'),
    musicVolume: volume('musicVolume'),
    sessionReminder: reminder,
  };
}

function readLocalPreferences() {
  try {
    const stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
    return stored && typeof stored === 'object' && !Array.isArray(stored) ? stored : {};
  } catch {
    return {};
  }
}

function readServerPreferences() {
  const raw = document.documentElement.dataset.userPreferences;
  if (!raw) return null;
  try {
    const stored = JSON.parse(raw);
    if (!stored || typeof stored !== 'object' || Array.isArray(stored)) return null;
    const mapped = {};
    const keys = {
      theme: 'theme',
      appearance: 'appearance',
      animations: 'animations',
      particles: 'particles',
      reduced_motion: 'reducedMotion',
      large_text: 'largeText',
      compact: 'compact',
      high_contrast: 'highContrast',
      colorblind_suits: 'colorblindSuits',
      confirm_wagers: 'confirmWagers',
      effects_volume: 'effectsVolume',
      music_volume: 'musicVolume',
      session_reminder_minutes: 'sessionReminder',
    };
    Object.entries(keys).forEach(([serverKey, clientKey]) => {
      if (Object.prototype.hasOwnProperty.call(stored, serverKey)) mapped[clientKey] = stored[serverKey];
    });
    return mapped;
  } catch {
    return null;
  }
}

const serverPreferences = readServerPreferences();
const localPreferences = readLocalPreferences();
let preferences = safePreferences(serverPreferences === null
  ? localPreferences
  : { ...localPreferences, ...serverPreferences });
let reminderTimer = null;

function applyPreferences(next, persist = true) {
  preferences = safePreferences(next);
  const root = document.documentElement;
  root.dataset.theme = preferences.theme;
  root.dataset.appearance = preferences.appearance;
  root.dataset.prefAnimations = String(preferences.animations);
  root.dataset.prefParticles = String(preferences.particles);
  root.dataset.prefReducedMotion = String(preferences.reducedMotion);
  root.dataset.prefLargeText = String(preferences.largeText);
  root.dataset.prefCompact = String(preferences.compact);
  root.dataset.prefHighContrast = String(preferences.highContrast);
  root.dataset.prefColorblindSuits = String(preferences.colorblindSuits);
  root.dataset.prefConfirmWagers = String(preferences.confirmWagers);

  document.querySelectorAll('[data-sound-effect], audio[data-channel="effects"]').forEach((audio) => {
    audio.volume = preferences.effectsVolume / 100;
  });
  document.querySelectorAll('audio[data-channel="music"]').forEach((audio) => {
    audio.volume = preferences.musicVolume / 100;
  });

  if (persist) {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(preferences)); } catch { /* Private storage may be unavailable. */ }
  }
  populatePreferencesForm();
  scheduleSessionReminder();
  window.dispatchEvent(new CustomEvent('parlor:preferences', { detail: { ...preferences } }));
}

function populatePreferencesForm() {
  document.querySelectorAll('[data-pref]').forEach((control) => {
    const key = control.dataset.pref;
    if (!(key in preferences)) return;
    if (control.type === 'checkbox') control.checked = Boolean(preferences[key]);
    else if (control.type === 'radio') control.checked = control.value === preferences[key];
    else control.value = String(preferences[key]);
  });
  document.querySelectorAll('[data-volume-output]').forEach((output) => {
    const key = output.dataset.volumeOutput === 'music' ? 'musicVolume' : 'effectsVolume';
    output.textContent = `${preferences[key]}%`;
  });
}

function scheduleSessionReminder() {
  if (reminderTimer) window.clearTimeout(reminderTimer);
  if (preferences.sessionReminder === 'off') return;
  const minutes = Number(preferences.sessionReminder);
  reminderTimer = window.setTimeout(() => {
    showSessionReminder(minutes);
    scheduleSessionReminder();
  }, minutes * 60 * 1000);
}

function showSessionReminder(minutes) {
  const existing = document.querySelector('[data-session-reminder]');
  if (existing) existing.remove();
  const notice = document.createElement('aside');
  notice.className = 'session-reminder card card-pad';
  notice.dataset.sessionReminder = '';
  notice.setAttribute('role', 'alertdialog');
  notice.setAttribute('aria-label', 'Session reminder');
  const title = document.createElement('h2');
  title.textContent = 'A gentle room check';
  const copy = document.createElement('p');
  copy.textContent = `You have been in the Parlor for about ${minutes} minutes. Your progress is safe if you take a break.`;
  const actions = document.createElement('div');
  actions.className = 'button-row';
  const continueButton = document.createElement('button');
  continueButton.type = 'button';
  continueButton.className = 'button button-ghost';
  continueButton.textContent = 'Continue';
  continueButton.addEventListener('click', () => notice.remove());
  const breakLink = document.createElement('a');
  breakLink.className = 'button button-gold';
  breakLink.href = '/take-a-break';
  breakLink.textContent = 'Take a break';
  actions.append(continueButton, breakLink);
  notice.append(title, copy, actions);
  document.body.append(notice);
  continueButton.focus();
}

function buildParticles() {
  const host = document.querySelector('[data-particles]');
  if (!host || host.childElementCount) return;
  const fragment = document.createDocumentFragment();
  for (let index = 0; index < 18; index += 1) {
    const particle = document.createElement('i');
    particle.className = `particle particle-${index + 1}`;
    fragment.append(particle);
  }
  host.append(fragment);
}

function openDialog(id, trigger) {
  const dialog = document.getElementById(id);
  if (!(dialog instanceof HTMLDialogElement)) return;
  dialog.dataset.returnFocus = trigger?.id || '';
  if (!trigger?.id && trigger) {
    trigger.id = `dialog-trigger-${crypto.getRandomValues(new Uint32Array(1))[0]}`;
    dialog.dataset.returnFocus = trigger.id;
  }
  if (!dialog.open) dialog.showModal();
}

function closeDialog(id) {
  const dialog = document.getElementById(id);
  if (!(dialog instanceof HTMLDialogElement) || !dialog.open) return;
  const returnId = dialog.dataset.returnFocus;
  dialog.close();
  if (returnId) document.getElementById(returnId)?.focus();
}

function updateCatalog() {
  const search = document.querySelector('[data-catalog-search]');
  const category = document.querySelector('[data-catalog-category]');
  const query = (search?.value || '').trim().toLocaleLowerCase();
  const selectedCategory = (category?.value || 'all').toLocaleLowerCase();
  let visible = 0;
  document.querySelectorAll('[data-game-card]').forEach((card) => {
    const text = card.dataset.searchText || '';
    const cardCategory = card.querySelector('.tag')?.textContent.trim().toLocaleLowerCase() || '';
    const show = (!query || text.includes(query)) && (selectedCategory === 'all' || cardCategory === selectedCategory);
    card.classList.toggle('is-hidden', !show);
    if (show) visible += 1;
  });
  const count = document.querySelector('[data-catalog-count]');
  if (count) count.textContent = `${visible} ${visible === 1 ? 'game' : 'games'}`;
  document.querySelector('[data-catalog-empty]')?.classList.toggle('is-hidden', visible !== 0);
}

function toggleGlobalAudio(button) {
  const shouldMute = button.getAttribute('aria-pressed') !== 'true';
  button.setAttribute('aria-pressed', String(shouldMute));
  button.setAttribute('aria-label', shouldMute ? 'Unmute all audio' : 'Mute all audio');
  document.querySelectorAll('audio, video').forEach((media) => { media.muted = shouldMute; });
  window.dispatchEvent(new CustomEvent('parlor:audio-mute', { detail: { muted: shouldMute } }));
}

document.addEventListener('click', (event) => {
  const target = event.target instanceof Element ? event.target : null;
  if (!target) return;
  const menuToggle = target.closest('[data-menu-toggle]');
  if (menuToggle) {
    const menu = document.querySelector('[data-menu]');
    const open = !menu?.classList.contains('is-open');
    menu?.classList.toggle('is-open', open);
    menuToggle.setAttribute('aria-expanded', String(open));
  }
  const dialogOpen = target.closest('[data-dialog-open]');
  if (dialogOpen) openDialog(dialogOpen.dataset.dialogOpen, dialogOpen);
  const dialogClose = target.closest('[data-dialog-close]');
  if (dialogClose) closeDialog(dialogClose.dataset.dialogClose);
  const dismiss = target.closest('[data-dismiss]');
  if (dismiss) dismiss.closest('.notice, [data-dismissible]')?.remove();
  const reset = target.closest('[data-preferences-reset]');
  if (reset) applyPreferences({ ...defaults });
  const fullscreen = target.closest('[data-game-fullscreen]');
  if (fullscreen) {
    const stage = document.querySelector('[data-game-container]') || document.documentElement;
    if (!document.fullscreenElement) stage.requestFullscreen?.();
    else document.exitFullscreen?.();
  }
  const audioToggle = target.closest('[data-audio-toggle]');
  if (audioToggle) toggleGlobalAudio(audioToggle);
  if (target.closest('[data-print]')) window.print();
  if (target.closest('[data-reload]')) window.location.reload();
  const copyButton = target.closest('[data-copy]');
  if (copyButton) {
    const value = document.querySelector(copyButton.dataset.copy)?.textContent || copyButton.dataset.copyValue || '';
    navigator.clipboard?.writeText(value.trim()).then(() => {
      const prior = copyButton.textContent;
      copyButton.textContent = 'Copied';
      window.setTimeout(() => { copyButton.textContent = prior; }, 1600);
    });
  }
  const passwordToggle = target.closest('[data-password-toggle]');
  if (passwordToggle) {
    const input = document.getElementById(passwordToggle.dataset.passwordToggle);
    if (input instanceof HTMLInputElement) {
      const reveal = input.type === 'password';
      input.type = reveal ? 'text' : 'password';
      passwordToggle.setAttribute('aria-pressed', String(reveal));
      passwordToggle.textContent = reveal ? 'Hide' : 'Show';
    }
  }
  const tab = target.closest('[data-tab]');
  if (tab) {
    const group = tab.closest('[role="tablist"]');
    const panel = document.getElementById(tab.getAttribute('aria-controls'));
    group?.querySelectorAll('[role="tab"]').forEach((item) => {
      item.setAttribute('aria-selected', String(item === tab));
      item.tabIndex = item === tab ? 0 : -1;
      const linked = document.getElementById(item.getAttribute('aria-controls'));
      if (linked) linked.hidden = item !== tab;
    });
    panel?.focus();
  }
});

document.addEventListener('change', (event) => {
  const target = event.target;
  if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement)) return;
  if (target.matches('[data-pref]')) {
    const key = target.dataset.pref;
    const value = target.type === 'checkbox' ? target.checked : target.value;
    applyPreferences({ ...preferences, [key]: value });
  }
  if (target.matches('[data-catalog-category], [data-catalog-sort]')) updateCatalog();
});

document.addEventListener('keydown', (event) => {
  const tab = event.target instanceof Element ? event.target.closest('[role="tab"]') : null;
  if (!tab) return;
  const tabs = [...(tab.closest('[role="tablist"]')?.querySelectorAll('[role="tab"]') ?? [])];
  const current = tabs.indexOf(tab);
  if (current < 0) return;
  let next = current;
  if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (current + 1) % tabs.length;
  else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') next = (current - 1 + tabs.length) % tabs.length;
  else if (event.key === 'Home') next = 0;
  else if (event.key === 'End') next = tabs.length - 1;
  else return;
  event.preventDefault();
  tabs[next]?.focus();
  tabs[next]?.click();
});

document.addEventListener('input', (event) => {
  const target = event.target;
  if (!(target instanceof HTMLInputElement)) return;
  if (target.matches('[data-catalog-search]')) updateCatalog();
  if (target.matches('input[type="range"][data-pref]')) {
    const key = target.dataset.pref;
    document.querySelector(`[data-volume-output="${key === 'musicVolume' ? 'music' : 'effects'}"]`)?.replaceChildren(`${target.value}%`);
  }
  const meter = target.closest('[data-character-field]')?.querySelector('[data-character-count]');
  if (meter) meter.textContent = `${target.value.length}`;
});

document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) return;
  const confirmation = form.dataset.confirm;
  if (confirmation && !window.confirm(confirmation)) event.preventDefault();
});

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') {
    const menu = document.querySelector('[data-menu].is-open');
    if (menu) {
      menu.classList.remove('is-open');
      const toggle = document.querySelector('[data-menu-toggle]');
      toggle?.setAttribute('aria-expanded', 'false');
      toggle?.focus();
    }
  }
  if (event.target?.matches('[role="tab"]') && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
    const tabs = [...event.target.closest('[role="tablist"]').querySelectorAll('[role="tab"]')];
    const current = tabs.indexOf(event.target);
    let next = current;
    if (event.key === 'ArrowRight') next = (current + 1) % tabs.length;
    if (event.key === 'ArrowLeft') next = (current - 1 + tabs.length) % tabs.length;
    if (event.key === 'Home') next = 0;
    if (event.key === 'End') next = tabs.length - 1;
    tabs[next]?.focus();
    tabs[next]?.click();
    event.preventDefault();
  }
});

document.addEventListener('fullscreenchange', () => {
  document.body.classList.toggle('game-fullscreen', Boolean(document.fullscreenElement));
  document.querySelectorAll('[data-game-fullscreen]').forEach((button) => {
    button.setAttribute('aria-pressed', String(Boolean(document.fullscreenElement)));
    button.textContent = document.fullscreenElement ? 'Exit fullscreen' : 'Fullscreen';
  });
});

buildParticles();
applyPreferences(preferences, serverPreferences !== null);
updateCatalog();
