# ADR 0013 — Named season rankings and distinct team-quiz answers

2026-10-02. Requested by the owner after production acceptance of PR72
`971be902c4554539650aafc39e81fbc61354de7b`.

Supersedes only ADR0011's employee-ranking pseudonym policy. Active participants
see current real names of eligible members of their own published, current season;
their own name carries a separate «Вы» marker. Operator authorization, the frozen
audience, employment/account filtering, shared places and score calculations stay
unchanged. Existing seasons render names immediately, including those whose legacy
privacy metadata still says `pseudonymous`. New drafts record `named`. Historical
participant labels and score/result rows are retained; there is no data migration.
No public ranking endpoint is introduced.

The «Кто есть кто?» quiz continues to use only the people visible in team_presenter.
Equivalent position labels (case, whitespace, dots and е/ё) produce one answer choice.
The selected people's labels take precedence over catalogue distractors. Answers are
compared by the same label key, so every employee with an equivalent canonical
position ID can receive a correct answer. Compound/deputy positions remain distinct.
Position IDs, staff places, assignments and learning history are never merged or
rewritten by this presentation fix. Native completion still requires at least 80%.

Regression coverage: named current-season reads, self marker, shared ranks,
nonmember/left/suspended boundaries, equivalent answers and distinct distractors.
Deployment and actual signed-in acceptance are separate evidence.
