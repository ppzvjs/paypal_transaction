<?php

namespace App\Command;

use App\Entity\Transactions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'db:clear',
    description: 'Add a short description for your command',
)]
class DbClearCommand extends Command
{
    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Clear Database');

        $transactions = $this->em->getRepository(Transactions::class)->findAll();

        foreach($transactions as $transaction){
            $this->em->remove($transaction);
        }

        $this->em->flush();

        $io->success('Database cleared');

        return Command::SUCCESS;
    }
}
