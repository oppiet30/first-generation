# Descendant families

Apply `database/migrations/004_partnerships.sql` after migrations 001–003.

At day 1, the founders form a partnership. Every 30 days, unpartnered adults are paired deterministically in person-ID order. Partners must be alive and at least 18; women may conceive through age 45, men through age 65. A person can be in only one active partnership through the simulation engine. Direct parent-child pairings are prohibited; siblings are permitted to reproduce in this fictional two-founder population. Genetic effects are not yet modeled.

A 1% daily conception check applies to eligible couples who are not already pregnant and have not conceived in the last 365 days. Gestation remains 280 days. No immigrants or additional founders are introduced.

**Limitations:** This is a simplified simulation rule, not a historical or biological claim. There is no relationship dissolution, infertility, miscarriage, mortality, or genetic inheritance yet. The deterministic pairing policy may leave some adults unpaired.
