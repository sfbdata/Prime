<?php

declare(strict_types=1);

namespace App\Comercial\Repository;

use App\Comercial\DTO\DestinatarioDaCampanha;
use App\Comercial\Entity\DestinoDaCampanha;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Os destinatários de cada execução de campanha (spec `comercial-e-emails.md` §8.5), por SQL. A materialização é
 * idempotente (UNIQUE em campanha, execução e e-mail, `ON CONFLICT DO NOTHING`); o lote trava as linhas pendentes com
 * `FOR UPDATE SKIP LOCKED` (dois passos ao mesmo tempo nunca pegam a mesma linha) e só sai das campanhas que ainda
 * enviam (`enviando` ou `recorrente`): a cancelada fica parada.
 *
 * @extends ServiceEntityRepository<DestinoDaCampanha>
 */
class DestinoDaCampanhaRepository extends ServiceEntityRepository
{
    private const POR_INSERT = 500;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DestinoDaCampanha::class);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $trabalho
     *
     * @return T
     */
    public function emTransacao(\Closure $trabalho): mixed
    {
        return $this->conexao()->transactional(static fn (): mixed => $trabalho());
    }

    /**
     * Grava os destinatários da execução; quem já estava gravado não se repete. Devolve quantos entraram.
     *
     * @param list<DestinatarioDaCampanha> $destinatarios
     */
    public function materializar(int $campanhaId, int $execucao, array $destinatarios, \DateTimeImmutable $agora): int
    {
        $inseridos = 0;
        foreach (array_chunk($destinatarios, self::POR_INSERT) as $parte) {
            $valores = [];
            $parametros = ['campanha' => $campanhaId, 'execucao' => $execucao, 'agora' => $agora->format('Y-m-d H:i:s')];
            foreach ($parte as $i => $d) {
                $valores[] = '(:campanha, :execucao, :e' . $i . ', :n' . $i . ', :agora)';
                $parametros['e' . $i] = $d->email;
                $parametros['n' . $i] = $d->nome === '' ? null : $d->nome;
            }
            $inseridos += (int) $this->conexao()->executeStatement(
                'INSERT INTO comercial_campanha_destino (campanha_id, execucao, email, nome, criado_em) VALUES '
                . implode(', ', $valores) . ' ON CONFLICT (campanha_id, execucao, email) DO NOTHING',
                $parametros,
            );
        }

        return $inseridos;
    }

    /**
     * Trava até `limite` destinatários ainda não entregues ao Carteiro, das campanhas que ainda enviam e só da execução
     * corrente de cada uma. Precisa estar dentro de {@see self::emTransacao()}: a trava vale até o fim dela.
     *
     * @return list<array{id: int, campanha_id: int, email: string, nome: ?string}>
     */
    public function reservarPendentes(int $limite): array
    {
        $linhas = $this->conexao()->fetchAllAssociative(
            "SELECT d.id, d.campanha_id, d.email, d.nome
             FROM comercial_campanha_destino d JOIN comercial_campanha c ON c.id = d.campanha_id
             WHERE d.envio_id IS NULL AND d.execucao = c.execucoes AND c.estado IN ('enviando', 'recorrente')
             ORDER BY d.id LIMIT " . max(1, $limite) . ' FOR UPDATE OF d SKIP LOCKED',
        );

        return array_map(static fn (array $l): array => [
            'id' => (int) $l['id'], 'campanha_id' => (int) $l['campanha_id'], 'email' => (string) $l['email'],
            'nome' => $l['nome'] === null ? null : (string) $l['nome'],
        ], $linhas);
    }

    /** O envio de teste (F3f): a campanha tem um destinatário só, e o envio dele vem do Carteiro na hora. */
    public function vincularEnvioDaCampanha(int $campanhaId, int $envioId): void
    {
        $this->conexao()->executeStatement(
            'UPDATE comercial_campanha_destino SET envio_id = :envio WHERE campanha_id = :campanha AND envio_id IS NULL',
            ['campanha' => $campanhaId, 'envio' => $envioId],
        );
    }

    public function gravarEnvio(int $destinoId, int $envioId): void
    {
        $this->conexao()->executeStatement(
            'UPDATE comercial_campanha_destino SET envio_id = :envio WHERE id = :id AND envio_id IS NULL',
            ['id' => $destinoId, 'envio' => $envioId],
        );
    }

    /** Tira da execução um destinatário que não vira e-mail (endereço que deixou de valer): não fica preso no lote. */
    public function descartar(int $destinoId): void
    {
        $this->conexao()->executeStatement('DELETE FROM comercial_campanha_destino WHERE id = :id AND envio_id IS NULL', ['id' => $destinoId]);
    }

    /** Retenção (§3.2, P4): quantos ainda têm o endereço legível e foram gravados antes do limite. */
    public function contarParaAnonimizar(\DateTimeImmutable $limite): int
    {
        return (int) $this->conexao()->fetchOne(
            "SELECT COUNT(*) FROM comercial_campanha_destino WHERE criado_em < :limite AND email LIKE '%@%'",
            ['limite' => $limite->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Retenção (§3.2, P4): o endereço vira SHA-256 (hex) do sal da aplicação mais o endereço, e o nome fica nulo. O hash
     * não tem `@`: a linha já anonimizada não é refeita.
     */
    public function anonimizarAntesDe(\DateTimeImmutable $limite, string $sal): int
    {
        return (int) $this->conexao()->executeStatement(
            "UPDATE comercial_campanha_destino SET email = encode(sha256(convert_to(:sal || email, 'UTF8')), 'hex'), nome = NULL
             WHERE criado_em < :limite AND email LIKE '%@%'",
            ['limite' => $limite->format('Y-m-d H:i:s'), 'sal' => $sal],
        );
    }

    private function conexao(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
