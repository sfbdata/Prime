<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Inteligencia\DTO\DocumentoDaQuestaoOutput;
use App\Inteligencia\DTO\MudancaDeStatusDaQuestaoOutput;
use App\Inteligencia\DTO\QuestaoJuridicaNoPainelOutput;
use App\Inteligencia\DTO\VersaoDeDocumentoJuridicoOutput;
use App\Inteligencia\Entity\VersaoDeDocumentoJuridico;
use App\Inteligencia\Enum\DocumentoJuridico;
use App\Inteligencia\Enum\StatusDaQuestaoJuridica;
use App\Inteligencia\Juridico\CatalogoDeQuestoesJuridicas;
use App\Inteligencia\Juridico\QuestaoJuridica;
use App\Inteligencia\Repository\EstadoDaQuestaoJuridicaRepository;
use App\Inteligencia\Repository\MudancaDeStatusDaQuestaoJuridicaRepository;
use App\Inteligencia\Repository\VersaoDeDocumentoJuridicoRepository;

/**
 * O bloco "Questões jurídicas" da aba "Configurações da I.A." do Master: cada questão do catálogo, na ordem dele, com o
 * status (sem linha no banco = pendente), o histórico de status. Os documentos (com as versões ajustadas já enviadas) vêm à parte, em
 * {@see documentos()}. Só leitura; quem chama já exigiu o Master. Poucas consultas, sem N+1.
 */
final class ListarQuestoesJuridicasUseCase
{
    public function __construct(
        private readonly CatalogoDeQuestoesJuridicas $catalogo,
        private readonly EstadoDaQuestaoJuridicaRepository $estados,
        private readonly MudancaDeStatusDaQuestaoJuridicaRepository $historico,
        private readonly VersaoDeDocumentoJuridicoRepository $versoes,
    ) {
    }

    /** @return list<QuestaoJuridicaNoPainelOutput> */
    public function executar(): array
    {
        $questoes = $this->catalogo->todas();
        $estados = $this->estados->todasPorChave();
        $historico = $this->historico->todasPorChave();
        $versoes = $this->versoesPorDocumento($questoes);

        return array_map(static fn (QuestaoJuridica $q): QuestaoJuridicaNoPainelOutput => new QuestaoJuridicaNoPainelOutput(
            $q,
            ($estados[$q->chave] ?? null)?->getStatus() ?? StatusDaQuestaoJuridica::Pendente,
            array_map(MudancaDeStatusDaQuestaoOutput::daLinha(...), $historico[$q->chave] ?? []),
        ), $questoes);
    }

    /**
     * Os documentos jurídicos (todos os do enum, uma vez cada), cada um com as versões ajustadas já enviadas: o bloco
     * único no fim da página. Independe das questões, então ocultar uma questão não tira documento daqui.
     *
     * @return list<DocumentoDaQuestaoOutput>
     */
    public function documentos(): array
    {
        $versoes = $this->versoesPorDocumento($this->catalogo->todas());

        return array_map(
            static fn (DocumentoJuridico $d): DocumentoDaQuestaoOutput => new DocumentoDaQuestaoOutput($d, $versoes[$d->value] ?? []),
            DocumentoJuridico::cases(),
        );
    }

    /**
     * @param list<QuestaoJuridica> $questoes
     *
     * @return array<string, list<VersaoDeDocumentoJuridicoOutput>>
     */
    private function versoesPorDocumento(array $questoes): array
    {
        $titulos = [];
        foreach ($questoes as $q) {
            $titulos[$q->chave] = $q->titulo;
        }

        return array_map(
            static fn (array $lista): array => array_map(
                static fn (VersaoDeDocumentoJuridico $v): VersaoDeDocumentoJuridicoOutput => VersaoDeDocumentoJuridicoOutput::daLinha($v, $titulos[$v->getQuestao() ?? ''] ?? null),
                $lista,
            ),
            $this->versoes->todasPorDocumento(),
        );
    }
}
