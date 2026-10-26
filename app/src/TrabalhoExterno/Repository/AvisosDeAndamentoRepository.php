<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Os avisos de andamento da demanda (desenho 06 de 24/10, D10) que chegaram para quem está com a tela
 * aberta: o toque e o toast "Andamento · {ID}". Só os da própria pessoa, não lidos, no escritório em que
 * ela está (sem escritório, o Convidado de fora: os dele em qualquer um, como o sino sem o `TenantFilter`).
 * Pela conexão, com o escopo explícito: o filtro de tenant não vale para consulta em SQL.
 *
 * Sem `final`: o teste do caso de uso mocka esta classe.
 */
class AvisosDeAndamentoRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** O maior id de aviso de andamento desta pessoa (0 se nenhum): o cursor da primeira consulta. */
    public function ultimoId(User $usuario, ?Tenant $escritorio): int
    {
        [$filtro, $parametros] = self::escopo($usuario, $escritorio);

        return (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM notificacao WHERE ' . $filtro, $parametros);
    }

    /**
     * Os não lidos depois do cursor, do mais antigo ao mais novo, no máximo `$limite`.
     *
     * @return list<array{id: int, titulo: string, mensagem: ?string, url: ?string, criada_em: string}>
     */
    public function depoisDe(User $usuario, ?Tenant $escritorio, int $desde, int $limite): array
    {
        [$filtro, $parametros] = self::escopo($usuario, $escritorio);
        $linhas = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, titulo, mensagem, url, criada_em FROM notificacao WHERE ' . $filtro . ' AND id > :desde AND lida = false ORDER BY id ASC LIMIT ' . max(1, $limite),
            $parametros + ['desde' => $desde],
        );

        return array_map(static fn (array $l): array => [
            'id' => (int) $l['id'],
            'titulo' => (string) $l['titulo'],
            'mensagem' => $l['mensagem'] !== null ? (string) $l['mensagem'] : null,
            'url' => $l['url'] !== null ? (string) $l['url'] : null,
            'criada_em' => (string) $l['criada_em'],
        ], $linhas);
    }

    /** @return array{0: string, 1: array<string, int|string>} */
    private static function escopo(User $usuario, ?Tenant $escritorio): array
    {
        $parametros = ['usuario' => (int) $usuario->getId(), 'tipo' => Notificacao::TIPO_TRABALHO_EXTERNO_DEMANDA_ANDAMENTO];
        if ($escritorio === null) {
            return ['usuario_id = :usuario AND tipo = :tipo', $parametros];
        }

        return ['usuario_id = :usuario AND tipo = :tipo AND tenant_id = :tenant', $parametros + ['tenant' => (int) $escritorio->getId()]];
    }
}
