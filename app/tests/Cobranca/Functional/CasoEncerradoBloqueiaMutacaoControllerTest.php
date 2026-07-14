<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Decisão de negócio (SPEC §17): um Caso ENCERRADO representa o fim do ciclo daquele Caso e NÃO pode
 * voltar a ter atividade ou saldo por mutação indireta — nova inadimplência deve abrir um novo Caso.
 * A UI já esconde os botões num caso encerrado; estes testes provam que a proteção também vive no
 * SERVIDOR: um POST direto às rotas (com CSRF válido) NÃO muta o caso encerrado.
 *
 * Cobre mutações que antes só eram barradas na interface: registrar tentativa de cobrança e reconhecer
 * valor atualizado. O CSRF é por nome-de-form/sessão, então o token é colhido de um caso ATIVO do mesmo
 * tenant (isola: prova que o bloqueio é de domínio, não de CSRF).
 */
final class CasoEncerradoBloqueiaMutacaoControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Registrar tentativa num caso ENCERRADO: bloqueado no servidor, sem novo evento')]
    public function testTentativaEmCasoEncerradoNaoMuta(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $casoAtivo] = $this->semearGrafo($tenant);
        [, $casoEncerrado] = $this->semearGrafo($tenant, ['status' => StatusCaso::Encerrado]);
        $encerradoId = (int) $casoEncerrado->getId();

        // Token colhido do form no caso ativo (mesmo nome de form → mesmo token de sessão).
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $casoAtivo->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'registrar_tentativa_cobranca');

        $client->request('POST', '/cobrancas/casos/' . $encerradoId . '/tentativas', [
            'registrar_tentativa_cobranca' => ['observacao' => 'Boleto reenviado', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $casoEncerrado->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $eventos = static::getContainer()->get(EventoHistoricoRepository::class)
            ->doCaso($em->find(\App\Cobranca\Entity\CasoCobranca::class, $encerradoId));
        self::assertCount(0, $eventos, 'caso encerrado não pode receber novo evento de tentativa');
    }

    #[TestDox('Reconhecer valor de obrigação de caso ENCERRADO: bloqueado no servidor, encargos intactos')]
    public function testReconhecerValorEmCasoEncerradoNaoMuta(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $casoAtivo] = $this->semearGrafo($tenant);
        [, $casoEncerrado] = $this->semearGrafo($tenant, ['status' => StatusCaso::Encerrado]);

        // Obrigação vinculada ao caso encerrado (semeada direto — o guard é sobre o estado do caso).
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $casoEncerrado,
            'valorOriginal' => 10000,
            'encargosReconhecidos' => 0,
        ]);
        $obrigacaoId = (int) $obrigacao->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $casoAtivo->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'reconhecer_valor_atualizado');

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/reconhecer-valor', [
            'reconhecer_valor_atualizado' => ['encargosReconhecidos' => '15,00', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $casoEncerrado->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $recarregada = $em->find(Obrigacao::class, $obrigacaoId);
        self::assertSame(0, $recarregada->getEncargosReconhecidos(), 'caso encerrado não pode reconhecer novos encargos');
    }
}
