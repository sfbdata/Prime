<?php

declare(strict_types=1);

namespace App\Conector\UseCase;

use App\Conector\DTO\EscritorioNoConectorOutput;
use App\Conector\Enum\VersaoDoAceiteDoConector;
use App\Conector\Repository\ConsentimentoDoConectorRepository;
use App\Conector\Repository\EscritorioDoConectorRepository;
use App\Conector\Service\GuardaDoMasterDoConector;
use App\Entity\Auth\User;

/**
 * A tabela do conector de I.A. na aba "Configurações da I.A." do Master (`docs/specs/mcp-remoto-oauth.md`, 14.a): todo
 * escritório ativo, com a liberação do Master (nível 2), o "ligado" do administrador (nível 3), o aceite e quantas
 * conexões ativas ele tem. O Master vê só a contagem, nunca de quem é cada conexão (decisão 6 do plano do PR 8).
 */
final class ObterConectorNoPainelDoMasterUseCase
{
    public function __construct(
        private readonly EscritorioDoConectorRepository $escritorios,
        private readonly ConsentimentoDoConectorRepository $consentimentos,
    ) {
    }

    /** @return list<EscritorioNoConectorOutput> */
    public function executar(User $master): array
    {
        GuardaDoMasterDoConector::exigir($master);
        $conexoes = $this->consentimentos->contarAtivosPorEscritorio();
        $vigente = VersaoDoAceiteDoConector::atual()->value;

        return array_map(static fn (array $linha): EscritorioNoConectorOutput => new EscritorioNoConectorOutput(
            tenantId: $linha['tenantId'],
            nome: $linha['nome'],
            liberado: $linha['liberado'],
            ligado: $linha['ligado'],
            aceiteEm: $linha['aceiteEm'] === null ? null : new \DateTimeImmutable($linha['aceiteEm']),
            // A mesma regra de EscritorioDoConector::aceiteCobreVersaoVigente(), sobre a linha do SQL.
            aceiteVigente: $linha['aceiteEm'] !== null && $linha['aceiteVersao'] !== null && $linha['aceiteVersao'] >= $vigente,
            conexoesAtivas: $conexoes[$linha['tenantId']] ?? 0,
        ), $this->escritorios->listarEscritoriosParaOMaster());
    }
}
