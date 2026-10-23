<?php

namespace App\Console\Commands;

use App\Recommend\Scores;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('auto:scores')]
#[Description('Compute market factors, deal scores and the rapportcijfer for the cars online now')]
class ComputeScores extends Command
{
    public function handle(Scores $scores): int
    {
        $result = $scores->run();

        $this->info(sprintf(
            '%d auto\'s, %d factorsets, %d met dealscore, %d met cijfer.',
            $result['cars'], $result['factors'], $result['with_deal'], $result['with_cijfer'],
        ));

        return self::SUCCESS;
    }
}
