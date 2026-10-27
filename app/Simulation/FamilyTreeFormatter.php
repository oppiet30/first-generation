<?php
declare(strict_types=1);
namespace FirstGeneration\Simulation;

final class FamilyTreeFormatter
{
    public static function ageAndStatus(array $person): string
    {
        if ($person['death_day'] === null) {
            return sprintf('age %d years, living', $person['age_years']);
        }
        $age = $person['age_days'] < 365
            ? sprintf('%d %s', $person['age_days'], $person['age_days'] === 1 ? 'day' : 'days')
            : sprintf('%d %s', $person['age_years'], $person['age_years'] === 1 ? 'year' : 'years');
        return sprintf('deceased, age at death %s, death day %d, cause of death: %s',
            $age, $person['death_day'], $person['death_cause'] ?? 'unknown');
    }
}
