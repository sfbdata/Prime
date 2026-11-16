<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\CodigoDoConector;
use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Entity\PropostaDoConector;
use App\Conector\Entity\TokenDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/** Semeia, pelo ORM, uma linha em cada tabela do conector para um escritório. */
trait SemeiaConector
{
    private EntityManagerInterface $em;

    private function tenantNovo(bool $ativo = true, ?string $excluidoEm = null): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório ' . uniqid());
        $tenant->setIsActive($ativo);
        if ($excluidoEm !== null) {
            $tenant->setExcluidoEm(new \DateTimeImmutable($excluidoEm));
        }
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function clienteGlobal(): ClienteDoConector
    {
        $cliente = new ClienteDoConector(
            'https://chatgpt.com/oauth/' . uniqid() . '/client.json',
            TipoDeClienteDoConector::Cimd,
            'ChatGPT',
            ['https://chatgpt.com/connector_platform_oauth_redirect'],
            new \DateTimeImmutable(),
        );
        $this->em->persist($cliente);
        $this->em->flush();

        return $cliente;
    }

    /**
     * Uma linha em cada uma das 7 tabelas com escritório; o token tem os dois pares (o segundo gerado pela primeira
     * renovação) para cobrir o `gerado_por_id`.
     *
     * @return array{consentimento: ConsentimentoDoConector, concessao: ConcessaoDoConector, proposta: PropostaDoConector, acesso: TokenDoConector, renovacao: TokenDoConector, acessoNovo: TokenDoConector, renovacaoNova: TokenDoConector, codigo: CodigoDoConector}
     */
    private function semearConector(Tenant $tenant, User $user, ClienteDoConector $cliente): array
    {
        $agora = new \DateTimeImmutable();
        $sufixo = uniqid('', true);
        $consentimento = new ConsentimentoDoConector($user, $tenant, $cliente, ['mcp:leitura', 'offline_access'], $agora);
        $concessao = new ConcessaoDoConector($consentimento, ['mcp:leitura', 'offline_access'], 'https://bluejus.com.br/mcp', hash('sha256', 'senha'), $agora);
        $codigo = new CodigoDoConector(hash('sha256', 'codigo' . $sufixo), $concessao, 'https://chatgpt.com/cb', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $agora);
        $acesso = TokenDoConector::deAcesso(hash('sha256', 'acesso' . $sufixo), $concessao, $agora);
        $renovacao = TokenDoConector::deRenovacao(hash('sha256', 'renovacao' . $sufixo), $concessao, $agora);
        $acessoNovo = TokenDoConector::deAcesso(hash('sha256', 'acesso2' . $sufixo), $concessao, $agora, $renovacao);
        $renovacaoNova = TokenDoConector::deRenovacao(hash('sha256', 'renovacao2' . $sufixo), $concessao, $agora, $renovacao);
        $proposta = new PropostaDoConector(hash('sha256', 'proposta' . $sufixo), $consentimento, 'atualizar_cliente', 'cadastrar', ['nome' => 'Fulano'], hash('sha256', 'dados'), 'Cadastrar Fulano', $agora);
        $escritorio = new EscritorioDoConector($tenant);

        foreach ([$consentimento, $concessao, $codigo, $acesso, $renovacao, $acessoNovo, $renovacaoNova, $proposta, $escritorio] as $entidade) {
            $this->em->persist($entidade);
        }
        $this->em->flush();

        return ['consentimento' => $consentimento, 'concessao' => $concessao, 'proposta' => $proposta, 'acesso' => $acesso, 'renovacao' => $renovacao, 'acessoNovo' => $acessoNovo, 'renovacaoNova' => $renovacaoNova, 'codigo' => $codigo];
    }
}
