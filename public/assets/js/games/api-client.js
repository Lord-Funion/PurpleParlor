/**
 * Transport only. This module never generates, predicts, scores, or alters a
 * game outcome. The PHP API is authoritative for state, payout, and balance.
 */
export class GameApiClient {
  constructor({ baseUrl = '', csrfToken = '' } = {}) {
    this.baseUrl = baseUrl.replace(/\/$/, '');
    this.csrfToken = csrfToken;
    this.sequence = 0;
    this.pendingKeys = new Map();
  }

  async start(slug, { action = 'play', wager = 0, options = {}, clientSeed = '' } = {}, signal) {
    const path = `/api/games/${encodeURIComponent(slug)}/round`;
    const request = {
      action,
      wager,
      options,
      client_seed: clientSeed,
    };
    const fingerprint = this.#fingerprint(path, request);
    request.idempotency_key = this.#pendingKey(fingerprint);
    return this.#send(path, request, signal, slug, fingerprint);
  }

  async act(slug, roundId, action, { wager, options = {}, clientSeed = '' } = {}, signal) {
    const path = `/api/games/${encodeURIComponent(slug)}/action`;
    const request = {
      action,
      wager,
      options,
      client_seed: clientSeed,
      round_id: roundId,
    };
    const fingerprint = this.#fingerprint(path, request);
    request.idempotency_key = this.#pendingKey(fingerprint);
    return this.#send(path, request, signal, slug, fingerprint);
  }

  createIdempotencyKey() {
    if (globalThis.crypto?.randomUUID) return `web:${crypto.randomUUID()}`;
    if (globalThis.crypto?.getRandomValues) {
      const bytes = new Uint8Array(16);
      globalThis.crypto.getRandomValues(bytes);
      return `web:${Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('')}`;
    }
    this.sequence += 1;
    return `web:fallback:${Date.now().toString(36)}:${this.sequence.toString(36).padStart(8, '0')}`;
  }

  #fingerprint(path, body) {
    const canonical = value => {
      if (Array.isArray(value)) return value.map(canonical);
      if (value && typeof value === 'object') {
        return Object.fromEntries(Object.keys(value).sort().map(key => [key, canonical(value[key])]));
      }
      return value;
    };
    return JSON.stringify([path, canonical(body)]);
  }

  #storageKey(fingerprint) {
    let hash = 2166136261;
    for (let index = 0; index < fingerprint.length; index += 1) {
      hash ^= fingerprint.charCodeAt(index);
      hash = Math.imul(hash, 16777619);
    }
    return `purple-parlor:pending-game-request:${(hash >>> 0).toString(16)}`;
  }

  #pendingKey(fingerprint) {
    if (this.pendingKeys.has(fingerprint)) return this.pendingKeys.get(fingerprint);
    const storageKey = this.#storageKey(fingerprint);
    try {
      const stored = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
      if (stored?.fingerprint === fingerprint && typeof stored?.idempotencyKey === 'string') {
        this.pendingKeys.set(fingerprint, stored.idempotencyKey);
        return stored.idempotencyKey;
      }
    } catch {
      // Private browsing and hardened clients can disable sessionStorage.
    }
    const idempotencyKey = this.createIdempotencyKey();
    this.pendingKeys.set(fingerprint, idempotencyKey);
    try {
      sessionStorage.setItem(storageKey, JSON.stringify({ fingerprint, idempotencyKey }));
    } catch {
      // In-memory reuse still protects retries during this page session.
    }
    return idempotencyKey;
  }

  #clearPendingKey(fingerprint) {
    this.pendingKeys.delete(fingerprint);
    try {
      const storageKey = this.#storageKey(fingerprint);
      const stored = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
      if (stored?.fingerprint === fingerprint) sessionStorage.removeItem(storageKey);
    } catch {
      // Nothing else is required when storage is unavailable.
    }
  }

  async #send(path, body, signal, expectedSlug, fingerprint) {
    const response = await fetch(`${this.baseUrl}${path}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': this.csrfToken,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(body),
      signal,
    });
    let payload;
    try {
      payload = await response.json();
    } catch {
      throw new GameApiError('The server returned an unreadable response.', response.status, 'invalid_response');
    }
    if (!response.ok) {
      // A definitive client rejection did not leave an ambiguous transport
      // outcome. Timeouts, throttling, and server errors retain the same key.
      if (response.status >= 400 && response.status < 500 && ![408, 425, 429].includes(response.status)) {
        this.#clearPendingKey(fingerprint);
      }
      throw new GameApiError(payload.message || 'The game action could not be completed.', response.status, payload.code || 'request_failed', payload);
    }
    if (!payload || payload.slug !== expectedSlug || typeof payload.roundId !== 'string') {
      throw new GameApiError('The server response is missing required round data.', response.status, 'invalid_response');
    }
    this.#clearPendingKey(fingerprint);
    return Object.freeze(payload);
  }
}

export class GameApiError extends Error {
  constructor(message, status, code, details = {}) {
    super(message);
    this.name = 'GameApiError';
    this.status = status;
    this.code = code;
    this.details = details;
  }
}
