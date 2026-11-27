<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\ConsultaDeDuplicadosDaPasta;
use App\Pasta\Repository\PastaDocumentoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Manda para a LIXEIRA as cópias repetidas de documentos dentro da MESMA pasta
 * (`app:documentos:apagar-duplicados`, pedido do dono de 27/11/2026). Roda como SISTEMA, sem usuário.
 *
 * Duplicado = mesmo `sha256` na mesma pasta (nunca o critério "provável"). Uma versão DOC e uma PDF
 * do mesmo documento têm hash diferente e não são tocadas.
 *
 * ## Qual cópia fica, por grupo
 *  - exatamente UMA cópia com vínculo (assinatura, documento do cliente, favorito, modelo de peça,
 *    anexo de tarefa, selo "Assinado"): ela fica e todas as outras vão para a lixeira;
 *  - nenhuma com vínculo: fica a mais antiga (`uploaded_at`, `id`);
 *  - MAIS DE UMA com vínculo: o grupo é PULADO (não repontamos vínculos) e vai ao relatório.
 *
 * ## O que faz e o que não faz
 * É `marcarExcluido()`, igual ao "Excluir" da tela: a linha e o arquivo FICAM e a purga agendada
 * (30 dias) é quem apaga depois; restaurar da lixeira desfaz. O Drive não é tocado (o despacho
 * para ele é do controller, não da lápide). Sem cartão no Registro da pasta: `pasta_mensagem.autor_id` é NOT NULL no banco (o
 * rastro é o relatório do comando e a auditoria do `marcarExcluido`).
 *
 * Simulação (`$gravar = false`) decide e relata igual, sem gravar nada. Com `$gravar`, um COMMIT por
 * pasta (flush + clear): um erro no meio deixa as pastas anteriores consistentes.
 * Escopo: o grupo vem com o escritório; o documento e a pasta são recarregados e conferidos contra
 * ele antes de qualquer marcação.
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
     * @param int|null $limite    grupos por rodada (a última pasta vem inteira); null = todos
     * @param int|null $aPartirDe só pastas com id MAIOR que este (cursor da fatia anterior)
     */
    public function executar(bool $gravar, ?int $tenantId = null, ?int $pastaId = null, ?int $limite = null, ?int $aPartirDe = null): ResultadoDaLimpezaDeDuplicados
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
            $daPasta   = $this->decidirPasta($grupos);
            $decisoes  = [...$decisoes, ...$gravar ? $this->aplicar($daPasta) : $daPasta];
            $tocadas  += $this->enviadasNa($decisoes, $grupos[0]['pasta_id']) > 0 ? 1 : 0;
        }

        $ultima = $porPasta === [] ? null : array_key_last($porPasta);
        $aFrente = $ultima === null ? 0 : $this->consulta->gruposDepoisDaPasta($tenantId, $pastaId, $ultima);

        return new ResultadoDaLimpezaDeDuplicados($gravar, $decisoes, $tocadas, $ultima, $aFrente);
    }

    /**
     * @param list<array{tenant_id: int, pasta_id: int, sha256: string, ids: list<int>, tamanhos: list<int>}> $grupos
     *
     * @return list<DecisaoSobreGrupoDeDuplicados>
     */
    private function decidirPasta(array $grupos): array
    {
        $vinculos = $this->consulta->vinculos(array_merge(...array_column($grupos, 'ids')));

        return array_map(fn (array $g): DecisaoSobreGrupoDeDuplicados => self::decidir($g, $vinculos), $grupos);
    }

    /**
     * A regra de qual cópia fica, sem tocar em nada.
     *
     * @param array{tenant_id: int, pasta_id: int, sha256: string, ids: list<int>, tamanhos: list<int>} $grupo ids do mais antigo ao mais novo
     * @param array<int, list<string>>                                                                    $vinculos
     */
    public static function decidir(array $grupo, array $vinculos): DecisaoSobreGrupoDeDuplicados
    {
        $comVinculo = array_values(array_filter($grupo['ids'], static fn (int $id): bool => ($vinculos[$id] ?? []) !== []));

        if (\count($comVinculo) > 1) {
            return new DecisaoSobreGrupoDeDuplicados($grupo['tenant_id'], $grupo['pasta_id'], DecisaoSobreGrupoDeDuplicados::VARIOS_VINCULOS, null, [], 0);
        }

        $mantido = $comVinculo[0] ?? $grupo['ids'][0];
        $motivo  = $comVinculo === [] ? DecisaoSobreGrupoDeDuplicados::MAIS_ANTIGA : DecisaoSobreGrupoDeDuplicados::COM_VINCULO;
        $enviados = [];
        $bytes    = 0;
        foreach ($grupo['ids'] as $i => $id) {
            if ($id !== $mantido) {
                $enviados[] = $id;
                $bytes     += $grupo['tamanhos'][$i];
            }
        }

        return new DecisaoSobreGrupoDeDuplicados($grupo['tenant_id'], $grupo['pasta_id'], $motivo, $mantido, $enviados, $bytes);
    }

    /**
     * Grava a pasta inteira num COMMIT. Documento que sumiu, mudou de pasta/escritório, voltou a não ter
     * hash igual ou já está na lixeira (o filtro o esconde) rebaixa a decisão para "pulado" (a cópia que FICA também tem de estar viva).
     *
     * @param list<DecisaoSobreGrupoDeDuplicados> $decisoes
     *
     * @return list<DecisaoSobreGrupoDeDuplicados>
     */
    private function aplicar(array $decisoes): array
    {
        $enviaveis = array_merge(...array_map(static fn (DecisaoSobreGrupoDeDuplicados $d): array => $d->enviadosIds, $decisoes));
        if ($enviaveis === []) {
            return $decisoes;
        }

        $tenantId = $decisoes[0]->tenantId;
        $pastaId  = $decisoes[0]->pastaId;
        $mantidos = array_filter(array_map(static fn (DecisaoSobreGrupoDeDuplicados $d): ?int => $d->mantidoId, $decisoes));
        $vivos    = $this->documentosVivos([...$enviaveis, ...$mantidos], $tenantId, $pastaId);
        $agora    = $this->clock->now();
        $final    = [];

        foreach ($decisoes as $d) {
            if ($d->enviadosIds !== [] && !$this->todosVivos([...$d->enviadosIds, $d->mantidoId], $vivos)) {
                $final[] = new DecisaoSobreGrupoDeDuplicados($tenantId, $pastaId, DecisaoSobreGrupoDeDuplicados::NAO_ENCONTRADO, null, [], 0);
                continue;
            }
            foreach ($d->enviadosIds as $id) {
                $vivos[$id]->marcarExcluido(null, $agora);
            }
            $final[] = $d;
        }

        $this->em->flush();
        $this->em->clear();

        return $final;
    }

    /**
     * @param list<int|null>              $ids
     * @param array<int, PastaDocumento> $vivos
     */
    private function todosVivos(array $ids, array $vivos): bool
    {
        foreach ($ids as $id) {
            if ($id === null || !isset($vivos[$id])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, PastaDocumento> id => documento vivo, DESTA pasta e DESTE escritório
     */
    private function documentosVivos(array $ids, int $tenantId, int $pastaId): array
    {
        $vivos = [];
        foreach ($this->documentos->findBy(['id' => $ids, 'tenant' => $tenantId, 'pasta' => $pastaId]) as $doc) {
            if (!$doc->estaNaLixeira() && $doc->getSha256() !== null) {
                $vivos[(int) $doc->getId()] = $doc;
            }
        }

        return $vivos;
    }

    /** @param list<DecisaoSobreGrupoDeDuplicados> $decisoes */
    private function enviadasNa(array $decisoes, int $pastaId): int
    {
        $n = 0;
        foreach ($decisoes as $d) {
            $n += $d->pastaId === $pastaId ? \count($d->enviadosIds) : 0;
        }

        return $n;
    }
}
