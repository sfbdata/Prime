<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Exclui a pasta mandando-a para a lixeira: vira lápide, restaurável pelo `RestaurarPastaUseCase`.
 *
 * Vale para TODA pasta, inclusive a última da sequência (desenho 02 Expedientes, `menuPasta`
 * ('excluir'), sem o prazo de 30 dias do desenho: decisão do dono em 23/10, a lixeira de pastas não
 * tem purga e a pasta fica nela até ser restaurada). A linha fica com `excluida_em`/
 * `excluida_por` preenchidos e situação ARQUIVADA; os arquivos ficam no disco, a lixeira da aba
 * Documentos fica como está. A pasta continua na lista do Expediente, riscada, e fica
 * somente-leitura (a trava é do `PastaSomenteLeituraListener`).
 *
 * Antes, a última da sequência era apagada de verdade, com os arquivos, e o número voltava a ser
 * livre. Esse caminho saiu: excluir passou a ser sempre reversível. Por consequência o número da
 * lápide continua ocupado (o `GerarNumeroDePasta` conta todas as linhas), o que também elimina a
 * corrida entre "sou a última?" e a criação da próxima pasta.
 *
 * Apagar de vez depois dos 30 dias (purga) NÃO existe aqui: apagar dado é decisão do dono e ficou
 * pendente. Até ela existir, a lápide fica indefinidamente e continua restaurável.
 */
final class ExcluirPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(Pasta $pasta, User $autor, Tenant $tenant): void
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new AccessDeniedException('Pasta não pertence ao tenant do usuário.');
        }

        if ($pasta->estaExcluida()) {
            throw new \LogicException('Esta pasta já está excluída.');
        }

        // Os arquivos ficam de propósito: sem eles a pasta riscada abriria vazia e a restauração
        // devolveria uma pasta sem os documentos do caso.
        $pasta->marcarExcluida($autor, new \DateTimeImmutable());
        $this->em->flush();
    }
}
