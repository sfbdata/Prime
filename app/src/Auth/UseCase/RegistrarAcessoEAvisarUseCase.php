<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\RegistrarAcessoInput;
use App\Auth\Repository\AcessoConhecidoRepository;
use App\Auth\Service\AvisoDeAcessoNovoMailerInterface;
use App\Auth\Service\ImpressaoDoAcesso;
use App\Auth\Service\NavegadorDoAcesso;
use App\Shared\Contract\ConfiguracaoDaPlataforma;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Registra o acesso de quem acabou de entrar e avisa por e-mail quando é um acesso novo (spec `login-aviso-novo.md`).
 *
 * Quem dispara: o login interativo (formulário, Google, Facebook). Regras: sem nenhum acesso conhecido é o primeiro
 * login e só grava; impressão conhecida só renova `ultimo_em`; impressão nova de quem já tinha acessos grava e avisa,
 * salvo se o Master desligou o aviso (`avisoLoginNovo` = off; falha do leitor vale como on).
 *
 * NUNCA derruba o login: qualquer Throwable (banco, e-mail, configuração) é logado e engolido. O e-mail vem DEPOIS da
 * gravação, então uma falha do envio não repete o aviso no login seguinte.
 */
final class RegistrarAcessoEAvisarUseCase
{
    public const CHAVE_AVISO = 'avisoLoginNovo';

    public function __construct(
        private readonly AcessoConhecidoRepository $repository,
        private readonly ImpressaoDoAcesso $impressao,
        private readonly AvisoDeAcessoNovoMailerInterface $mailer,
        private readonly ConfiguracaoDaPlataforma $configuracao,
        private readonly ClockInterface $relogio,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function executar(RegistrarAcessoInput $input): void
    {
        try {
            $navegador = NavegadorDoAcesso::deUserAgent($input->userAgent);
            $agora = $this->relogio->now();

            $tinhaAcessos = $this->repository->usuarioTemAcessos($input->user);
            $nova = $this->repository->registrar($input->user, $this->impressao->calcular($navegador, $input->ip), $agora);

            if (!$nova || !$tinhaAcessos || !$this->avisoLigado()) {
                return;
            }

            $this->mailer->avisar($input->user, $this->noFusoDoSistema($agora), $navegador);
        } catch (\Throwable $e) {
            $this->logger->error('Aviso de acesso novo: falhou, o login segue.', ['exception' => $e]);
        }
    }

    private function avisoLigado(): bool
    {
        try {
            return $this->configuracao->valor(self::CHAVE_AVISO) !== 'off';
        } catch (\Throwable) {
            return true;
        }
    }

    private function noFusoDoSistema(\DateTimeImmutable $momento): \DateTimeImmutable
    {
        try {
            $fuso = $this->configuracao->valor('fuso');

            return $momento->setTimezone(new \DateTimeZone($fuso !== '' ? $fuso : 'America/Sao_Paulo'));
        } catch (\Throwable) {
            return $momento->setTimezone(new \DateTimeZone('America/Sao_Paulo'));
        }
    }
}
