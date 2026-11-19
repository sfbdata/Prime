<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Tenant\Service\GeradorDeCodigoDaConta;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * O código pessoal `BJ-XXXX-XXXX-XXXX` de cada usuário, com que ele recebe pastas de outras contas (spec
 * acesso-restrito-e-compartilhar §4.5, Q3). Mesmo gerador e mesmo alfabeto do código da conta, e único entre os dois:
 * um código nunca é ao mesmo tempo de um escritório e de uma pessoa. Nasce na primeira vez que a pessoa o vê.
 */
final class CodigoPessoal
{
    private const TENTATIVAS = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GeradorDeCodigoDaConta $gerador,
    ) {
    }

    /**
     * O código da pessoa; sem código ainda, sorteia um livre e grava.
     *
     * A gravação é um UPDATE condicional (`codigo_pessoal IS NULL`), não um flush: duas visitas ao mesmo tempo na
     * primeira vez (Perfil e Recebidas, ou duas abas) sorteariam códigos diferentes e o último flush trocaria o código
     * que a primeira já mostrou. Assim só o primeiro grava e o outro lê o que ficou no banco. Sem flush, nada mais do
     * que estiver pendente no EntityManager é gravado aqui.
     */
    public function garantir(User $pessoa): string
    {
        $atual = $pessoa->getCodigoPessoal();
        if ($atual !== null) {
            return $atual;
        }
        $conexao = $this->em->getConnection();
        for ($i = 0; $i < self::TENTATIVAS; ++$i) {
            $codigo = $this->gerador->gerar();
            if ($this->emUso($codigo)) {
                continue;
            }
            try {
                $conexao->executeStatement(
                    'UPDATE "user" SET codigo_pessoal = ? WHERE id = ? AND codigo_pessoal IS NULL',
                    [$codigo, $pessoa->getId()],
                );
            } catch (UniqueConstraintViolationException) {
                continue;   // outra pessoa levou o mesmo código entre a consulta e o UPDATE: sorteia de novo
            }
            $gravado = $conexao->fetchOne('SELECT codigo_pessoal FROM "user" WHERE id = ?', [$pessoa->getId()]);
            if (!\is_string($gravado)) {
                break;
            }
            $this->sincronizar($pessoa, $gravado);

            return $gravado;
        }

        throw new \RuntimeException('Não foi possível sortear um código pessoal livre.');
    }

    /** Normaliza o que a pessoa digitou (maiúsculas, sem espaços); `null` fora do formato `BJ-XXXX-XXXX-XXXX`. */
    public static function normalizar(?string $digitado): ?string
    {
        $codigo = strtoupper((string) preg_replace('/\s+/', '', (string) $digitado));

        return preg_match(GeradorDeCodigoDaConta::PADRAO, $codigo) === 1 ? $codigo : null;
    }

    /** O usuário ativo dono do código, se houver. */
    public function dono(string $codigo): ?User
    {
        $pessoa = $this->em->getRepository(User::class)->findOneBy(['codigoPessoal' => $codigo]);

        return $pessoa !== null && $pessoa->isActive() ? $pessoa : null;
    }

    /** Põe na entidade o código que já está no banco, sem que o próximo flush o grave de novo. */
    private function sincronizar(User $pessoa, string $codigo): void
    {
        $pessoa->definirCodigoPessoal($codigo);
        if ($this->em->contains($pessoa)) {
            $this->em->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($pessoa), 'codigoPessoal', $codigo);
        }
    }

    private function emUso(string $codigo): bool
    {
        $conexao = $this->em->getConnection();

        return $conexao->fetchOne('SELECT 1 FROM "user" WHERE codigo_pessoal = ?', [$codigo]) !== false
            || $conexao->fetchOne('SELECT 1 FROM tenant WHERE codigo_conta = ?', [$codigo]) !== false;
    }
}
