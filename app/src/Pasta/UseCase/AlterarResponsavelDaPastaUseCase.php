<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\TrocaDeResponsavelOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\ResponsavelFixoNoAcessoRestritoException;
use App\Pasta\Service\ColaboradorNaTrocaDeResponsavel;
use App\Pasta\Service\EscopoDePastas;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Responsável da pasta (chip do cabeçalho). Quando de fato muda, o Registro ganha o cartão do sistema do desenho 02
 * (`escolher`, dc L3118): "Responsável: alterado de "Ana" para "Bruno".", com "Sem responsável" no lado vazio.
 * Escolher quem já é o responsável não grava nada. Quem resolve o colaborador (do escritório, atribuível) e checa a
 * permissão de editar é o controller; aqui fica a guarda do escritório.
 *
 * Acesso restrito (spec `acesso-restrito-e-compartilhar.md`, §5.1 item 5): quem só alcança as pastas sob a sua
 * responsabilidade não troca o responsável, nem para si (a pasta alheia nem chega aqui: a barreira dá 404).
 *
 * Troca com confirmação (fatia 7, L8 do desenho 03): a troca desvincula o responsável anterior; com "Manter como
 * colaborador", ele ganha um compartilhamento interno da pasta (regra 5), gravado na mesma transação da troca.
 */
final class AlterarResponsavelDaPastaUseCase
{
    public const SEM_RESPONSAVEL = 'Sem responsável';
    public const SEM_NOME = 'Usuário sem nome';

    public function __construct(
        private readonly RegistroDoSistemaNaPasta $registro,
        private readonly EntityManagerInterface $em,
        private readonly ?EscopoDePastas $escopo = null,
        private readonly ?ColaboradorNaTrocaDeResponsavel $colaborador = null,
    ) {
    }

    public function executar(Pasta $pasta, ?User $novo, User $autor, Tenant $tenant, bool $manterAnterior = false): TrocaDeResponsavelOutput
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if ($this->escopo?->ehRestritoNoEscritorio($autor, $tenant) === true) {
            throw new ResponsavelFixoNoAcessoRestritoException();
        }

        $atual = $pasta->getResponsavel();
        if (self::mesmo($atual, $novo)) {
            return new TrocaDeResponsavelOutput(false);
        }

        if ($manterAnterior && $this->colaborador === null) {
            throw new \LogicException('Manter como colaborador sem o ColaboradorNaTrocaDeResponsavel.');
        }

        $trocar = function () use ($pasta, $atual, $novo, $autor, $tenant, $manterAnterior): bool {
            $pasta->setResponsavel($novo);
            $this->registro->registrar($pasta, $autor, 'resp', sprintf('alterado de "%s" para "%s".', self::nome($atual), self::nome($novo)));
            $mantido = $manterAnterior && $atual !== null && $this->colaborador?->manter($pasta, $atual, $autor, $tenant) === true;
            $this->em->flush();

            return $mantido;
        };

        $mantido = $manterAnterior && $atual !== null
            ? (bool) $this->em->wrapInTransaction($trocar)
            : $trocar();

        return new TrocaDeResponsavelOutput(true, $mantido);
    }

    private static function mesmo(?User $a, ?User $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return $a !== null && $b !== null && $a->getId() !== null && $a->getId() === $b->getId();
    }

    private static function nome(?User $usuario): string
    {
        if ($usuario === null) {
            return self::SEM_RESPONSAVEL;
        }

        // Nunca o e-mail: o cartão fica no Registro de toda a equipe e na análise da I.A.
        return $usuario->getFullName() ?: self::SEM_NOME;
    }
}
