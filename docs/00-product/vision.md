# Product Vision

**Status:** Approved (G0, 2026-11-16)

## Vision

A fast, accessible, secure personal todo list. Signed-in users manage their own tasks,
and nobody else can see them. The app is also the **reference implementation** of our AI-first
engineering framework, so the way it is built matters as much as what it does.

## Goals

1. Users can capture, complete, and organise tasks with as little friction as possible.
2. Each user's data is strictly isolated, and this is proven by tests rather than assumed.
3. The project shows a repeatable process (gates, agents, deterministic validation) that can be reused on larger Laravel and Vue apps.

## Non-goals (for the MVP)

Collaboration and sharing, tags or projects, reminders and notifications, drag-and-drop ordering,
priorities, a mobile app or public API, offline support.

## Success measures

| Type | Measure | Target |
|---|---|---|
| Product | Must-have stories delivered and accepted | 100% |
| Product | WCAG 2.2 AA checks on core journeys | Pass |
| Quality | Must AC covered by automated tests (traceability matrix) | 100% |
| Quality | Production defects in the first 30 days | 0 severity-1 |
| Process | Merges or deploys that bypassed a gate | 0 |
| Process | AI-authored PRs merged without needing rework from the human | Tracked as a baseline, no target yet |
