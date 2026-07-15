# Game Development

New games implement `GameInterface`, register a unique slug/configuration, and provide public rules, wager constraints, paytable, probabilities, outcome generation, payout, tutorial, client presentation, tests, and simulations.

Production randomness comes only from approved server-side sources. The client sends an action and options, never a result. The service validates authentication/guest state, CSRF, idempotency, configuration, wager, wallet lock, and round state in one database transaction. Multi-action games append validated immutable transitions; a round closes and pays once.

Run focused rule/payout tests, deterministic test vectors, fast simulation, and standard statistical simulation before enabling a game. Probability disclosures must match configuration. Animation is skippable and cannot block navigation or alter the outcome. Keyboard, touch, narrow-screen, reduced-motion, large-text, and screen-reader presentation are required.

