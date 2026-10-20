<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Entity\Auth\User;
use App\Repository\UserRepository;
use App\TrabalhoExterno\Repository\ConviteDemandaRepository;
use App\TrabalhoExterno\Service\ConviteDisponivel;

/**
 * O que a página do convite mostra (tabela do §8 da spec): indisponível, criar conta, entrar,
 * outro e-mail ou aceitar. Consulta: não grava nada. Só expõe o serviço, o local e o e-mail
 * convidado, nunca o escritório nem o conteúdo da demanda.
 */
final class AbrirConviteUseCase
{
    public function __construct(
        private readonly ConviteDemandaRepository $convites,
        private readonly UserRepository $usuarios,
        private readonly ConviteDisponivel $disponivel,
    ) {
    }

    /** @return array{estado: string, convite: ?array<string, string>} */
    public function executar(string $token, ?User $logado): array
    {
        $convite = $this->convites->peloToken($token);
        if ($convite === null || !$this->disponivel->para($convite, new \DateTimeImmutable('today'))) {
            return ['estado' => 'indisponivel', 'convite' => null];
        }

        $d = $convite->getDemanda();
        $dados = [
            'nome' => $convite->getNome(),
            'email' => $convite->getEmail(),
            'validade' => $convite->getValidade()->format('d/m/Y'),
            'servico' => $d->getTipoServico(),
            'local' => $d->getCidadeExecucao() . '/' . $d->getUfExecucao(),
        ];

        $estado = match (true) {
            $logado !== null && !$convite->destinadoA($logado) => 'outro_email',
            $logado !== null => 'aceitar',
            $this->usuarios->encontrarPorEmailIgnorandoCaixa($convite->getEmail()) !== [] => 'entrar',
            default => 'criar_conta',
        };

        return ['estado' => $estado, 'convite' => $dados];
    }
}
