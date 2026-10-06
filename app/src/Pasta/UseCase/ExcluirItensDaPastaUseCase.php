<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Service\SelecaoDeItensDaPasta;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Manda para a LIXEIRA uma seleção de documentos e subpastas da pasta — o Del, o "Excluir (N)" da
 * barra de seleção, o ⋮ de um item e a rota de excluir UM documento (D4/D7, DOC-35/57/58).
 *
 * Quem dispara é um usuário com permissão de EDITAR a pasta (a rota checa; aqui só tenant e pasta
 * são reconferidos). O que ele quer: tirar o item da lista — e poder voltar atrás.
 *
 * Desde o L7 "excluir" é lápide: cada item recebe `excluido_em`/`excluido_por` e some de toda
 * leitura (pelo `LixeiraFilter`), mas a LINHA FICA e o ARQUIVO FÍSICO FICA, até
 * `app:documentos:purgar-lixeira` (30 dias). O Drive não é tocado. Por isso este UseCase não
 * devolve chave nenhuma — não há o que remover depois do COMMIT; quem remove é a purga.
 *
 * Uma subpasta leva a subárvore inteira (filhas, netas e os documentos de cada nível), TODA com o
 * MESMO carimbo de tempo — é esse carimbo que `RestaurarItensDaPastaUseCase` usa para devolver o
 * bloco de uma vez. O que já estava na lixeira dentro dela (excluído antes, em outra ação) mantém
 * o carimbo original e não volta junto. Item dentro de subpasta selecionada não é marcado "de
 * novo" (a travessia da subpasta o cobre) e entra uma vez só na contagem.
 *
 * Auditoria: `PastaDocumento` e `PastaSecao` são `Auditavel`; o `AuditLogSubscriber` registra a
 * marcação como `update` (diff de `excluidoEm`/`excluidoPor`), inclusive nos descendentes — nada
 * a fazer aqui.
 *
 * @see MoverItensDaPastaUseCase     o par deste, com a mesma seleção
 * @see RestaurarItensDaPastaUseCase o inverso
 */
final class ExcluirItensDaPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param list<PastaDocumento> $documentos
     * @param list<PastaSecao>     $secoes
     */
    public function executar(Pasta $pasta, array $documentos, array $secoes, User $autor, Tenant $tenant): ResultadoExcluirItensDaPasta
    {
        $selecao = SelecaoDeItensDaPasta::de($documentos, $secoes, $pasta, $tenant);
        $agora   = $this->clock->now();

        $subpastas = 0;
        $arquivos  = 0;

        foreach ($selecao->documentos as $documento) {
            // Já na lixeira só chega aqui com o filtro desligado por fora; não re-carimba.
            if ($documento->estaNaLixeira()) {
                continue;
            }
            $documento->marcarExcluido($autor, $agora);
            ++$arquivos;
        }

        foreach ($selecao->secoes as $secao) {
            $daArvore   = $secao->marcarArvoreExcluida($autor, $agora);
            $subpastas += 1 + $daArvore['subpastas'];
            $arquivos  += $daArvore['arquivos'];
        }

        $this->em->flush();

        return new ResultadoExcluirItensDaPasta(
            documentosRemovidos: count($selecao->documentos),
            subpastasRemovidas: $subpastas,
            arquivosRemovidos: $arquivos,
            idsDocumentos: array_values(array_map(static fn (PastaDocumento $d): int => (int) $d->getId(), $documentos)),
            idsSecoes: array_values(array_map(static fn (PastaSecao $s): int => (int) $s->getId(), $secoes)),
            excluidoEm: $agora,
        );
    }
}
