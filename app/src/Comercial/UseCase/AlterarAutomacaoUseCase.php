<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\DTO\EstadoDaAutomacao;
use App\Comercial\Entity\AutomacaoDeEmail;
use App\Comercial\Enum\Automacao;
use App\Comercial\Exception\AutomacaoRecusada;
use App\Comercial\Repository\AutomacaoDeEmailRepository;
use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * O Master liga, desliga ou muda o atraso de uma automação (desenho 05 L.262-268; spec comercial-e-emails.md §3.5).
 * O `cad` é obrigatório e não desliga (P2). O atraso só vale entre as opções do dc. Auditado pela entidade.
 */
final class AlterarAutomacaoUseCase
{
    public function __construct(
        private readonly AutomacaoDeEmailRepository $automacoes,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function executar(string $chave, bool $ligada, ?string $atraso, User $por): EstadoDaAutomacao
    {
        $automacao = Automacao::tryFrom($chave) ?? throw new AutomacaoRecusada('Automação desconhecida.');
        $this->exigirAlteracaoValida($automacao, $ligada, $atraso);
        $entidade = $this->automacoes->find($automacao->value);
        if (!$entidade instanceof AutomacaoDeEmail) {
            throw new AutomacaoRecusada('Automação desconhecida.');
        }
        $entidade->alterar($ligada, $automacao->opcoesDeAtraso() === [] ? null : $atraso, $por, $this->clock->now());
        $this->em->flush();

        return $this->automacoes->estado($automacao);
    }

    private function exigirAlteracaoValida(Automacao $automacao, bool $ligada, ?string $atraso): void
    {
        if ($automacao->obrigatoria() && !$ligada) {
            throw new AutomacaoRecusada('Esta automação é obrigatória: sem ela ninguém consegue criar conta.');
        }
        $opcoes = $automacao->opcoesDeAtraso();
        if ($opcoes !== [] && !in_array($atraso, $opcoes, true)) {
            throw new AutomacaoRecusada('Escolha um dos atrasos da lista.');
        }
    }
}
