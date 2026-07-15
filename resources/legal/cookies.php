<?php
return [
 'title'=>'Cookie Policy','version'=>'draft-1','effective_date'=>'{{EFFECTIVE_DATE}}','summary'=>'How {{SITE_NAME}} uses cookies and similar local browser storage.','important_notice'=>'Strictly necessary storage supports security and requested features. Optional analytics or advertising storage must stay off until enabled with legally appropriate consent.',
 'sections'=>[
  ['heading'=>'1. What browser storage means','paragraphs'=>['Cookies are small values a website asks a browser to store. Local and session storage offer similar device-local storage. They do not give us access to unrelated files on your device.']],
  ['heading'=>'2. Strictly necessary storage','items'=>['Secure session identifier and CSRF protection.','Authentication, account security, rate-limiting, and load-balancing values where required.','Age-confirmation timestamp and policy version.','Minimal local guest balance and progress before account conversion.','Consent and privacy-choice records.']],
  ['heading'=>'3. Preference storage','paragraphs'=>['Theme, appearance, sound volume, animation, particles, reduced motion, large text, compact mode, contrast, colorblind suit settings, wager confirmations, and session reminders may be saved on the device. Signed-in users may also save account preferences on the server.']],
  ['heading'=>'4. Analytics and advertising','paragraphs'=>['Privacy-conscious first-party aggregate analytics may measure page views, launches, completed rounds, error rates, conversions, feature usage, broad device category, and performance. We do not fingerprint users or collect precise location. Advertising technology stays disabled until approved and configured with any required consent.']],
  ['heading'=>'5. Your controls','paragraphs'=>['Use the site consent controls to change optional choices. Browser controls can clear storage, but blocking strictly necessary storage may prevent login, security, guest progress, or checkout from working.']],
  ['heading'=>'6. Storage register','paragraphs'=>['Before launch, the Adult Owner must maintain a current register naming each cookie or storage key, provider, purpose, type, duration, and whether consent is required. Provider SDK storage must be documented when those providers are enabled.']],
 ],
];
