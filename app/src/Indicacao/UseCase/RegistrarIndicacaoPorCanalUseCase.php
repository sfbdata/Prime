<?php

declare(strict_types=1);

namespace App\Indicacao\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Indicacao\Entity\Indicacao;
use App\Indicacao\Enum\CanalDeIndicacao;
use App\Indicacao\Exception\IndicacaoRecusada;
use App\Indicacao\Service\RegistroDeIndicacao;
use Psr\Clock\ClockInterface;

/**
 * "WhatsApp" e "Copiar link curto" (spec `indicar-bluejus.md`, comportamentos 5 e 6; bj-contas.js L131-132): registra
 * a indicação do canal (conta nos limites como o e-mail) e devolve o texto do desenho. Quem abre o WhatsApp e copia o
 * link é a tela, só depois do sim daqui.
 */
final class RegistrarIndicacaoPorCanalUseCase
{
    public function __construct(
        private readonly RegistroDeIndicacao $registro,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** Devolve a mensagem de sucesso; recusa com {@see IndicacaoRecusada} e o texto do desenho. */
    public function executar(CanalDeIndicacao $canal, User $user, ?Tenant $escritorio): string
    {
        if ($canal === CanalDeIndicacao::Email) {
            throw new \InvalidArgumentException('O convite por e-mail tem caminho próprio.');
        }

        return $this->registro->comTrava($user, function () use ($canal, $user, $escritorio): string {
            $this->registro->gravar(Indicacao::porCanal($user, $escritorio, $canal, $this->relogio->now()), $user, $escritorio);

            return $canal === CanalDeIndicacao::Whatsapp ? 'Mensagem aberta no WhatsApp.' : 'Link copiado.';
        });
    }
}
