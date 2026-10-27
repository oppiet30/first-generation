# Descendant families

Apply `database/migrations/004_partnerships.sql` after migrations 001–003, then apply `005_relationship_settings.sql`.

At day 1, the founders form a partnership. Every 30 days, unpartnered adults are paired deterministically in person-ID order. Partners must be alive and at least 18; women may conceive through age 45, men through age 65. A person can be in only one active partnership through the simulation engine. Direct parent-child pairings are prohibited by default; siblings are permitted to reproduce in this fictional two-founder population. A per-world setting can optionally permit adult parent-child pairings. Genetic effects are not yet modeled.

A 1% daily conception check applies to eligible couples who are not already pregnant and have not conceived in the last 365 days. Gestation remains 280 days. No immigrants or additional founders are introduced.

**Limitations:** This is a simplified simulation rule, not a historical or biological claim. There is no relationship dissolution, infertility, miscarriage, mortality, or genetic inheritance yet. The deterministic pairing policy may leave some adults unpaired.

## Optional relationship setting

The default is disabled for every world, including newly created worlds. To explicitly allow parent-adult-child partnerships in world 1:

```sql
INSERT INTO world_relationship_settings(world_id,allow_parent_child_partnerships)
VALUES (1,1)
ON DUPLICATE KEY UPDATE allow_parent_child_partnerships=VALUES(allow_parent_child_partnerships);
```

Set the value back to `0` to disable new parent-child pairings. Existing partnerships are not retroactively dissolved. This setting affects pairing only; adult age requirements remain enforced.
