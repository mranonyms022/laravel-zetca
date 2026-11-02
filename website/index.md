---
layout: home

hero:
  name: Laravel ZATCA
  text: Saudi e-invoicing (Fatoora Phase 2) for Laravel
  tagline: Issue simplified and standard invoices, report and clear them with ZATCA, and handle rejections, corrections and credit notes, with a database-backed audit trail.
  actions:
    - theme: brand
      text: Get started
      link: /guide/introduction
    - theme: alt
      text: Quick start (sandbox)
      link: /guide/quick-start
    - theme: alt
      text: GitHub
      link: https://github.com/mranonyms022/laravel-zetca

features:
  - title: B2C in milliseconds
    details: "Zatca::b2c()->issue() validates, signs and returns the QR code and counter without waiting for ZATCA. Reporting runs on a queue, with retries inside the 24-hour deadline."
  - title: B2B clearance
    details: Standard invoices are cleared through ZATCA's clearance API. The cleared XML stamped by ZATCA is stored, and only that version is meant for the buyer.
  - title: Safe invoice chain
    details: The counter (ICV) and previous hash (PIH) are allocated under database locks. Unique keys make duplicate counters and chain forks impossible, even across many servers.
  - title: Rejections handled properly
    details: Retry the identical document, correct and reissue on the same record (new UUID and counter), or cancel accepted invoices with credit and debit notes, as ZATCA requires.
  - title: Devices and certificates
    details: Onboard devices with an OTP, run compliance checks, store keys encrypted, and get warned before certificates expire.
  - title: Built for operations
    details: Transactional outbox, circuit breaker, deadline alerts, chain verification, error grouping, events for every outcome, and an optional HTTP API with load shedding.
---

::: warning Status
The package is under active development (version 0.5, milestone M6 of 8). Issuing, reporting, clearance,
corrections and credit/debit notes work end to end in ZATCA's developer sandbox. The management dashboard
follows in M7. **Do not use it in production before version 1.0.**
:::
