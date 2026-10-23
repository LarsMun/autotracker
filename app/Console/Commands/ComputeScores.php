<?php

namespace App\Console\Commands;

use App\Recommend\Scores;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('auto:scores')]
#[Description('Compute market factors, deal scores, match scores and today\'s ranking for the cars online now')]
class ComputeScores extends Command
{
    public function handle(Scores $scores): int
    {
        $result = $scores->run();

        $this->info(sprintf(
            '%d auto\'s, %d factorsets, %d met dealscore, %d door de harde eisen.',
            $result['cars'], $result['factors'], $result['with_deal'], $result['passing'],
        ));

        return self::SUCCESS;
    }
}
