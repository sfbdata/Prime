<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Exception\ConcessaoRecusada;

/**
 * A conferência completa de uma concessão antes de ela sustentar um token (`docs/specs/mcp-remoto-oauth.md`, seção 4,
 * regras 1 e 3, 9.2 e decisão 9 do planejamento do PR 3). Roda na troca do código, na troca da renovação e, a partir do
 * PR 5, a cada requisição do `/mcp`. Sem efeito colateral: não revoga, não grava, não liga filtro nem modo; quem chama
 * decide o que fazer com a recusa (no `/oauth/token`, revogar a concessão e responder `invalid_grant`).
 *
 * API pública estável (o PR 5 reusa): {@see exigir()}. A ordem das conferências é esta, e a primeira que falha lança:
 *
 * 1. concessão válida em `$agora`: não revogada, dentro dos 7 dias e com o consentimento ativo
 *    ({@see ConcessaoRecusada::concessaoInvalida()});
 * 2. o app de I.A. da concessão ativo no registro de clientes ({@see ConcessaoRecusada::clienteInativo()});
 * 3. corte de "Sair de todos os aparelhos": `autorizado_em` menor ou igual a `User::getSessoesEncerradasEm()`, com
 *    comparação por segundo inteiro (no mesmo segundo também cai), o mesmo critério do
 *    `SessaoEncerradaEmTodosOsAparelhosListener` ({@see ConcessaoRecusada::sessoesEncerradas()});
 * 4. impressão da senha de agora igual à gravada na autorização ({@see ConcessaoRecusada::senhaTrocada()});
 * 5. as barreiras da pessoa e do escritório, sem o passe do Master ({@see PortaoDoConector::conferir()}, lança
 *    {@see AcessoDoConectorRecusado});
 * 6. a liberação do conector nos três níveis ({@see LiberacaoDoConector::exigir()}, lança
 *    {@see AcessoDoConectorRecusado} com o motivo `liberacao`).
 */
final class ConferenciaDaConcessao
{
    public function __construct(
        private readonly PortaoDoConector $portao,
        private readonly LiberacaoDoConector $liberacao,
        private readonly ImpressaoDaSenha $impressaoDaSenha,
    ) {
    }

    /**
     * @throws ConcessaoRecusada         por motivo da própria concessão (itens 1 a 4)
     * @throws AcessoDoConectorRecusado  pelo portão ou pela liberação (itens 5 e 6)
     */
    public function exigir(ConcessaoDoConector $concessao, \DateTimeImmutable $agora): void
    {
        $this->exigirConcessaoViva($concessao, $agora);

        $pessoa = $concessao->getUser();
        if ($concessao->foiCortadoEm(self::fimDoSegundo($pessoa->getSessoesEncerradasEm()))) {
            throw ConcessaoRecusada::sessoesEncerradas();
        }
        if (!$concessao->impressaoDaSenhaBate($this->impressaoDaSenha->de($pessoa))) {
            throw ConcessaoRecusada::senhaTrocada();
        }

        $escritorio = $concessao->getTenant() ?? throw new \LogicException('Concessão sem escritório.');
        $this->portao->conferir((int) $pessoa->getId(), (int) $escritorio->getId());
        $this->liberacao->exigir($escritorio);
    }

    /** @throws ConcessaoRecusada */
    private function exigirConcessaoViva(ConcessaoDoConector $concessao, \DateTimeImmutable $agora): void
    {
        if (!$concessao->estaValidaEm($agora) || !$concessao->getConsentimento()->isAtivo()) {
            throw ConcessaoRecusada::concessaoInvalida();
        }
        if (!$concessao->getCliente()->isAtivo()) {
            throw ConcessaoRecusada::clienteInativo();
        }
    }

    /**
     * O corte vai ao último microssegundo do segundo dele: assim a concessão autorizada no mesmo segundo do corte cai
     * mesmo quando o `autorizado_em` em memória ainda tem fração (no banco a coluna guarda só segundos).
     */
    private static function fimDoSegundo(?\DateTimeImmutable $corte): ?\DateTimeImmutable
    {
        return $corte?->setTime((int) $corte->format('H'), (int) $corte->format('i'), (int) $corte->format('s'), 999999);
    }
}
