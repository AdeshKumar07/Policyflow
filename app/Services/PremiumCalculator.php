<?php

namespace App\Services;

use Carbon\Carbon;

class PremiumCalculator
{
    public function calculate(
        string $dateOfBirth,
        string $tripStart,
        string $tripEnd,
        string $planCode,
        string $destination,
        int $sumInsured
    ): float {
        // 1. Daily rate based on plan
        $dailyRates = [
            'BASIC' => 12,
            'PLUS' => 20,
            'PREMIUM' => 30,
        ];

        $dailyRate = $dailyRates[$planCode];

        // 2. Calculate age
        $dob = Carbon::parse($dateOfBirth);
        $tripStartDate = Carbon::parse($tripStart);

        $age = $dob->diffInYears($tripStartDate);

        // 3. Age multiplier
        if ($age <= 17) {
            $ageMultiplier = 1.10;
        } elseif ($age <= 60) {
            $ageMultiplier = 1.00;
        } elseif ($age <= 70) {
            $ageMultiplier = 1.50;
        } else {
            $ageMultiplier = 2.00;
        }

        // 4. Destination multiplier
        $destinationMultiplier = $destination === 'INTERNATIONAL'
            ? 1.80
            : 1.00;

        // 5. Coverage multiplier
        $coverageMultipliers = [
            100000 => 1.00,
            300000 => 1.25,
            500000 => 1.50,
        ];

        $coverageMultiplier = $coverageMultipliers[$sumInsured];

        // 6. Inclusive travel days
        $travelDays = $tripStartDate->diffInDays(
            Carbon::parse($tripEnd)
        ) + 1;

        // 7. Final premium
        $premium = $dailyRate
            * $travelDays
            * $ageMultiplier
            * $destinationMultiplier
            * $coverageMultiplier;

        return round($premium, 2);
    }
}