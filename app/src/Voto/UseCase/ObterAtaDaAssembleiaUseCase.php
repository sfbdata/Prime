<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Voto\DTO\AtaDaAssembleia;
use App\Voto\DTO\AvaliacaoNaLista;
use App\Voto\DTO\LinhaDaRelacaoDeVotantes;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OcorrenciaDaUnidade;
use App\Voto\Entity\ParticipacaoNaPauta;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\DTO\OcorrenciaNaVotacao;
use App\Voto\Entity\OcorrenciaDaVotacao;
use App\Voto\Repository\OcorrenciaDaUnidadeRepository;
use App\Voto\Repository\OcorrenciaDaVotacaoRepository;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\AcessoAAssembleia;
use App\Voto\Service\IdentificacaoDaUnidade;
use App\Voto\Service\LeituraDaAssembleia;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A ata imprimível (relatório consolidado do desenho, em HTML): a assembleia lida e, por pauta que saiu da espera, a
 * participação por bloco ou torre (unidades e peso) e, quando cabe, a relação de votantes.
 *
 * A relação de votantes (spec voto-equipe-e-master-1611, seção 2) só entra se TODAS as condições valem: o cenário tem
 * lista (condomínio, associação, sociedade), a pauta está encerrada e a pessoa (`$ator`) pode vê-la
 * ({@see AcessoAAssembleia::podeVerRelacao()}). Sai em ordem alfabética, com a faixa horária no lugar da hora exata e
 * sem o voto de ninguém. Cada emissão grava "Relação de votantes emitida" no `audit_log`. Sem `$ator` (ou com
 * `$comVotantes` falso, a minuta) a relação não é montada nem a lista nominal carregada.
 */
final class ObterAtaDaAssembleiaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly ParticipacaoNaPautaRepository $participacoes,
        private readonly EncerrarPautaPorPrazoVencidoUseCase $encerrarVencidas,
        private readonly LeituraDaAssembleia $leitura,
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly AcessoAAssembleia $acesso,
        private readonly RegistrarAuditoriaDaRelacaoDeVotantesUseCase $auditoria,
        private readonly EntityManagerInterface $em,
        private readonly OcorrenciaDaUnidadeRepository $ocorrencias,
        private readonly OcorrenciaDaVotacaoRepository $ocorrenciasDaVotacao,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, string $emitidaPor, ?\DateTimeImmutable $agora = null, bool $comVotantes = true, ?User $ator = null): AtaDaAssembleia
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $this->encerrarVencidas->executar($assembleia, $agora);
        [$participacoes, $blocos] = $comVotantes ? $this->participacaoPorPauta($assembleia) : [[], []];
        $relacao = $comVotantes && null !== $ator ? $this->relacaoDeVotantes($tenant, $assembleia, $ator, $participacoes) : [];

        $avaliacoes = $comVotantes && null !== $ator ? $this->avaliacoes($assembleia, $ator) : [];

        return new AtaDaAssembleia($this->leitura->montar($assembleia), $agora ?? new \DateTimeImmutable(), $emitidaPor, $relacao, $blocos, $avaliacoes, ...$this->ocorrenciasDosEleitores($assembleia, $ator));
    }

    /**
     * "Ocorrências registradas": o texto e a qualificação do eleitor (dado pessoal, LGPD) só saem para quem gere a assembleia
     * ({@see AcessoAAssembleia::podeRegistrarOcorrencia()}, a mesma regra da lista "Ocorrências"); para os demais, e sem
     * `$ator`, só a quantidade.
     *
     * @return array{0: list<OcorrenciaNaVotacao>, 1: int} o conteúdo (vazio sem permissão) e o total
     */
    private function ocorrenciasDosEleitores(Assembleia $assembleia, ?User $ator): array
    {
        $todas = $this->ocorrenciasDaVotacao->listarDaAssembleia($assembleia);
        if (null === $ator || !$this->acesso->podeRegistrarOcorrencia($ator, $assembleia)) {
            return [[], \count($todas)];
        }

        return [
            array_map(static fn (OcorrenciaDaVotacao $o): OcorrenciaNaVotacao => OcorrenciaNaVotacao::deOcorrencia($o), $todas),
            \count($todas),
        ];
    }

    /**
     * "Ocorrências e avaliações de aptidão" (desenho 18, H297/H401): as ocorrências COM avaliação, para quem vê a relação de
     * votantes ou decide a avaliação (spec voto-avaliacoes-do-conselho-1611, seção 4). Nome da unidade e texto da ocorrência
     * não saem para quem não pode ver nenhuma das duas listas.
     *
     * @return list<AvaliacaoNaLista>
     */
    private function avaliacoes(Assembleia $assembleia, User $ator): array
    {
        if (!$this->acesso->podeVerRelacao($ator, $assembleia) && !$this->acesso->podeVerAvaliacoes($ator, $assembleia)) {
            return [];
        }
        $comAvaliacao = array_filter(
            $this->ocorrencias->listarDaAssembleia($assembleia),
            static fn (OcorrenciaDaUnidade $o): bool => null !== $o->getAvaliacao(),
        );

        return array_values(array_map(AvaliacaoNaLista::deOcorrencia(...), array_reverse($comAvaliacao)));
    }

    /**
     * A relação de votantes das pautas encerradas, por id da pauta; vazio quando o cenário não tem lista ou a pessoa não
     * pode vê-la. Grava a trilha (e só quando saiu alguma relação).
     *
     * @param array<int, list<ParticipacaoNaPauta>> $participacoes por id da pauta
     *
     * @return array<int, list<LinhaDaRelacaoDeVotantes>>
     */
    private function relacaoDeVotantes(Tenant $tenant, Assembleia $assembleia, User $ator, array $participacoes): array
    {
        if (!$assembleia->getCenario()->temListaDeVotantes() || !$this->acesso->podeVerRelacao($ator, $assembleia)) {
            return [];
        }
        $unidades = $this->unidades->listarDaAssembleia($assembleia);
        $relacao = [];
        foreach ($assembleia->getPautas() as $pauta) {
            if (EstadoDaPauta::Encerrada !== $pauta->getEstado() || $pauta->cancelada() || $pauta->retirada()) {
                continue;
            }
            $relacao[(int) $pauta->getId()] = self::linhasDaRelacao($pauta, $unidades, $participacoes[(int) $pauta->getId()] ?? []);
        }
        if ([] !== $relacao) {
            $this->auditoria->emitiu($tenant, $ator, $assembleia, array_keys($relacao));
            $this->em->flush();
        }

        return $relacao;
    }

    /**
     * Uma linha por unidade da pauta (as de bloco fora dos grupos dela não entram), em ordem alfabética do nome.
     *
     * @param list<UnidadeDaAssembleia> $unidades
     * @param list<ParticipacaoNaPauta> $participacoes
     *
     * @return list<LinhaDaRelacaoDeVotantes>
     */
    private static function linhasDaRelacao(PautaDaAssembleia $pauta, array $unidades, array $participacoes): array
    {
        $votouEm = [];
        foreach ($participacoes as $participacao) {
            $votouEm[(int) $participacao->getUnidade()->getId()] = $participacao->getRegistradaEm();
        }
        $repetidas = IdentificacaoDaUnidade::repetidas($unidades);
        $linhas = [];
        foreach ($unidades as $unidade) {
            if (!$pauta->aceitaBloco($unidade->getBloco())) {
                continue;
            }
            $em = $votouEm[(int) $unidade->getId()] ?? null;
            $nome = trim((string) $unidade->getResponsavel());
            $linhas[] = new LinhaDaRelacaoDeVotantes(
                '' !== $nome ? $nome : 'Não informado',
                IdentificacaoDaUnidade::exibir($unidade, isset($repetidas[$unidade->getIdentificacao()])),
                null !== $em ? LinhaDaRelacaoDeVotantes::VOTOU : ($unidade->isApta() ? LinhaDaRelacaoDeVotantes::NAO_VOTOU : LinhaDaRelacaoDeVotantes::INAPTO),
                null !== $em ? $em->setTime((int) $em->format('G'), 0) : null,
            );
        }
        $colacao = new \Collator('pt_BR');
        usort($linhas, static fn (LinhaDaRelacaoDeVotantes $x, LinhaDaRelacaoDeVotantes $y): int => $colacao->compare($x->nome, $y->nome) ?: $colacao->compare($x->identificacao, $y->identificacao));

        return $linhas;
    }

    /**
     * @return array{0: array<int, list<ParticipacaoNaPauta>>, 1: array<int, list<array{bloco: string, unidades: int, peso: string}>>}
     */
    private function participacaoPorPauta(Assembleia $assembleia): array
    {
        $votantes = [];
        $blocos = [];
        foreach ($assembleia->getPautas() as $pauta) {
            if (EstadoDaPauta::Aguardando === $pauta->getEstado()) {
                continue;
            }
            $lista = $this->participacoes->listarNominalDaPauta($pauta);
            $votantes[(int) $pauta->getId()] = $lista;
            $blocos[(int) $pauta->getId()] = self::porBloco($lista);
        }

        return [$votantes, $blocos];
    }

    /**
     * @param list<ParticipacaoNaPauta> $lista
     *
     * @return list<array{bloco: string, unidades: int, peso: string}>
     */
    private static function porBloco(array $lista): array
    {
        $blocos = [];
        foreach ($lista as $participacao) {
            $unidade = $participacao->getUnidade();
            $bloco = $blocos[$unidade->getBloco()] ?? ['bloco' => $unidade->getBloco(), 'unidades' => 0, 'peso' => '0.000000'];
            $blocos[$unidade->getBloco()] = ['bloco' => $bloco['bloco'], 'unidades' => $bloco['unidades'] + 1, 'peso' => bcadd($bloco['peso'], $unidade->pesoDoVoto(), 6)];
        }
        ksort($blocos);

        return array_values($blocos);
    }
}
