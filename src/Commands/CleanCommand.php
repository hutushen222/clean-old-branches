<?php

namespace MilkyThinking\CleanOldBranches\Commands;

use Carbon\Carbon;
use GitElephant\Exception\InvalidRepositoryPathException;
use GitElephant\Objects\Branch;
use GitElephant\Objects\Remote;
use GitElephant\Repository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

class CleanCommand extends Command
{
    /**
     * @var Repository
     */
    private $repo;

    /**
     * Local or Remote
     *
     * @var string
     */
    private $repoMode;

    /**
     * @var string
     */
    private $remoteName;

    /**
     * @var Remote
     */
    private $remote;

    /**
     * @var int
     */
    private $days;

    /**
     * @var bool
     */
    private $dryRun;

    /**
     * @var array of reserved branches
     */
    private $reservedBranches = [
        'master',
        'develop',
    ];

    protected function configure()
    {
        $this->setName('clean')
            ->setDescription('Clean old branches')
            ->addArgument('repo', InputArgument::OPTIONAL, 'The repository path')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dry run');
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if (!$input->getArgument('repo')) {
            $input->setArgument('repo', getcwd());
        }

        $this->dryRun = $input->getOption('dry-run');

        try {
            $this->repo = Repository::open($input->getArgument('repo'));
        } catch (InvalidRepositoryPathException $e) {
            // TODO output error message and exit
            throw $e;
        }
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        if (is_null($this->repoMode)) {
            $this->interactRepoMode($input, $output);
        }

        if ($this->isRemote()) {
            $this->interactRemote($input, $output);
        }

        $this->interactDays($input, $output);
    }

    private function interactRepoMode(InputInterface $input, OutputInterface $output)
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $question = new ChoiceQuestion('Choose repository mode:', ['remote', 'local'], 0);
        $this->repoMode = $helper->ask($input, $output, $question);
    }

    private function isRemote()
    {
        return $this->repoMode === 'remote';
    }

    private function interactRemote(InputInterface $input, OutputInterface $output)
    {
        $chosen = null;

        $output->writeln('Query remotes ...');
        $remotes = $this->repo->getRemotes();

        if (empty($remotes)) {
            /** @var FormatterHelper $formatter */
            $formatter = $this->getHelper('formatter');
            $formattedBlock = $formatter->formatBlock([
                '',
                'Warning!',
                '-------',
                'This repo has no remote repository.',
                ''
            ], 'error');
            $output->writeln($formattedBlock);
        }

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $remoteNames = array_map(function ($remote) { return $remote->getName(); }, $remotes);
        $question = new ChoiceQuestion('Select a remote:', $remoteNames, 0);
        $this->remoteName = $helper->ask($input, $output, $question);

        /** @var Remote $remote */
        foreach ($remotes as $remote) {
            if ($remote->getName() == $this->remoteName) {
                $this->remote = $remote;
                break;
            }
        }
    }

    protected function interactDays(InputInterface $input, OutputInterface $output)
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $question = new ChoiceQuestion('Choose last commit before days:', [30, 45, 60], 0);
        $this->days = $helper->ask($input, $output, $question);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->executeBefore($input, $output);

        if ($this->isRemote()) {
            $this->executeRemote($input, $output);
        } else {
            $this->executeLocal($input, $output);
        }

        $this->executeAfter($input, $output);
    }

    protected function executeBefore(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Start...');

        if ($this->isRemote()) {
            $output->writeln(sprintf('  ==> Fetch %s...', $this->remoteName));
            $this->repo->fetch($this->remoteName);
        }
    }

    protected function executeAfter(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Done.');
    }

    private function executeLocal(InputInterface $input, OutputInterface $output)
    {
        $branches = $this->repo->getBranches(true);

        foreach ($branches as $branch) {
            $this->deleteBranch($input, $output, $branch);
        }
    }

    private function executeRemote(InputInterface $input, OutputInterface $output)
    {
        $remoteBranches = $this->remote->getBranches();

        /** @var Branch $remoteBranch */
        foreach ($remoteBranches as $remoteBranchName => $remoteBranch) {
            $this->deleteBranch($input, $output, $remoteBranchName);
        }
    }

    private function deleteBranch(InputInterface $input, OutputInterface $output, $branch)
    {
        $branchFull = $this->isRemote() ? $this->remoteName . '/' . $branch : $branch;

        if ($this->isReservedBranch($branch)) {
            $output->writeln(sprintf('  ==> "%s" is reserved.', $branchFull));
            return;
        }

        if ($this->repo->getMainBranch()->getName() == $branch) {
            $output->writeln(sprintf('  ==> "%s" is skipped (current branch).', $branchFull));
            return;
        }

        $lastCommit = $this->getLastCommit($branchFull);
        $lastCommitAt = Carbon::instance($lastCommit->getDatetimeAuthor());

        if ($this->isBeforeDays($lastCommitAt)) {
            if (!$this->dryRun) {
                if ($this->isRemote()) {
                    $this->repo->push($this->remoteName, ':' . $branch);
                } else {
                    $this->repo->deleteBranch($branch, true);
                }
            }
            $output->writeln(sprintf('  ==> "%s" is deleted (last commit at: "%s").',
                $branchFull,
                $lastCommitAt->format('Y-m-d H:i:s')));
        } else {
            $output->writeln(sprintf('  ==> "%s" is skipped (last commit at: "%s").',
                $branchFull,
                $lastCommitAt->format('Y-m-d H:i:s')));
        }
    }

    private function isReservedBranch($branch)
    {
        return in_array($branch, $this->reservedBranches, true);
    }

    private function getLastCommit($branch)
    {
        return $this->repo->getLog($branch, null, 1)->first();
    }

    private function isBeforeDays(Carbon $date)
    {
        $now = Carbon::now();

        return $now->greaterThan($date) && $now->diffInDays($date) > $this->days;
    }
}
