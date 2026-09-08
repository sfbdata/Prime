<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\ResultadoCorrecaoPastaDuplicada;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Service\NormalizadorDePastaJudicial;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CriarPastaDTO;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\UseCase\CriarPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Corrige o Problema B medido em produção: uma Pasta acabou vinculada a MAIS DE UM
 * `CasoCobranca` (mesma pessoa em unidades diferentes, ou pessoas diferentes) — sempre pelo modo
 * "vincular pasta existente" da judicialização, que até esta frente não tinha nenhuma trava contra
 * reuso (ver `JudicializarCasoUseCase::pastaExistenteDoTenant`, que agora recusa o caso novo).
 *
 * Esta correção é para os casos JÁ duplicados: por grupo (mesma `pasta_judicial_id`), o caso cujo
 * vínculo é mais ANTIGO (evento `Judicializacao`/`VinculoPasta`, não `criadoEm` — que não reflete a
 * ordem real do vínculo) FICA com a pasta; os demais recebem pasta NOVA (ou uma órfã compatível, se
 * houver), normalizada pelo mesmo fluxo que a judicialização usa.
 *
 * Duas camadas (`prever`/`confirmar`), padrão de `ReconciliarDuplaContagemUseCase`: `prever()` é
 * somente leitura, o artefato que o dono aprova antes da escrita; `confirmar()` aplica numa
 * transação única.
 */
final class CorrigirPastaJudicialDuplicadaUseCase
{
    public function __construct(
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly EventoHistoricoRepository $eventoRepository,
        private readonly PastaRepository $pastaRepository,
        private readonly CriarPastaUseCase $criarPasta,
        private readonly NormalizadorDePastaJudicial $normalizador,
        private readonly RegistrarEventoHistorico $registrarEvento,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function prever(Tenant $tenant): ResultadoCorrecaoPastaDuplicada
    {
        return $this->processar($tenant, null);
    }

    public function confirmar(Tenant $tenant, User $usuario): ResultadoCorrecaoPastaDuplicada
    {
        return $this->em->wrapInTransaction(
            fn (): ResultadoCorrecaoPastaDuplicada => $this->processar($tenant, $usuario),
        );
    }

    private function processar(Tenant $tenant, ?User $usuario): ResultadoCorrecaoPastaDuplicada
    {
        $itens = [];

        // Pastas já judicializadas do tenant INTEIRO — usado para achar órfã reaproveitável (pasta
        // existente, não excluída, que NENHUM caso usa) sem reconsultar por grupo.
        $idsJaVinculados = $this->casoRepository->pastaIdsJudicializadosDoTenant($tenant);

        foreach ($this->casoRepository->pastasJudiciaisDuplicadas($tenant) as $grupo) {
            $vencedor = $this->vencedorDoGrupo($grupo);
            $pastaCompartilhada = $vencedor->getPastaJudicial();

            foreach ($grupo as $caso) {
                if ($caso === $vencedor) {
                    continue;
                }

                $itens[] = $this->corrigirCaso($caso, $pastaCompartilhada, $tenant, $usuario, $idsJaVinculados, $vencedor);
            }
        }

        if ($usuario !== null) {
            // Flush único: todos os casos corrigidos + pastas + eventos numa transação só (ou tudo, ou
            // nada — `confirmar()` já envolve em `wrapInTransaction`).
            $this->em->flush();
        }

        return new ResultadoCorrecaoPastaDuplicada(aplicou: $usuario !== null, itens: $itens);
    }

    /**
     * O caso cujo vínculo com a pasta é mais ANTIGO (evento de histórico, não `criadoEm` — que não
     * reflete quando o vínculo de fato aconteceu). Casos sem nenhum evento de vínculo (não deveria
     * ocorrer — todo caminho de judicializar registra um) ficam por último, nunca vencem por omissão.
     *
     * @param list<CasoCobranca> $grupo
     */
    private function vencedorDoGrupo(array $grupo): CasoCobranca
    {
        $comData = [];
        foreach ($grupo as $caso) {
            $comData[] = ['caso' => $caso, 'data' => $this->dataDoVinculo($caso)];
        }

        usort($comData, static function (array $a, array $b): int {
            if ($a['data'] === null) {
                return 1;
            }
            if ($b['data'] === null) {
                return -1;
            }

            return $a['data'] <=> $b['data'];
        });

        return $comData[0]['caso'];
    }

    private function dataDoVinculo(CasoCobranca $caso): ?\DateTimeImmutable
    {
        $candidatos = array_filter(
            $this->eventoRepository->doCaso($caso),
            static fn ($e): bool => $e->getTipo() === TipoEventoHistorico::Judicializacao
                || $e->getTipo() === TipoEventoHistorico::VinculoPasta,
        );

        if ($candidatos === []) {
            return null;
        }

        // doCaso() vem mais-recente-primeiro; o vínculo é o mais ANTIGO dos candidatos.
        return min(array_map(static fn ($e) => $e->getOcorridoEm(), $candidatos));
    }

    /** @param list<int> $idsJaVinculados */
    private function corrigirCaso(
        CasoCobranca $caso,
        ?Pasta $pastaCompartilhada,
        Tenant $tenant,
        ?User $usuario,
        array $idsJaVinculados,
        CasoCobranca $vencedor,
    ): array {
        $pessoaNome = $caso->getPessoaCobradaAtual()?->getNome() ?? '—';
        $unidade = $caso->getObjeto()?->getIdentificacao() ?? '—';

        $orfa = $this->pastaOrfaCompativel($tenant, $pessoaNome, $idsJaVinculados);
        $reaproveitou = $orfa !== null;
        $pastaNova = $orfa ?? $this->criarPastaNova($tenant, $usuario);

        // Nome COM a unidade embutida — ao contrário do padrão normal (`ComporNomeDaPastaJudicial::
        // paraCaso`), que aqui produziria o MESMO nome para os dois casos do par duplicado (fantasia +
        // pessoa se repetem). A unidade desambigua. Por isso o `normalizador->normalizar()` (que
        // SOBRESCREVE o nome pelo padrão comum) roda ANTES, e este nome vem DEPOIS — a ordem importa.
        $pessoaComUnidade = trim(sprintf('%s (unidade %s)', $pessoaNome === '—' ? '' : $pessoaNome, $unidade));

        if ($usuario !== null) {
            $this->normalizador->normalizar($pastaNova, $caso, $tenant, $usuario);
            if ($pessoaComUnidade !== '') {
                $pastaNova->setNomeCliente($pessoaComUnidade);
            }
            $caso->setPastaJudicial($pastaNova);
            $this->casoRepository->salvar($caso);

            $this->registrarEventoDeCorrecao($caso, $vencedor, $pastaNova, $reaproveitou, $usuario);
        } elseif ($pessoaComUnidade !== '') {
            // Dry-run: sem chamar o normalizador (não persiste nada), mas o relatório precisa mostrar
            // o nome que a pasta teria.
            $pastaNova->setNomeCliente($pessoaComUnidade);
        }

        return [
            'pastaAntigaId' => (int) $pastaCompartilhada?->getId(),
            'pastaAntigaNup' => $pastaCompartilhada?->getNup(),
            'casoVencedorId' => (int) $vencedor->getId(),
            'casoCorrigidoId' => (int) $caso->getId(),
            'casoCorrigidoUnidade' => $unidade,
            'casoCorrigidoPessoa' => $pessoaNome,
            'reaproveitouOrfa' => $reaproveitou,
            'pastaNovaId' => $pastaNova->getId(),
            'pastaNovaNup' => $pastaNova->getNup(),
            'pastaNovaNome' => $pastaNova->getNomeCliente(),
        ];
    }

    /**
     * Pasta existente do tenant, NÃO excluída e SEM caso algum apontando pra ela, cujo nome bate com
     * a pessoa do caso perdedor — reaproveitar é melhor que inflar o acervo com pasta nova quando o
     * escritório já tinha aberto uma para esta unidade por engano. Sem correspondência, `null`
     * (comum — nenhum dos 2 casos reais medidos em 08/09 tinha órfã disponível).
     *
     * @param list<int> $idsJaVinculados
     */
    private function pastaOrfaCompativel(Tenant $tenant, string $pessoaNome, array $idsJaVinculados): ?Pasta
    {
        if (trim($pessoaNome) === '' || $pessoaNome === '—') {
            return null;
        }

        foreach ($this->pastaRepository->buscarParaVinculo($pessoaNome, $tenant, 5, $idsJaVinculados) as $linha) {
            return $this->pastaRepository->find($linha['id']);
        }

        return null;
    }

    private function criarPastaNova(Tenant $tenant, ?User $usuario): Pasta
    {
        if ($usuario === null) {
            // Dry-run: não cria pasta de verdade (poluiria o acervo a cada `prever()`). Instância NÃO
            // persistida, só para o relatório mostrar o que aconteceria.
            return new Pasta();
        }

        return $this->criarPasta->executar(new CriarPastaDTO(nomeCliente: null, nomeAcao: null), $usuario, $tenant);
    }

    private function registrarEventoDeCorrecao(
        CasoCobranca $caso,
        CasoCobranca $vencedor,
        Pasta $pastaNova,
        bool $reaproveitou,
        User $usuario,
    ): void {
        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::VinculoPasta,
            $usuario,
            sprintf(
                'Pasta corrigida: duplicidade com o caso #%d resolvida — %s %s.',
                $vencedor->getId(),
                $reaproveitou ? 'vinculado à pasta órfã' : 'nova pasta',
                $pastaNova->getNup() ?? (string) $pastaNova->getId(),
            ),
            ['pastaId' => $pastaNova->getId(), 'origem' => 'correcao_pasta_duplicada'],
        );
    }
}
