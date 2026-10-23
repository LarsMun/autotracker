<?php

namespace App\Deals;

/**
 * Ordinary least squares for price = a + b·age_months + c·km, solved from the normal equations.
 */
final class Regression
{
    /**
     * @param  list<array{0: float|int, 1: float|int, 2: float|int}>  $rows  [age in months, km, price]
     * @return array{a: float, b: float, c: float, n: int, r2: float}|null null when the data cannot determine it
     */
    public static function fit(array $rows): ?array
    {
        $n = count($rows);

        if ($n < 3) {
            return null;
        }

        // X'X and X'y for the columns [1, age, km].
        $xtx = array_fill(0, 3, array_fill(0, 3, 0.0));
        $xty = [0.0, 0.0, 0.0];

        foreach ($rows as [$age, $km, $price]) {
            $x = [1.0, (float) $age, (float) $km];

            for ($i = 0; $i < 3; $i++) {
                $xty[$i] += $x[$i] * $price;

                for ($j = 0; $j < 3; $j++) {
                    $xtx[$i][$j] += $x[$i] * $x[$j];
                }
            }
        }

        $beta = self::solve($xtx, $xty);

        if ($beta === null) {
            return null;
        }

        [$a, $b, $c] = $beta;
        $mean = array_sum(array_column($rows, 2)) / $n;
        $total = $residual = 0.0;

        foreach ($rows as [$age, $km, $price]) {
            $total += ($price - $mean) ** 2;
            $residual += ($price - ($a + $b * $age + $c * $km)) ** 2;
        }

        return ['a' => $a, 'b' => $b, 'c' => $c, 'n' => $n, 'r2' => $total > 0 ? 1 - $residual / $total : 0.0];
    }

    /**
     * Gaussian elimination with partial pivoting; null when the system is singular (e.g. every
     * car the same age).
     *
     * @param  list<list<float>>  $m
     * @param  list<float>  $v
     * @return list<float>|null
     */
    private static function solve(array $m, array $v): ?array
    {
        $size = count($v);

        for ($col = 0; $col < $size; $col++) {
            $pivot = $col;

            for ($row = $col + 1; $row < $size; $row++) {
                if (abs($m[$row][$col]) > abs($m[$pivot][$col])) {
                    $pivot = $row;
                }
            }

            // Relative to the column's scale: km values make the matrix entries large.
            $scale = max(1.0, ...array_map(fn ($r) => abs($r[$col]), $m));

            if (abs($m[$pivot][$col]) < 1e-9 * $scale) {
                return null;
            }

            [$m[$col], $m[$pivot]] = [$m[$pivot], $m[$col]];
            [$v[$col], $v[$pivot]] = [$v[$pivot], $v[$col]];

            for ($row = $col + 1; $row < $size; $row++) {
                $f = $m[$row][$col] / $m[$col][$col];

                for ($k = $col; $k < $size; $k++) {
                    $m[$row][$k] -= $f * $m[$col][$k];
                }

                $v[$row] -= $f * $v[$col];
            }
        }

        $x = array_fill(0, $size, 0.0);

        for ($row = $size - 1; $row >= 0; $row--) {
            $sum = $v[$row];

            for ($k = $row + 1; $k < $size; $k++) {
                $sum -= $m[$row][$k] * $x[$k];
            }

            $x[$row] = $sum / $m[$row][$row];
        }

        return $x;
    }
}
