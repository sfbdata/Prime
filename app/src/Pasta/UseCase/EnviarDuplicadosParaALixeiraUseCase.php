<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\ConsultaDeDuplicadosDaPasta;
use App\Pasta\Repository\PastaDocumentoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Manda para a LIXEIRA as cópias repetidas de documentos dentro da MESMA pasta e da MESMA seção
 * (`app:documentos:apagar-duplicados`, pedido do dono de 27/11/2026). Roda como SISTEMA, sem usuário.
 *
 * Duplicado = mesmo `sha256` na mesma pasta E na mesma seção (sem seção conta como uma seção, a raiz;
 * nunca o critério "provável"). Cópia idêntica em seções diferentes fica. Uma versão DOC e uma PDF do
 * mesmo documento têm hash diferente e não são tocadas.
 *
 * ## Qual cópia fica, por grupo
 *  - exatamente UMA cópia com vínculo (assinatura, documento do cliente, favorito, modelo de peça,
 *    anexo de tarefa, selo "Assinado"): ela fica e todas as outras vão para a lixeira;
 *  - nenhuma com vínculo: fica a mais antiga (`uploaded_at`, `id`);
 *  - MAIS DE UMA com vínculo: o grupo é PULADO (não repontamos vínculos) e vai ao relatório.
 *
 * ## O que faz e o que não faz
 * É `marcarExcluido()`, igual ao "Excluir" da tela: a linha e o arquivo FICAM e a purga agendada
 * (30 dias) é quem apaga depois; restaurar da lixeira desfaz. O Drive não é tocado. Sem cartão no
 * Registro da pasta: `pasta_mensagem.autor_id` é NOT NULL no banco (o rastro é o relatório do comando
 * e a auditoria do `marcarExcluido`).
 *
 * Simulação (`$gravar = false`) decide e relata igual, sem gravar nada. Com `$gravar`, um COMMIT por
 * pasta. DENTRO da transação da pasta tudo é relido: cada documento (escritório, pasta, seção, sha256
 * igual ao do grupo, tamanho > 0, vivo) e os vínculos. Mudou algo desde a consulta: o grupo é pulado.
 * `$aoConcluirPasta` recebe as decisões de cada pasta logo DEPOIS do COMMIT dela (é por ele que o
 * comando grava o CSV de forma incremental).
 */
final class EnviarDuplicadosParaALixeiraUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConsultaDeDuplicadosDaPasta $consulta,
        private readonly PastaDocumentoRepository $documentos,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param int|null                                                   $limite          grupos por rodada (a última pasta vem inteira); null = todos
     * @param int|null                                                   $aPartirDe       só pastas com id MAIOR que este (cursor da fatia anterior)
     * @param (callable(list<DecisaoSobreGrupoDeDuplicados>): void)|null $aoConcluirPasta
     */
    public function executar(bool $gravar, ?int $tenantId = null, ?int $pastaId = null, ?int $limite = null, ?int $aPartirDe = null, ?callable $aoConcluirPasta = null): ResultadoDaLimpezaDeDuplicados
    {
        if ($limite !== null && $limite < 1) {
            throw new \InvalidArgumentException('O limite precisa ser um inteiro positivo.');
        }

        $porPasta = [];
        foreach ($this->consulta->grupos($tenantId, $pastaId, $aPartirDe, $limite) as $grupo) {
            $porPasta[$grupo['pasta_id']][] = $grupo;
        }

        $decisoes = [];
        $tocadas  = 0;
        foreach ($porPasta as $grupos) {
            $daPasta = $this->decidirPasta($grupos);
            if ($gravar) {
                $daPasta = $this->aplicar($grupos, $daPasta);
            }
            if ($aoConcluirPasta !== null) {
                $aoConcluirPasta($daPasta);
            }
            $decisoes = [...$decisoes, ...$daPasta];
            $tocadas += array_sum(array_map(static fn (DecisaoSobreGrupoDeDuplicados $d): int => \count($d->enviadosIds), $daPasta)) > 0 ? 1 : 0;
        }

        $ultima  = $porPasta === [] ? null : array_key_last($porPasta);
        $aFrente = $ultima === null ? 0 : $this->consulta->gruposDepoisDaPasta($tenantId, $pastaId, $ultima);

        return new ResultadoDaLimpezaDeDuplicados($gravar, $decisoes, $tocadas, $ultima, $aFrente);
    }

    /**
     * @param list<array{tenant_id: int, pasta_id: int, secao_id: int|null, sha256: string, ids: list<int>, tamanhos: list<int>}> $grupos
     *
     * @return list<DecisaoSobreGrupoDeDuplicados>
     */
    private function decidirPasta(array $grupos): array
    {
        $vinculos = $this->consulta->vinculos(array_merge(...array_column($grupos, 'ids')));

        return array_map(static fn (array $g): DecisaoSobreGrupoDeDuplicados => self::decidir($g, $vinculos), $grupos);
    }

    /**
     * A regra de qual cópia fica, sem tocar em nada.
     *
     * @param array{tenant_id: int, pasta_id: int, secao_id: int|null, sha256: string, ids: list<int>, tamanhos: list<int>} $grupo    ids do mais antigo ao mais novo
     * @param array<int, list<string>>                                                                                      $vinculos
     */
    public static function decidir(array $grupo, array $vinculos): DecisaoSobreGrupoDeDuplicados
    {
        $comVinculo = array_values(array_filter($grupo['ids'], static fn (int $id): bool => ($vinculos[$id] ?? []) !== []));

        if (\count($comVinculo) > 1) {
            return new DecisaoSobreGrupoDeDuplicados($grupo['tenant_id'], $grupo['pasta_id'], $grupo['secao_id'], DecisaoSobreGrupoDeDuplicados::VARIOS_VINCULOS, null, [], 0);
        }

        $mantido  = $comVinculo[0] ?? $grupo['ids'][0];
        $motivo   = $comVinculo === [] ? DecisaoSobreGrupoDeDuplicados::MAIS_ANTIGA : DecisaoSobreGrupoDeDuplicados::COM_VINCULO;
        $enviados = [];
        $bytes    = 0;
        foreach ($grupo['ids'] as $i => $id) {
            if ($id !== $mantido) {
                $enviados[] = $id;
                $bytes     += $grupo['tamanhos'][$i];
            }
        }

        return new DecisaoSobreGrupoDeDuplicados($grupo['tenant_id'], $grupo['pasta_id'], $grupo['secao_id'], $motivo, $mantido, $enviados, $bytes);
    }

    /**
     * Grava a pasta inteira num COMMIT, relendo tudo DENTRO da transação.
     *
     * @param list<array{tenant_id: int, pasta_id: int, secao_id: int|null, sha256: string, ids: list<int>, tamanhos: list<int>}> $grupos
     * @param list<DecisaoSobreGrupoDeDuplicados>                                                                                 $planejadas
     *
     * @return list<DecisaoSobreGrupoDeDuplicados>
     */
    private function aplicar(array $grupos, array $planejadas): array
    {
        if (array_merge(...array_map(static fn (DecisaoSobreGrupoDeDuplicados $d): array => $d->enviadosIds, $planejadas)) === []) {
            return $planejadas;
        }

        $final = $this->em->wrapInTransaction(fn (): array => $this->aplicarNaTransacao($grupos, $planejadas));
        $this->em->clear();

        return $final;
    }

    /**
     * @param list<array{tenant_id: int, pasta_id: int, secao_id: int|null, sha256: string, ids: list<int>, tamanhos: list<int>}> $grupos
     * @param list<DecisaoSobreGrupoDeDuplicados>                                                                                 $planejadas
     *
     * @return list<DecisaoSobreGrupoDeDuplicados>
     */
    private function aplicarNaTransacao(array $grupos, array $planejadas): array
    {
        $todos    = array_merge(...array_column($grupos, 'ids'));
        $vivos    = $this->documentosVivos($todos, $planejadas[0]->tenantId, $planejadas[0]->pastaId);
        $vinculos = $this->consulta->vinculos($todos);
        $agora    = $this->clock->now();
        $final    = [];

        foreach ($planejadas as $i => $planejada) {
            $grupo = $grupos[$i];
            $nova  = self::decidir($grupo, $vinculos);

            if ($planejada->foiPulado()) {
                $final[] = $planejada;
            } elseif ($nova->mantidoId !== $planejada->mantidoId || $nova->enviadosIds !== $planejada->enviadosIds) {
                $final[] = $this->pulada($planejada, DecisaoSobreGrupoDeDuplicados::VINCULO_NOVO);
            } elseif (!$this->conferem($grupo, $vivos)) {
                $final[] = $this->pulada($planejada, DecisaoSobreGrupoDeDuplicados::NAO_ENCONTRADO);
            } else {
                foreach ($planejada->enviadosIds as $id) {
                    $vivos[$id]->marcarExcluido(null, $agora);
                }
                $final[] = $planejada;
            }
        }

        return $final;
    }

    private function pulada(DecisaoSobreGrupoDeDuplicados $d, string $motivo): DecisaoSobreGrupoDeDuplicados
    {
        return new DecisaoSobreGrupoDeDuplicados($d->tenantId, $d->pastaId, $d->secaoId, $motivo, null, [], 0);
    }

    /**
     * Todos os documentos do grupo estão vivos, na seção do grupo, com o sha256 do grupo e tamanho > 0.
     *
     * @param array{tenant_id: int, pasta_id: int, secao_id: int|null, sha256: string, ids: list<int>, tamanhos: list<int>} $grupo
     * @param array<int, PastaDocumento>                                                                                    $vivos
     */
    private function conferem(array $grupo, array $vivos): bool
    {
        foreach ($grupo['ids'] as $id) {
            $doc = $vivos[$id] ?? null;
            if ($doc === null || $doc->getSha256() !== $grupo['sha256'] || $doc->getTamanhoBytes() <= 0 || $doc->getSecao()?->getId() !== $grupo['secao_id']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, PastaDocumento> id => documento vivo, DESTE escritório e DESTA pasta
     */
    private function documentosVivos(array $ids, int $tenantId, int $pastaId): array
    {
        $vivos = [];
        foreach ($this->documentos->findBy(['id' => $ids, 'tenant' => $tenantId, 'pasta' => $pastaId]) as $doc) {
            if (!$doc->estaNaLixeira()) {
                $vivos[(int) $doc->getId()] = $doc;
            }
        }

        return $vivos;
    }
}
