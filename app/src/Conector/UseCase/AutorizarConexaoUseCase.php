<?php

declare(strict_types=1);

namespace App\Conector\UseCase;

use App\Conector\DTO\AutorizarConexaoInput;
use App\Conector\DTO\PedidoDeAutorizacao;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\CodigoDoConector;
use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Exception\ClienteRecusado;
use App\Conector\Repository\CodigoDoConectorRepository;
use App\Conector\Repository\ConcessaoDoConectorRepository;
use App\Conector\Repository\ConsentimentoDoConectorRepository;
use App\Conector\Service\ImpressaoDaSenha;
use App\Conector\Service\LiberacaoDoConector;
use App\Conector\Service\PortaoDoConector;
use App\Conector\Service\RegistroDeClientes;
use App\Conector\Service\SegredoOpaco;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * A pessoa autorizou, na tela, um app de I.A. a agir em UM escritório (`docs/specs/mcp-remoto-oauth.md`, 4, 9.4 e
 * 13.2). Devolve o código de autorização (o valor; o banco guarda só o hash), que volta ao app no redirecionamento.
 *
 * Nada do que veio do formulário é aceito sem conferência no servidor:
 * - o escritório tem de estar entre os vínculos ativos da pessoa (`findActiveByUser`), e passar de novo pelo
 *   {@see PortaoDoConector::conferir()} (sem o passe do Master) e pela {@see LiberacaoDoConector::exigir()};
 * - o cliente e o `redirect_uri` passam de novo pelo {@see RegistroDeClientes} (o app pode ter sido desativado entre o
 *   GET e o POST);
 * - os parâmetros OAuth vêm do {@see PedidoDeAutorizacao} da sessão, nunca do formulário.
 *
 * Escopos: `mcp:leitura` sempre; `mcp:escrita` só se o app pediu E a pessoa marcou (5.1); `offline_access` se o app
 * pediu. Consentimento ativo da mesma pessoa, escritório e app é reaproveitado com os escopos novos (decisão 5 de
 * 16/11); senão nasce outro. A concessão nasce com a impressão da senha e o `autorizado_em` do relógio (seção 4,
 * regra 3), e o código vale 10 minutos.
 *
 * Gravação numa transação, dentro do escopo de auditoria do escritório ESCOLHIDO: o `audit_log` do consentimento cai
 * na trilha dele, não na do escritório que está na sessão do navegador.
 *
 * Duas autorizações da mesma pessoa ao mesmo tempo (dois navegadores na primeira conexão) se enfileiram pela trava da
 * linha da pessoa (`SELECT ... FOR UPDATE`), pedida no começo da transação: a segunda espera a primeira confirmar e
 * então encontra o consentimento ativo e o reaproveita, em vez de esbarrar no índice único do consentimento ativo.
 */
final class AutorizarConexaoUseCase
{
    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly PortaoDoConector $portao,
        private readonly LiberacaoDoConector $liberacao,
        private readonly RegistroDeClientes $registro,
        private readonly ConsentimentoDoConectorRepository $consentimentos,
        private readonly ConcessaoDoConectorRepository $concessoes,
        private readonly CodigoDoConectorRepository $codigos,
        private readonly ImpressaoDaSenha $impressao,
        private readonly SegredoOpaco $segredo,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDeAuditoria,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @throws AcessoDoConectorRecusado escritório sem vínculo ativo, barrado pelo portão ou sem a liberação
     * @throws ClienteRecusado          o app ou o retorno deixou de ser aceito
     */
    public function executar(AutorizarConexaoInput $input, PedidoDeAutorizacao $pedido, User $pessoa): string
    {
        $tenant = $this->escritorioDosVinculosAtivos($pessoa, $input->tenantId);
        $cliente = $this->registro->resolver($pedido->clientId, $pedido->redirectUri);
        $escopos = self::escoposConcedidos($pedido, $input->escrita);

        return $this->escopoDeAuditoria->executarComoConta(
            (int) $tenant->getId(),
            fn (): string => $this->em->wrapInTransaction(
                fn (): string => $this->gravar($pessoa, $tenant, $cliente, $pedido, $escopos),
            ),
        );
    }

    /** @throws AcessoDoConectorRecusado */
    private function escritorioDosVinculosAtivos(User $pessoa, ?int $tenantId): Tenant
    {
        $vinculo = null;
        foreach ($this->vinculos->findActiveByUser($pessoa) as $ativo) {
            if ($tenantId !== null && $ativo->getTenant()->getId() === $tenantId) {
                $vinculo = $ativo;
            }
        }
        if ($vinculo === null) {
            throw AcessoDoConectorRecusado::escritorio();
        }

        return $vinculo->getTenant();
    }

    /**
     * Confere o escritório de novo, dentro da transação, e grava consentimento, concessão e código. Reautorizar o mesmo
     * app no mesmo escritório derruba antes as concessões abertas do consentimento (`substituida`): os escopos que
     * valem são os escolhidos agora, e a cadeia antiga (talvez com gravação) não fica viva sem aparecer no Meu Perfil.
     *
     * @param list<string> $escopos
     *
     * @throws AcessoDoConectorRecusado
     */
    private function gravar(User $pessoa, Tenant $tenant, ClienteDoConector $cliente, PedidoDeAutorizacao $pedido, array $escopos): string
    {
        // Antes de qualquer leitura: quem chega em segundo espera aqui e já lê o que o primeiro confirmou. O `lock()`
        // trava só a linha (o `find()` com trava recarregaria a pessoa com JOIN, e o PostgreSQL recusa o FOR UPDATE no
        // lado opcional de um LEFT JOIN).
        $this->em->lock($this->em->contains($pessoa) ? $pessoa : $this->em->getReference(User::class, $pessoa->getId()), LockMode::PESSIMISTIC_WRITE);
        $this->portao->conferir((int) $pessoa->getId(), (int) $tenant->getId());
        $this->liberacao->exigir($tenant);

        $agora = $this->relogio->now();
        $consentimento = $this->consentimento($pessoa, $tenant, $cliente, $escopos, $agora);
        if ($consentimento->getId() !== null) {
            $this->concessoes->revogarDoConsentimento($consentimento, MotivoDaRevogacaoDaConcessao::Substituida, $agora);
        }
        $concessao = new ConcessaoDoConector($consentimento, $escopos, $pedido->resource, $this->impressao->de($pessoa), $agora);
        $codigo = $this->segredo->gerar();

        $this->consentimentos->salvar($consentimento);
        $this->concessoes->salvar($concessao);
        $this->codigos->salvar(new CodigoDoConector($codigo['hash'], $concessao, $pedido->redirectUri, $pedido->codeChallenge, $agora));
        $this->em->flush();

        return $codigo['valor'];
    }

    /** @param list<string> $escopos */
    private function consentimento(User $pessoa, Tenant $tenant, ClienteDoConector $cliente, array $escopos, \DateTimeImmutable $agora): ConsentimentoDoConector
    {
        $ativo = $this->consentimentos->buscarAtivo($pessoa, $tenant, $cliente);
        if ($ativo === null) {
            return new ConsentimentoDoConector($pessoa, $tenant, $cliente, $escopos, $agora);
        }

        $ativo->trocarEscopos($escopos);

        return $ativo;
    }

    /** @return list<string> */
    private static function escoposConcedidos(PedidoDeAutorizacao $pedido, bool $marcouEscrita): array
    {
        $escopos = [ServidorDeAutorizacao::ESCOPO_LEITURA];
        if ($pedido->pediuEscrita() && $marcouEscrita) {
            $escopos[] = ServidorDeAutorizacao::ESCOPO_ESCRITA;
        }
        if ($pedido->pediuRenovacao()) {
            $escopos[] = ServidorDeAutorizacao::OFFLINE_ACCESS;
        }

        return $escopos;
    }
}
