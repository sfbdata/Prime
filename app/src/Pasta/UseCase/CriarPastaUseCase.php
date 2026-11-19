<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Cliente\Entity\Cliente;
use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CriarPastaDTO;
use App\Pasta\Exception\NumeroDePastaEmUsoException;
use App\Pasta\Repository\PastaRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CriarPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GerarNumeroDePasta $gerarNumero,
        private readonly PastaRepository $pastaRepository,
    ) {}

    /** Com `recusarNumeroEmUso` e o número já em outra pasta viva, lança `NumeroDePastaEmUsoException` (nada é gravado). */
    public function executar(CriarPastaDTO $dto, User $criadoPor, Tenant $tenant): Pasta
    {
        // A transação é o que dá validade à trava por escritório do GerarNumeroDePasta: a
        // advisory lock só segura até o commit, então gerar o número e gravar a pasta têm de
        // estar dentro da MESMA transação. Separar os dois reabre a corrida que esta mudança
        // veio fechar. `use_savepoints: true` (doctrine.yaml) deixa isso aninhar em quem já
        // abriu transação — o dry-run do ReconciliarCommand é o caso real.
        $criada = $this->em->wrapInTransaction(function () use ($dto, $criadoPor, $tenant): Pasta|NumeroDePastaEmUsoException {
            $nup = $this->numeroDaPasta($dto, $tenant);
            if ($nup instanceof NumeroDePastaEmUsoException) {
                // Devolvida, não lançada: lançar aqui dentro fecharia o EntityManager de quem chamou.
                return $nup;
            }

            // Sem `recusarNumeroEmUso`, o NUP explícito ainda pode repetir (acervo/Drive trazem 10A/10B e
            // números repetidos): a identidade de sincronização é o driveFolderId, não o NUP.
            $pasta = new Pasta();
            $pasta->setNup($nup);
            $pasta->setNomeCliente($dto->nomeCliente);
            $pasta->setNomeAcao($dto->nomeAcao);
            $pasta->setCriadoPor($criadoPor);
            $pasta->setTenant($tenant);
            if ($dto->criadorComoResponsavel) {
                $pasta->setResponsavel($criadoPor);
            }
            $this->vincularAoCadastroDoMesmoNome($pasta, $dto, $tenant);

            $this->em->persist($pasta);
            $this->em->flush();

            return $pasta;
        });
        if ($criada instanceof NumeroDePastaEmUsoException) {
            throw $criada;
        }

        return $criada;
    }

    private function numeroDaPasta(CriarPastaDTO $dto, Tenant $tenant): string|NumeroDePastaEmUsoException
    {
        $nup = $dto->nup === null ? '' : trim($dto->nup);

        // Em branco = automático. Antes daqui isso era erro ("O NUP é obrigatório");
        // agora o sistema atribui, que é o requisito R1 do dono.
        if ($nup === '') {
            return $this->gerarNumero->executar($tenant);
        }
        if ($dto->recusarNumeroEmUso) {
            return $this->gerarNumero->recusaDoNumero($tenant, $nup, null) ?? $nup;
        }

        return $nup;
    }

    /** "Cliente já cadastrado em N pasta(s). A nova pasta será vinculada ao mesmo cadastro." (desenho 03, L276) */
    private function vincularAoCadastroDoMesmoNome(Pasta $pasta, CriarPastaDTO $dto, Tenant $tenant): void
    {
        $nome = (string) $pasta->getNomeCliente();
        if (!$dto->vincularAoCadastroDoMesmoNome || $nome === '') {
            return;
        }

        $id = $this->pastaRepository->cadastroUnicoPorNomeDeCliente([$nome], $tenant)[$nome] ?? null;
        $cliente = $id === null ? null : $this->em->find(Cliente::class, $id);
        if ($cliente !== null) {
            $pasta->addCliente($cliente);
        }
    }
}
