<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Inteligencia\Enum\StatusDaQuestaoJuridica;
use App\Inteligencia\Exception\QuestaoJuridicaNaoEncontradaException;
use App\Inteligencia\Juridico\CatalogoDeQuestoesJuridicas;
use App\Inteligencia\Repository\EstadoDaQuestaoJuridicaRepository;
use App\Inteligencia\Service\GuardaDoMasterDaIa;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * O Master marca uma questão jurídica como pendente, em análise ou resolvida, e o sistema guarda quem mudou e quando
 * (linha do histórico + auditoria no escopo de plataforma). Questão fora do catálogo é 404; o mesmo status não grava
 * nada. Com todas resolvidas, o aviso do topo some.
 */
final class AlterarStatusDaQuestaoJuridicaUseCase
{
    public function __construct(
        private readonly CatalogoDeQuestoesJuridicas $catalogo,
        private readonly EstadoDaQuestaoJuridicaRepository $estados,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(string $chave, StatusDaQuestaoJuridica $novo, User $master): void
    {
        GuardaDoMasterDaIa::exigir($master);
        if ($this->catalogo->porChave($chave) === null) {
            throw new QuestaoJuridicaNaoEncontradaException($chave);
        }
        $agora = $this->relogio->now();
        $estado = $this->estados->obterParaAlterar($chave, $agora);
        $mudanca = $estado->mudarPara($novo, $master, $agora);
        if ($mudanca === null) {
            return;
        }
        // Questão da plataforma: a linha de auditoria fica sem escritório.
        $this->escopoDePlataforma->executar(fn () => $this->estados->salvarMudanca($estado, $mudanca));
    }
}
