<?php

declare(strict_types=1);

namespace App\Command;

use App\Shared\Email\IdentidadeDeEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[AsCommand(
    name: 'app:test-mailer',
    description: 'Envia um email de teste para verificar configuração do Mailer',
)]
final class TestMailerCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly IdentidadeDeEmail $identidadeDeEmail,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'destinatario',
            InputArgument::REQUIRED,
            'Endereço que receberá o e-mail de teste',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (new Email())
            ->to((string) $input->getArgument('destinatario'))
            ->text('Este é um email de teste enviado pelo Symfony Mailer.');
        $this->identidadeDeEmail->aplicar($email, 'Teste de Mailer Symfony');

        $this->mailer->send($email);

        $output->writeln('Email de teste enviado com sucesso!');

        return Command::SUCCESS;
    }
}
