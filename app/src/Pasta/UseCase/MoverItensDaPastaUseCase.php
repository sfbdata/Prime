<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\Service\SelecaoDeItensDaPasta;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Move uma seleção de documentos e subpastas para outra subpasta da MESMA pasta, ou para a raiz
 * (`$destino = null`) — o "Mover para…", o arraste e o Ctrl+X/V do explorador (D4, DOC-33/55).
 *
 * Quem dispara é um usuário com permissão de EDITAR a pasta; a rota provou a posse de TODOS os
 * ids numa consulta (contagem diferente → 404, nada parcial) e só então chega aqui, onde cada
 * item é reconferido ({@see SelecaoDeItensDaPasta}).
 *
 * Regras:
 *  - tudo ou nada: as validações rodam ANTES de qualquer `set`, e há um `flush()` só;
 *  - ciclo bloqueado: nenhuma subpasta selecionada pode ir para si mesma nem para a própria
 *    descendência (os mesmos guards de `MoverPastaSecaoUseCase`, item a item);
 *  - teto de profundidade: a SUBÁRVORE inteira de cada subpasta tem de caber no destino;
 *  - item dentro de subpasta selecionada vai junto com ela — não é movido por conta própria;
 *  - `ordem` das subpastas movidas: ao fim das irmãs do destino, na ordem da seleção. A ordem
 *    dos documentos não muda (como em `MoverDocumentoParaSecaoUseCase`);
 *  - mover NÃO marca `modificado_em`: o documento não mudou, só o lugar.
 *
 * @see ExcluirItensDaPastaUseCase o par deste, com a mesma seleção
 */
final class MoverItensDaPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaSecaoRepository $secaoRepository,
    ) {
    }

    /**
     * @param list<PastaDocumento> $documentos
     * @param list<PastaSecao>     $secoes
     */
    public function executar(
        Pasta $pasta,
        array $documentos,
        array $secoes,
        ?PastaSecao $destino,
        User $autor,
        Tenant $tenant,
    ): ResultadoMoverItensDaPasta {
        if ($destino !== null) {
            if ($destino->getTenant() !== $tenant) {
                throw new AccessDeniedException('Pasta de destino não pertence ao tenant do usuário.');
            }
            if ($destino->getPasta() !== $pasta) {
                throw new \InvalidArgumentException('A pasta de destino não pertence a esta pasta.');
            }
        }

        $selecao = SelecaoDeItensDaPasta::de($documentos, $secoes, $pasta, $tenant);

        foreach ($selecao->secoes as $secao) {
            if ($destino !== null && ($destino === $secao || $destino->descendeDe($secao))) {
                throw new \InvalidArgumentException('Não é possível mover uma pasta para dentro dela mesma.');
            }

            $profundidadeDoDestino = $destino?->getProfundidade() ?? 0;
            if ($profundidadeDoDestino + $secao->getAltura() > CriarPastaSecaoUseCase::PROFUNDIDADE_MAXIMA) {
                throw new \InvalidArgumentException(
                    sprintf('Não é possível passar de %d níveis de pasta.', CriarPastaSecaoUseCase::PROFUNDIDADE_MAXIMA),
                );
            }
        }

        // Uma consulta só para a ordem: MAX das irmãs do destino, ANTES de mover (o que já foi
        // movido ainda não está no banco, então consultar a cada item devolveria o mesmo número).
        $ordem = $selecao->secoes !== [] ? $this->secaoRepository->proximaOrdem($pasta, $tenant, $destino) : 0;

        foreach ($selecao->secoes as $secao) {
            $secao->setPai($destino);
            $secao->setOrdem($ordem++);
        }

        foreach ($selecao->documentos as $documento) {
            $documento->setSecao($destino);
        }

        $this->em->flush();

        return new ResultadoMoverItensDaPasta(
            documentos: count($selecao->documentos),
            secoes: count($selecao->secoes),
            destinoId: $destino?->getId(),
        );
    }
}
