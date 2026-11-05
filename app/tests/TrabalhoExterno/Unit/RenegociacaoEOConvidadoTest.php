<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\TrabalhoExterno\Entity\ConviteDemanda;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\MensagemDemanda;
use App\TrabalhoExterno\Entity\PropostaDemanda;
use App\TrabalhoExterno\Enum\AcaoDemanda;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Achado 2.09 (2ª rodada da auditoria): a demanda reaberta com o convite ainda vigente volta a negociar com o
 * cliente. O registro da nova proposta (com o valor) e os motivos de reabrir, de pedir e responder informações,
 * de pedir ajustes e de recusar/aceitar a proposta são da Central com o cliente: o Convidado não os lê, mesmo
 * gravados depois do aceite do convite. O Solicitante e a Central continuam lendo.
 */
#[CoversClass(PoliticaDaDemanda::class)]
#[CoversClass(DemandaExterna::class)]
final class RenegociacaoEOConvidadoTest extends TestCase
{
    #[TestDox('reaberta e renegociada com o convite vigente: o Convidado não lê a proposta nova nem os motivos; o Solicitante lê')]
    public function testRenegociacaoNaoChegaAoConvidado(): void
    {
        $politica = new PoliticaDaDemanda();
        $d = Apoio::demanda(Situacao::Execucao);
        $convidado = Apoio::usuario(77);
        $convite = new ConviteDemanda($d, 'Convidado', (string) $convidado->getEmail(), null, new \DateTimeImmutable('+30 days'), str_repeat('a', 64), ['chat' => true], Apoio::autor());
        $d->convidar($convite, Apoio::autor());
        $convite->aceitar($convidado, new \DateTimeImmutable('today'));
        $comoConvidado = new Contexto($convidado, null, Perfil::Convidado, new \App\TrabalhoExterno\Entity\ConfiguracaoCentral(), $convite);
        $antes = count($d->getMensagens());

        $m = Apoio::autor(Perfil::Master);
        $s = Apoio::autor(Perfil::Solicitante);
        $d->aplicar(AcaoDemanda::EnviarParaValidacao, $m, null);
        $d->aplicar(AcaoDemanda::Concluir, $m, null);
        $d->aplicar(AcaoDemanda::Reabrir, $m, 'SEGREDO-REABRIR');
        $d->aplicar(AcaoDemanda::SolicitarInformacoes, $m, 'SEGREDO-INFO');
        $d->aplicar(AcaoDemanda::ResponderInformacoes, $s, 'SEGREDO-RESPOSTA');
        $d->enviarProposta(new PropostaDemanda($d, 2, '777.00', null, null, new \DateTimeImmutable(), null, null, null, $m), $m);
        $d->aplicar(AcaoDemanda::SolicitarAjustes, $s, 'SEGREDO-AJUSTE');
        $d->enviarProposta(new PropostaDemanda($d, 3, '555.00', null, null, new \DateTimeImmutable(), null, null, null, $m), $m);
        $d->aplicar(AcaoDemanda::AceitarProposta, $s, null);
        self::assertSame(Situacao::Aceita, $d->getSituacao());

        /** @var list<MensagemDemanda> $novas */
        $novas = array_slice($d->getMensagens(), $antes);
        $lidas = [];
        foreach ($novas as $mensagem) {
            if ($politica->mensagemVisivel($comoConvidado, $d, $mensagem)) {
                $lidas[] = $mensagem->getTexto();
            }
            self::assertTrue($politica->mensagemVisivel(Apoio::contexto(Perfil::Solicitante), $d, $mensagem), 'o Solicitante lê: ' . $mensagem->getTexto());
            self::assertTrue($politica->mensagemVisivel(Apoio::contexto(Perfil::Master), $d, $mensagem), 'a Central lê: ' . $mensagem->getTexto());
        }

        $tudo = implode(' | ', $lidas);
        foreach (['777', '555', 'Proposta v', 'SEGREDO-REABRIR', 'SEGREDO-INFO', 'SEGREDO-RESPOSTA', 'SEGREDO-AJUSTE', 'Proposta aceita'] as $reservado) {
            self::assertStringNotContainsString($reservado, $tudo, "o Convidado leu \"$reservado\"");
        }
        // O que é do trabalho dele continua: o envio para validação e a conclusão.
        self::assertStringContainsString('Execução enviada para validação', $tudo);
        self::assertStringContainsString('Serviço concluído', $tudo);
    }
}
