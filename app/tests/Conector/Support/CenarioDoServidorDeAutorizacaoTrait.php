<?php

declare(strict_types=1);

namespace App\Tests\Conector\Support;

use App\Conector\DTO\PedidoDeTokenInput;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\CodigoDoConector;
use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Service\ImpressaoDaSenha;
use App\Conector\Service\SegredoOpaco;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Symfony\Component\Clock\Clock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cenário do `/oauth/token` gravado direto no banco, sem depender da tela de autorização (bloco A do PR 3): pessoa com
 * senha de verdade e termos aceitos, escritório liberado e ligado, vínculo aprovado, app de I.A. registrado,
 * consentimento, concessão (com a impressão da senha de agora) e um código de autorização com PKCE S256. O relógio é o
 * `Clock` global do Symfony, que o `ClockSensitiveTrait::mockTime()` troca.
 */
trait CenarioDoServidorDeAutorizacaoTrait
{
    use CenarioDoConectorTrait;

    /** O par do exemplo da RFC 7636 (apêndice B). */
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    private const SENHA = 'Senha@Conector123';

    private function agora(): \DateTimeImmutable
    {
        return Clock::get()->now();
    }

    /** Pessoa com senha de verdade (hash do container) e termos aceitos, ligada ao escritório. */
    private function pessoaComSenha(Tenant $escritorio): User
    {
        $pessoa = $this->pessoa();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $pessoa->setPassword($hasher->hashPassword($pessoa, self::SENHA));
        $this->em()->flush();
        $this->vinculo($pessoa, $escritorio);

        return $pessoa;
    }

    private function liberar(Tenant $escritorio, User $por): EscritorioDoConector
    {
        $liberacao = new EscritorioDoConector($escritorio);
        $liberacao->ligar($por, $this->agora());
        $this->em()->persist($liberacao);
        $this->em()->flush();

        return $liberacao;
    }

    private function appDeIa(string $host = 'claude.ai'): ClienteDoConector
    {
        $cliente = new ClienteDoConector('https://' . $host . '/oauth/' . uniqid() . '/client.json', TipoDeClienteDoConector::Cimd, 'App', [self::REDIRECT], $this->agora());
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }

    /**
     * A pessoa autoriza o app no escritório agora: consentimento, concessão e o código (devolvido em texto).
     *
     * @param list<string> $escopos
     *
     * @return array{pessoa: User, escritorio: Tenant, cliente: ClienteDoConector, liberacao: EscritorioDoConector, consentimento: ConsentimentoDoConector, concessao: ConcessaoDoConector, codigo: string}
     */
    private function conexaoAutorizada(array $escopos = ['mcp:leitura', 'offline_access']): array
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoaComSenha($escritorio);
        $liberacao = $this->liberar($escritorio, $pessoa);
        $cliente = $this->appDeIa();
        $consentimento = new ConsentimentoDoConector($pessoa, $escritorio, $cliente, $escopos, $this->agora());
        $this->em()->persist($consentimento);
        [$concessao, $codigo] = $this->autorizarDeNovo($consentimento);

        return compact('pessoa', 'escritorio', 'cliente', 'liberacao', 'consentimento', 'concessao') + ['codigo' => $codigo];
    }

    /**
     * Uma autorização nova no mesmo consentimento: concessão com a impressão da senha de agora e um código novo.
     *
     * @return array{ConcessaoDoConector, string}
     */
    private function autorizarDeNovo(ConsentimentoDoConector $consentimento): array
    {
        $impressao = new ImpressaoDaSenha((string) static::getContainer()->getParameter('kernel.secret'));
        $concessao = new ConcessaoDoConector($consentimento, $consentimento->getEscopos(), ServidorDeAutorizacao::RESOURCE, $impressao->de($consentimento->getUser()), $this->agora());
        $segredo = (new SegredoOpaco())->gerar();
        $this->em()->persist($concessao);
        $this->em()->persist(new CodigoDoConector($segredo['hash'], $concessao, self::REDIRECT, self::CHALLENGE, $this->agora()));
        $this->em()->flush();

        return [$concessao, $segredo['valor']];
    }

    /** @param array<string, string|null> $troca campos do pedido a trocar (nome do campo do DTO) */
    private function pedidoDoCodigo(string $codigo, ClienteDoConector $cliente, array $troca = []): PedidoDeTokenInput
    {
        $campos = $troca + [
            'clientId' => $cliente->getIdentificador(),
            'redirectUri' => self::REDIRECT,
            'codeVerifier' => self::VERIFIER,
            'resource' => ServidorDeAutorizacao::RESOURCE,
        ];

        return new PedidoDeTokenInput(PedidoDeTokenInput::GRANT_CODIGO, $campos['clientId'], $codigo, $campos['redirectUri'], $campos['codeVerifier'], null, $campos['resource']);
    }

    private function pedidoDeRenovacao(string $renovacao, ClienteDoConector|string $cliente, ?string $resource = null): PedidoDeTokenInput
    {
        $clientId = $cliente instanceof ClienteDoConector ? $cliente->getIdentificador() : $cliente;

        return new PedidoDeTokenInput(PedidoDeTokenInput::GRANT_RENOVACAO, $clientId, null, null, null, $renovacao, $resource);
    }
}
