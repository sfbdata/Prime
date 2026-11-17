<?php

declare(strict_types=1);

namespace App\Conector\UseCase;

use App\Conector\DTO\ConexaoDeIaOutput;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Exception\ClienteRecusado;
use App\Conector\Repository\ConsentimentoDoConectorRepository;
use App\Entity\Auth\User;
use App\Repository\UserTenantRepository;
use App\Tenant\Enum\SituacaoNaEquipe;

/**
 * A lista "Conexões de I.A." do Meu Perfil (`docs/specs/mcp-remoto-oauth.md`, 13.1 linha do PR 8, e 14.e): as conexões
 * ativas da pessoa logada em TODOS os escritórios dela, com o escritório na linha (decisão 1 do plano do PR 8). Só da
 * própria pessoa: quem chama passa o usuário da sessão, nunca um id vindo da requisição.
 *
 * O app aparece pelo host do `client_id`, nunca pelo `client_name`, que é autodeclarado (9.4). A conexão de um
 * escritório com que a pessoa já não tem vínculo aprovado continua na lista, marcada, para ela poder revogar
 * (decisão 2): o vínculo é conferido como no portão do conector ({@see \App\Conector\Service\PortaoDoConector}), ativo
 * e com a situação aprovada.
 */
final class ListarConexoesDaPessoaUseCase
{
    public function __construct(
        private readonly ConsentimentoDoConectorRepository $consentimentos,
        private readonly UserTenantRepository $vinculos,
    ) {
    }

    /** @return list<ConexaoDeIaOutput> a mais recente primeiro */
    public function executar(User $pessoa): array
    {
        return array_map(
            fn (ConsentimentoDoConector $consentimento): ConexaoDeIaOutput => $this->paraATela($consentimento, $pessoa),
            $this->consentimentos->listarAtivosDaPessoaEmTodos($pessoa),
        );
    }

    private function paraATela(ConsentimentoDoConector $consentimento, User $pessoa): ConexaoDeIaOutput
    {
        $escritorio = $consentimento->getTenant() ?? throw new \LogicException('Conexão sem escritório.');
        $identificador = $consentimento->getCliente()->getIdentificador();

        return new ConexaoDeIaOutput(
            (int) $consentimento->getId(),
            ClienteRecusado::hostDe($identificador) ?? $identificador,
            (string) $escritorio->getName(),
            (int) $escritorio->getId(),
            $consentimento->getEscopos(),
            $consentimento->getConcedidoEm(),
            $consentimento->getUltimoUsoEm(),
            $this->temVinculoAprovado($pessoa, $consentimento),
        );
    }

    private function temVinculoAprovado(User $pessoa, ConsentimentoDoConector $consentimento): bool
    {
        $escritorio = $consentimento->getTenant();
        $vinculo = $escritorio === null ? null : $this->vinculos->findPorUserETenant($pessoa, $escritorio);

        return $vinculo !== null && $vinculo->isActive() && $vinculo->getSituacao() === SituacaoNaEquipe::Aprovado;
    }
}
