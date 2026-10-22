<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\DiasUteisDoEscritorio;
use App\Tarefa\DTO\SugestoesDaMetaOutput;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\NaturezaDoDespacho;
use App\Tarefa\Repository\AprendizadoDaPendenciaRepository;
use App\Tarefa\Repository\AprendizadoDoDespachoRepository;
use App\Tarefa\Service\PreferenciaDoAprendizado;
use App\Tarefa\Service\SugestoesAprendidas;

/**
 * As sugestões do aprendizado para quem abre a meta (desenho 04B de 22/10, L61, L99 e L151-158; spec
 * `minhas-metas-esteira-2210.md`, 1.1 itens 1 e 2, C2, D4 e Fase 4). As contas são as do protótipo
 * ({@see SugestoesAprendidas}); aqui se lê só o que é da própria pessoa no escritório da sessão, e nada com a chave
 * do Meu Perfil desligada.
 *
 * - `doDespacho`: frases por natureza, natureza preferida e prazo usual (o prazo vira data pela regra do escritório).
 * - `pedidos`: o "Você costuma pedir" do modal de passagem, por etapa destino.
 */
final class MontarSugestoesDaMetaUseCase
{
    public const PRAZO_PADRAO = MontarDespachosDaMetaUseCase::UTEIS_DO_PRAZO_PADRAO;

    public function __construct(
        private readonly AprendizadoDoDespachoRepository $despachos,
        private readonly AprendizadoDaPendenciaRepository $pendencias,
        private readonly PreferenciaDoAprendizado $preferencia,
        private readonly DiasUteisDoEscritorio $diasUteis,
    ) {
    }

    public function doDespacho(Tarefa $meta, User $quemVe, Tenant $escritorio, \DateTimeImmutable $hoje): SugestoesDaMetaOutput
    {
        if ($meta->getTenant()?->getId() !== $escritorio->getId() || !$this->preferencia->ligado($escritorio, $quemVe)) {
            return SugestoesDaMetaOutput::nenhuma();
        }
        $registros = $this->despachos->doUsuario($escritorio, $quemVe, AprenderComAMetaUseCase::LIMITE_DE_DESPACHOS);
        if ($registros === []) {
            return SugestoesDaMetaOutput::nenhuma();
        }
        $etapa = $meta->getEtapa();
        $frases = ['' => SugestoesAprendidas::frases($registros, $etapa, null)];
        foreach (NaturezaDoDespacho::cases() as $natureza) {
            $frases[$natureza->value] = SugestoesAprendidas::frases($registros, $etapa, $natureza);
        }
        $preferida = SugestoesAprendidas::naturezaPreferida($registros, $etapa);
        $usual = SugestoesAprendidas::prazoUsual($registros, $etapa);
        $prazo = $usual > 0 && $usual !== self::PRAZO_PADRAO
            ? $this->diasUteis->somarUteis($hoje, $usual, $escritorio->getEstado())->format('Y-m-d')
            : null;

        return new SugestoesDaMetaOutput($frases, $preferida?->value, $usual, $prazo, $preferida !== null || $usual > 0);
    }

    /** @return array<string, list<string>> etapa destino => as 5 linhas que a pessoa mais pede para ela */
    public function pedidos(User $quemVe, Tenant $escritorio): array
    {
        if (!$this->preferencia->ligado($escritorio, $quemVe)) {
            return [];
        }
        $registros = $this->pendencias->doUsuario($escritorio, $quemVe, AprenderComAMetaUseCase::LIMITE_DE_PENDENCIAS);
        $porEtapa = [];
        foreach (EtapaDaPasta::cases() as $etapa) {
            $pedidos = SugestoesAprendidas::pedidos($registros, $etapa);
            if ($pedidos !== []) {
                $porEtapa[$etapa->value] = $pedidos;
            }
        }

        return $porEtapa;
    }
}
