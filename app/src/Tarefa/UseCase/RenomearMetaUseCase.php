<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Exception\TituloDeMetaInvalidoException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Renomear a meta na própria lista da pasta (desenho 1.2.3, "Editar nome" no ⋮).
 *
 * Quem pode: a mesma guarda de concluir e de trocar responsáveis (acesso ao módulo +
 * `verificarAcessoTarefa`), aplicada pelo controller. Quem trocou e de qual nome para
 * qual vai para o Histórico pela auditoria (Tarefa é Auditavel).
 *
 * O nome é normalizado (espaços nas pontas e repetidos) e gravado como digitado.
 */
final class RenomearMetaUseCase
{
    /** Tamanho da coluna `tarefa.titulo`. */
    public const TAMANHO_MAXIMO = 255;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return bool true quando o nome mudou; false quando o novo nome é igual ao atual
     *
     * @throws TituloDeMetaInvalidoException
     */
    public function executar(Tarefa $tarefa, Tenant $tenant, string $titulo): bool
    {
        if (!self::mesmoEscritorio($tarefa->getTenant(), $tenant)) {
            // O TenantFilter já esconde meta de outro escritório; esta é a segunda trava.
            throw new \LogicException('Meta de outro escritório.');
        }

        $titulo = trim((string) preg_replace('/\s+/u', ' ', $titulo));

        if ($titulo === '') {
            throw new TituloDeMetaInvalidoException('O nome da meta não pode ficar vazio.');
        }

        if (mb_strlen($titulo) > self::TAMANHO_MAXIMO) {
            throw new TituloDeMetaInvalidoException(sprintf('O nome da meta pode ter até %d caracteres.', self::TAMANHO_MAXIMO));
        }

        if ($titulo === $tarefa->getTitulo()) {
            return false;
        }

        $tarefa->setTitulo($titulo);
        $this->em->flush();

        return true;
    }

    private static function mesmoEscritorio(?Tenant $daMeta, Tenant $atual): bool
    {
        return $daMeta === $atual
            || ($daMeta !== null && $daMeta->getId() !== null && $daMeta->getId() === $atual->getId());
    }
}
