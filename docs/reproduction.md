# Reproduction and childbirth

Migration 003 adds pregnancies. Each running tick processes due births, then attempts conception for Adam and Eve. A pregnancy lasts 280 simulation days. The deterministic world RNG controls conception (1% per eligible day) and sex at birth. Conception requires both founders alive, Eve aged 18–45, Adam at least 18, no current pregnancy, and at least 365 days since the previous conception. Children receive both parent IDs and are born at age zero. Names are deterministic placeholders.

This first version intentionally restricts reproduction to the founding couple. Descendant partnerships, genetics, pregnancy complications, mortality, and infant survival are future milestones.

Apply migrations 001, 002, and 003 in order for new databases. For existing installations, apply only migration 003.
