<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Service\ComporNomeDaPastaJudicial;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Regrava o `nome_cliente` das pastas judiciais no padrão novo (só o cliente da carteira, decisão do
 * dono, 2026-10-27). A tela já mostra o nome derivado; isto alinha o texto gravado (a reserva), que
 * ainda traz `<fantasia> - <pessoa cobrada>` ou `<pessoa> (unidade X)` das judicializações antigas.
 *
 * Pasta com mais de um caso (o par duplicado que `CorrigirPastaJudicialDuplicadaUseCase` desfaz)
 * recebe o nome do primeiro caso. Caso sem cliente de carteira não muda nada. Simula por padrão.
 *
 * Também alinha as pastas que NÃO são judiciais mas têm cliente principal e ainda guardam um texto
 * composto ("cliente - alguém"): passam a guardar o nome do cliente principal. Pasta sem cliente
 * principal fica como está (o texto livre é tudo o que ela tem). Idempotente: o segundo uso não muda nada.
 */
final class RegravarNomeDasPastasJudiciaisUseCase
{
    public function __construct(
        private readonly CasoCobrancaRepository $casos,
        private readonly ComporNomeDaPastaJudicial $comporNome,
        private readonly EntityManagerInterface $em,
        private readonly PastaRepository $pastas,
    ) {
    }

    /**
     * @return list<array{pastaId: int, antes: ?string, depois: string}> só as pastas cujo nome muda
     */
    public function executar(Tenant $tenant, bool $aplicar): array
    {
        $mudancas = [];
        $vistas = [];
        $judiciais = [];

        foreach ($this->casos->comPastaJudicial($tenant) as $caso) {
            $pasta = $caso->getPastaJudicial();
            $id = $pasta?->getId();
            $nome = $this->comporNome->paraCaso($caso);
            if ($id !== null) {
                $judiciais[$id] = true;
            }

            if ($pasta === null || $id === null || isset($vistas[$id]) || $nome === null) {
                continue;
            }
            $vistas[$id] = true;

            if ($pasta->getNomeCliente() === $nome) {
                continue;
            }

            $mudancas[] = ['pastaId' => $id, 'antes' => $pasta->getNomeCliente(), 'depois' => $nome];
            if ($aplicar) {
                $pasta->setNomeCliente($nome);
            }
        }

        foreach ($this->pastas->comNomeCompostoEClientePrincipal($tenant) as $pasta) {
            $id = $pasta->getId();
            $nome = $this->comporNome->paraCliente($pasta->getClientePrincipal());

            if ($id === null || isset($judiciais[$id]) || $nome === null || $nome === '' || $pasta->getNomeCliente() === $nome) {
                continue;
            }

            $mudancas[] = ['pastaId' => $id, 'antes' => $pasta->getNomeCliente(), 'depois' => $nome];
            if ($aplicar) {
                $pasta->setNomeCliente($nome);
            }
        }

        if ($aplicar && $mudancas !== []) {
            $this->em->flush();
        }

        return $mudancas;
    }
}
